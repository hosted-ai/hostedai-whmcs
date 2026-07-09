<?php

use WHMCS\Database\Capsule;
use WHMCS\Module\Server\HosteDai\Helper;

// CLI-only. This script runs prepaid billing and suspension — it must never be
// triggerable over HTTP. Reject any non-CLI (web) invocation before bootstrapping.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

$whmcspath = "";
if (file_exists(dirname(__FILE__) . "/config.php"))
    require_once dirname(__FILE__) . "/config.php";

if (!empty($whmcspath)) {
    require_once $whmcspath . "/init.php";
} else {
    require(__DIR__ . "/../init.php");
}

$helper = new Helper();

/**
 * Build a Helper bound to the hosted·ai server the given service is provisioned on.
 * Each WHMCS service may live on a different hosted·ai cluster; a parameter-less
 * `new Helper()` falls back to the first enabled hostedai server and would query the
 * wrong cluster (team-not-found → zero billing). Returns null if no server is found.
 */
function hostedaiHelperForService($sid)
{
    $service = Capsule::table('tblhosting')->where('id', $sid)->first();
    if (!$service) {
        return null;
    }
    $server = Capsule::table('tblservers')->where('id', $service->server)->first();
    if (!$server || empty($server->hostname)) {
        return null;
    }
    return new Helper([
        'serverhostname' => $server->hostname,
        'serverpassword' => decrypt($server->password),
    ]);
}

// Process lock — prevents two cron instances from running concurrently and
// double-billing the same hour when the scheduler fires twice in quick succession.
// The lock lives in a private, per-install directory (0700) rather than a predictable
// world-writable /tmp path, so another local user cannot pre-create/squat the file and
// silently stall billing. A failure to OPEN the lock is treated as an error (loud,
// non-zero exit) — only a genuinely held lock is a quiet "already running" exit.
$lockDir = sys_get_temp_dir() . '/hostedai-' . substr(md5(__DIR__), 0, 12);
if (!is_dir($lockDir)) {
    @mkdir($lockDir, 0700, true);
}
$lockFile = $lockDir . '/hourly_cron.lock';
$lockFd   = fopen($lockFile, 'c');
if (!$lockFd) {
    logActivity('HostedAI Hourly Cron: ERROR — cannot open lock file ' . $lockFile . '; aborting to avoid unguarded billing.');
    exit(1);
}
if (!flock($lockFd, LOCK_EX | LOCK_NB)) {
    logActivity('HostedAI Hourly Cron: another instance is running, exiting.');
    fclose($lockFd);
    exit(0);
}

// 55 min = hourly cron interval (60 min) minus a 5-min overlap buffer.
// Keeps billing idempotent when the cron scheduler fires slightly early or two
// processes overlap at the start of an hour.
const CRON_OVERLAP_GUARD_MINUTES = 55;

try {
    logActivity("HostedAI Hourly Cron started on " . date('Y-m-d H:i:s'));

    // Ensure wallet columns exist before querying the table (idempotent ALTER TABLE guards).
    $helper->ensureWalletColumns();

    $teams = Capsule::table('mod_hostdaiteam_details')
        ->where('billing_mode', 'prepaid')
        ->get();

    foreach ($teams as $team) {

        // Bind the API helper to the cluster this service lives on. Usage billing
        // needs it, but the balance check / auto top-up / suspension are WHMCS-side
        // (localAPI) and must still run even when the server is missing — otherwise a
        // service whose server was deleted/disabled would never suspend or top up.
        $teamHelper = hostedaiHelperForService($team->sid);

        // Billing phase — guarded by CRON_OVERLAP_GUARD_MINUTES to prevent double-billing
        // when two cron processes overlap. API errors also skip the balance check since
        // we can't know the post-billing balance in that case.
        $skipBalanceCheck = false;
        $shouldBill = ($teamHelper !== null);

        if (!$teamHelper) {
            logActivity("Hourly cron: no server for service {$team->sid} (TeamID {$team->teamid}) — skipping usage billing, still running balance check");
        }

        // Don't accrue new usage invoices on a service already suspended for zero
        // balance: it isn't running, and invoices it can't pay would linger as overdue
        // debt in a mode that is "no debt by design". The balance check below still runs
        // (top-up + the InvoicePaid hook handle reactivation).
        if (($team->suspended_reason ?? '') === 'balance_zero') {
            $shouldBill = false;
            logActivity("Hourly cron: TeamID {$team->teamid} suspended (balance_zero) — skipping usage billing");
        }

        if ($shouldBill && !empty($team->last_billed_at)) {
            $secondsSince = time() - strtotime($team->last_billed_at);
            if ($secondsSince < CRON_OVERLAP_GUARD_MINUTES * 60) {
                logActivity("Hourly cron: Skipping billing for TeamID {$team->teamid} — last billed {$secondsSince}s ago");
                $shouldBill = false;
            }
        }

        if ($shouldBill) {
            logActivity("Hourly cron: Processing billing for TeamID {$team->teamid} (UID {$team->uid})");

            // One shared UTC window so every category query covers the identical hour.
            $winEnd    = gmdate('Y-m-d\TH:i');
            $winStart  = gmdate('Y-m-d\TH:i', time() - 3600);
            $hourLabel = gmdate('Y-m-d H:00') . ' UTC';

            $response = $teamHelper->generateHourlyBill($team->teamid, $winStart, $winEnd);

            if ($response['httpcode'] !== 200) {
                logActivity("Hourly cron: API error for TeamID {$team->teamid}, HTTP {$response['httpcode']}");
                $skipBalanceCheck = true;
            } else {
                $responseData = $response['result'];
                $currencyCode = $responseData->currency_code ?? null;

                // Build one invoice line per non-zero cost category. Prepaid used to bill
                // ONLY compute (per-instance) and ignore shared storage / GPUaaS pool /
                // team metrics — those accrued on the platform but were never charged.
                $lineItems = [];

                // 1) Compute (instances). Do NOT use current_month_total_cost — for an
                //    hourly window it is 0/cumulative-agnostic; sum per-instance total_cost.
                //    VM-nature instances omit total_cost, so fall back to summing the
                //    itemized interval resources (excluding the "total_cost" key).
                $compute = 0.0;
                foreach ($responseData->billing_by_workspace ?? [] as $workspace) {
                    foreach ($workspace->instances ?? [] as $instanceData) {
                        $ic = floatval($instanceData->total_cost ?? 0);
                        if ($ic <= 0 && isset($instanceData->intervals)) {
                            foreach ($instanceData->intervals as $iv) {
                                foreach ((array)($iv->Resources ?? []) as $rk => $ru) {
                                    if ($rk === 'total_cost') { continue; }
                                    $ic += floatval($ru->cost ?? 0);
                                }
                            }
                        }
                        $compute += $ic;
                    }
                }
                if ($compute > 0) {
                    $lineItems[] = ['description' => "Compute (instances) — {$hourLabel} — Team {$team->teamid}", 'amount' => $compute];
                }

                // 2) Shared storage (best-effort; separate endpoint).
                $ss = $teamHelper->getTeamSharedStorageBilling($team->teamid, 'all', $winStart, $winEnd, 'hourly');
                if (($ss['httpcode'] ?? 0) === 200) {
                    $storage = 0.0;
                    foreach ((array)($ss['result']->details ?? []) as $vol) {
                        foreach ((array)($vol->intervals ?? []) as $iv) {
                            $storage += floatval($iv->cost ?? 0);
                        }
                    }
                    if ($storage > 0) {
                        $lineItems[] = ['description' => "Shared storage — {$hourLabel} — Team {$team->teamid}", 'amount' => $storage];
                    }
                }

                // 3) GPUaaS pool (best-effort).
                $gp = $teamHelper->getTeamGpuaasPoolBilling($team->teamid, 'all', $winStart, $winEnd, 'hourly');
                if (($gp['httpcode'] ?? 0) === 200) {
                    $pool = 0.0;
                    foreach ((array)($gp['result']->details ?? []) as $pd) {
                        foreach ((array)($pd->intervals ?? []) as $iv) {
                            $pool += floatval($iv->interval_cost ?? 0);
                        }
                    }
                    if ($pool > 0) {
                        $lineItems[] = ['description' => "GPUaaS pool — {$hourLabel} — Team {$team->teamid}", 'amount' => $pool];
                    }
                }

                // 4) Team-level resource usage (team_metrics; detailed endpoint only).
                $dt = $teamHelper->generateDetailedTeamBill($team->teamid, $winStart, $winEnd, 'hourly');
                if (($dt['httpcode'] ?? 0) === 200 && !empty($dt['result']->team_metrics)) {
                    $tmArr   = (array)$dt['result']->team_metrics;
                    $tmFirst = reset($tmArr);
                    $tmCost  = floatval($tmFirst->total_cost ?? 0);
                    if ($tmCost > 0) {
                        $lineItems[] = ['description' => "Team resource usage — {$hourLabel} — Team {$team->teamid}", 'amount' => $tmCost];
                    }
                }

                $totalCost = 0.0;
                foreach ($lineItems as $li) { $totalCost += $li['amount']; }

                // Stamp last_billed_at (prevents redundant API calls for zero-usage teams)
                Capsule::table('mod_hostdaiteam_details')
                    ->where('sid', $team->sid)
                    ->update(['last_billed_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]);

                if ($totalCost > 0) {
                    $cats = implode(', ', array_map(function ($li) { return $li['description']; }, $lineItems));
                    logActivity("Hourly cron: TeamID {$team->teamid} — deducting \${$totalCost} across " . count($lineItems) . " line item(s)");
                    // WHMCS invoices follow the client's currency; warn if the API bills
                    // in a different one (the amount would be recorded mis-denominated).
                    $helper->warnOnCurrencyMismatch($team->uid, $currencyCode);
                    $summary      = "Hourly usage — {$hourLabel} — Team {$team->teamid}";
                    $deductResult = $helper->createAndPayHourlyInvoice($team->uid, $totalCost, $summary, $lineItems);

                    if ($deductResult['result'] !== 'success') {
                        logActivity("Hourly cron: Deduction failed for TeamID {$team->teamid}: " . json_encode($deductResult));
                    } else {
                        logActivity("Hourly cron: Deducted \${$totalCost} from UID {$team->uid}, invoice #{$deductResult['invoiceid']}");
                    }
                } else {
                    logActivity("Hourly cron: TeamID {$team->teamid} — zero usage this hour, no invoice");
                }
            }
        }

        // Balance check — runs every iteration unless billing returned an API error.
        // Runs even when billing was skipped (55-min guard), so suspension / warnings
        // are applied promptly regardless of how often the cron fires.
        if (!$skipBalanceCheck) {
            $balance = $helper->getClientCreditBalance($team->uid);
            if ($balance !== null) {
                $product    = Capsule::table('tblproducts')->where('id', $team->pid)->first();
                $minBalance = ($product && !empty($product->configoption11))
                    ? floatval($product->configoption11)
                    : 1.00;
                $currentReason = $team->suspended_reason ?? '';

                // Auto top-up: proactively raise an Add Funds invoice when the wallet
                // dips below the configured threshold (set above Min Wallet Balance so
                // it fires before suspension). Dedup per client — skip if the client
                // already has an open Add Funds invoice (credit is shared per client).
                $topupThreshold = ($product && !empty($product->configoption14)) ? floatval($product->configoption14) : 0;
                $topupAmount    = ($product && !empty($product->configoption15)) ? floatval($product->configoption15) : 0;
                if ($topupThreshold > 0 && $topupAmount > 0 && $balance < $topupThreshold) {
                    if (!$helper->hasOpenAddFundsInvoice($team->uid)) {
                        $topup = $helper->createAddFundsInvoice($team->uid, $topupAmount);
                        if (isset($topup['result']) && $topup['result'] === 'success') {
                            logActivity("Hourly cron: auto top-up invoice #{$topup['invoiceid']} (\${$topupAmount}) raised for UID {$team->uid} — balance \${$balance} < threshold \${$topupThreshold}");
                        }
                    } else {
                        logActivity("Hourly cron: auto top-up skipped for UID {$team->uid} — open Add Funds invoice already exists");
                    }
                }

                if ($balance <= $minBalance && $currentReason !== 'balance_zero') {
                    $helper->suspendTerminate_service($team->sid, $team->pid, 'ModuleSuspend');
                    Capsule::table('mod_hostdaiteam_details')
                        ->where('sid', $team->sid)
                        ->update(['suspended_reason' => 'balance_zero', 'updated_at' => date('Y-m-d H:i:s')]);
                    logActivity("Hourly cron: Suspended service {$team->sid} (TeamID {$team->teamid}) — balance \${$balance} ≤ threshold \${$minBalance}");
                } elseif ($balance > $minBalance && $balance <= $minBalance * 2) {
                    // Low balance warning — send at most once per 24 hours
                    $lastNotified = $team->low_balance_notified_at ?? null;
                    $hoursSince   = $lastNotified ? (time() - strtotime($lastNotified)) / 3600 : 999;
                    if ($hoursSince >= 24) {
                        $helper->sendLowBalanceWarning($team->uid, $team->sid, $balance, $minBalance);
                        Capsule::table('mod_hostdaiteam_details')
                            ->where('sid', $team->sid)
                            ->update(['low_balance_notified_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]);
                    } else {
                        logActivity("Hourly cron: Low balance for service {$team->sid} — warning already sent {$hoursSince}h ago, skipping");
                    }
                }
            }
        }
    }

    logActivity("HostedAI Hourly Cron completed on " . date('Y-m-d H:i:s'));

} catch (\Exception $e) {
    logActivity("Exception in HostedAI Hourly Cron: " . $e->getMessage());
} finally {
    if (isset($lockFd) && $lockFd) {
        flock($lockFd, LOCK_UN);
        fclose($lockFd);
    }
}
