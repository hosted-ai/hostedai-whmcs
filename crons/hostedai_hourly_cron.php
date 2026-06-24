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

try {
    logActivity("HostedAI Hourly Cron started on " . date('Y-m-d H:i:s'));

    // Ensure Phase 4 columns exist before querying the table
    $helper->ensureWalletColumns();

    $teams = Capsule::table('mod_hostdaiteam_details')
        ->where('billing_mode', 'prepaid')
        ->get();

    foreach ($teams as $team) {

        // Billing phase — guarded by 55-min check (prevents double-billing on cron overlap).
        // API errors also skip this team entirely since we can't know the post-billing balance.
        $skipBalanceCheck = false;
        $shouldBill = true;

        if (!empty($team->last_billed_at)) {
            $secondsSince = time() - strtotime($team->last_billed_at);
            if ($secondsSince < 55 * 60) {
                logActivity("Hourly cron: Skipping billing for TeamID {$team->teamid} — last billed {$secondsSince}s ago");
                $shouldBill = false;
            }
        }

        if ($shouldBill) {
            logActivity("Hourly cron: Processing billing for TeamID {$team->teamid} (UID {$team->uid})");

            $response = $helper->generateHourlyBill($team->teamid);

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
}
