<?php

use WHMCS\Database\Capsule;
use WHMCS\Module\Server\HosteDai\Helper;

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
$lockFile = sys_get_temp_dir() . '/hostedai_hourly_cron.lock';
$lockFd   = fopen($lockFile, 'c');
if (!$lockFd || !flock($lockFd, LOCK_EX | LOCK_NB)) {
    logActivity('HostedAI Hourly Cron: already running, exiting.');
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

        if ($shouldBill && !empty($team->last_billed_at)) {
            $secondsSince = time() - strtotime($team->last_billed_at);
            if ($secondsSince < CRON_OVERLAP_GUARD_MINUTES * 60) {
                logActivity("Hourly cron: Skipping billing for TeamID {$team->teamid} — last billed {$secondsSince}s ago");
                $shouldBill = false;
            }
        }

        if ($shouldBill) {
            logActivity("Hourly cron: Processing billing for TeamID {$team->teamid} (UID {$team->uid})");

            $response = $teamHelper->generateHourlyBill($team->teamid);

            if ($response['httpcode'] !== 200) {
                logActivity("Hourly cron: API error for TeamID {$team->teamid}, HTTP {$response['httpcode']}");
                $skipBalanceCheck = true;
            } else {
                $responseData = $response['result'];

                // Extract total hourly cost — prefer current_month_total_cost, fall back to
                // summing workspace instance costs
                $totalCost = 0.0;
                if (isset($responseData->current_month_total_cost) && $responseData->current_month_total_cost > 0) {
                    $totalCost = floatval($responseData->current_month_total_cost);
                } elseif (isset($responseData->billing_by_workspace)) {
                    foreach ($responseData->billing_by_workspace as $workspace) {
                        foreach ($workspace->instances ?? [] as $instanceData) {
                            $totalCost += floatval($instanceData->total_cost ?? 0);
                        }
                    }
                }

                // Stamp last_billed_at (prevents redundant API calls for zero-usage teams)
                Capsule::table('mod_hostdaiteam_details')
                    ->where('sid', $team->sid)
                    ->update(['last_billed_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]);

                if ($totalCost > 0) {
                    logActivity("Hourly cron: TeamID {$team->teamid} — deducting \${$totalCost}");
                    $description  = "Hourly usage — " . date('Y-m-d H:00') . " — Team " . $team->teamid;
                    $deductResult = $helper->createAndPayHourlyInvoice($team->uid, $totalCost, $description);

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
