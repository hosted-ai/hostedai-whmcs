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

    $teams = Capsule::table('mod_hostdaiteam_details')
        ->where('billing_mode', 'prepaid')
        ->get();

    foreach ($teams as $team) {

        // Guard: skip if billed within last 55 minutes (prevents double-billing on cron overlap)
        if (!empty($team->last_billed_at)) {
            $secondsSince = time() - strtotime($team->last_billed_at);
            if ($secondsSince < 55 * 60) {
                logActivity("Hourly cron: Skipping TeamID {$team->teamid} — last billed {$team->last_billed_at} ({$secondsSince}s ago)");
                continue;
            }
        }

        logActivity("Hourly cron: Processing TeamID {$team->teamid} (UID {$team->uid})");

        // Query API for last hour's usage
        $response = $helper->generateHourlyBill($team->teamid);

        if ($response['httpcode'] !== 200) {
            logActivity("Hourly cron: API error for TeamID {$team->teamid}, HTTP {$response['httpcode']}");
            continue;
        }

        $responseData = $response['result'];

        // Extract total hourly cost — prefer current_month_total_cost (= period total from API),
        // fall back to summing workspace instance costs
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

        // Always stamp last_billed_at (prevents redundant API calls for zero-usage teams)
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

        // Check balance and suspend if at or below threshold
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
            }
        }
    }

    logActivity("HostedAI Hourly Cron completed on " . date('Y-m-d H:i:s'));

} catch (\Exception $e) {
    logActivity("Exception in HostedAI Hourly Cron: " . $e->getMessage());
}
