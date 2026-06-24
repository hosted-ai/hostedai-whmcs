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
    logActivity("HostedAI Cron started on " . date('Y-m-d H:i:s'));

    // Debug mode - set to true to run on any day for testing
    $debug_mode = false; // PRODUCTION: Always false for security
    
    // TESTING: Uncomment below to enable daily execution for testing
    // $debug_mode = true; // TESTING ONLY - allows cron to run on any day
    
    if (date('d') == '01' || $debug_mode) {
        if ($debug_mode) {
            logActivity("DEBUG MODE: Running invoice generation on day " . date('d') . " instead of 1st");
        }

        // Generate bills and create invoices — monthly mode only
        $teams = Capsule::table('mod_hostdaiteam_details')
            ->where(function ($q) {
                $q->where('billing_mode', 'monthly')->orWhereNull('billing_mode');
            })->get();

        foreach ($teams as $team) {
            // Production: Basic processing log (debug info removed for security)
            logActivity("Processing billing for TeamID {$team->teamid}");
            
            $response = $helper->generateBill($team->teamid);
            logActivity("Billing response for TeamID {$team->teamid}: " . json_encode($response));

            // Always initialize invoice items regardless of main billing response
            $invoiceItems = [];
            $itemCount = 1;
            $totalWithoutTax = 0;
            $currencyCode = 'USD';
            $currencySymbol = '$';

            // Process main billing data if available
            if ($response['httpcode'] === 200) {
                $responseData = $response['result'];

                // Log enhanced billing information
                $pricingPolicy = $responseData->pricing_policy ?? 'Unknown';
                $resourcePolicy = $responseData->resource_policy ?? 'Unknown';
                $currencyCode = $responseData->currency_code ?? 'USD';
                $currencySymbol = $responseData->currency_symbol ?? '$';
                $currentMonthCost = $responseData->current_month_total_cost ?? 0;
                
                logActivity("Enhanced billing info for TeamID {$team->teamid} - Pricing Policy: {$pricingPolicy}, Resource Policy: {$resourcePolicy}, Currency: {$currencyCode}, Current Month Cost: {$currentMonthCost}");

                // Add monthly base cost if available
                if (isset($responseData->monthly_cost) && $responseData->monthly_cost > 0) {
                    $monthlyCost = number_format($responseData->monthly_cost, 2);
                    $invoiceItems["itemdescription{$itemCount}"] = "Monthly Base Service Fee";
                    $invoiceItems["itemamount{$itemCount}"] = $responseData->monthly_cost; // Use raw float for invoice
                    $invoiceItems["itemtaxed{$itemCount}"] = true;
                    $totalWithoutTax += $responseData->monthly_cost;
                    $itemCount++;
                }

                // Process workspace billing data if available
                if (isset($responseData->billing_by_workspace) && !empty($responseData->billing_by_workspace)) {
                    logActivity("Processing workspace billing data for TeamID {$team->teamid}");

                foreach ($responseData->billing_by_workspace as $workspace) {
                    $workspaceName = $workspace->workspace_name ?? 'Unknown Workspace';
                    if (empty($workspace->instances)) {
                        logActivity("No instances found for workspace: {$workspaceName}");
                        continue;
                    }

                    foreach ($workspace->instances as $instanceId => $instanceData) {
                        $instanceName = $instanceData->instance_name ?? $instanceId;
                        $instanceTotalCost = floatval($instanceData->total_cost ?? 0);

                        // Aggregate costs across all intervals (months)
                        $cpuTotal = 0; $ramTotal = 0; $diskTotal = 0; $gpuTotal = 0;
                        $subscriptionTotal = 0; $tflopsTotal = 0; $vramTotal = 0;

                        if (isset($instanceData->intervals)) {
                            foreach ($instanceData->intervals as $month => $intervalData) {
                                $res = $intervalData->Resources ?? new \stdClass();
                                $cpuTotal += floatval($res->CPU->cost ?? 0);
                                $ramTotal += floatval($res->RAM->cost ?? 0);
                                $diskTotal += floatval($res->{'Ephemeral Storage'}->cost ?? 0);
                                $gpuTotal += floatval($res->GPU->cost ?? 0);
                                $subscriptionTotal += floatval($res->{'Subscription Rate'}->cost ?? 0);
                                $tflopsTotal += floatval($res->TFlops->cost ?? 0);
                                $vramTotal += floatval($res->vRAM->cost ?? 0);
                            }
                        }

                        $cpu = number_format($cpuTotal, 2);
                        $ram = number_format($ramTotal, 2);
                        $disk = number_format($diskTotal, 2);
                        $gpu = number_format($gpuTotal, 2);
                        $subscription = number_format($subscriptionTotal, 2);
                        $tflops = number_format($tflopsTotal, 2);
                        $vram = number_format($vramTotal, 2);

                        $description = <<<DESC
                                        Workspace: {$workspaceName}
                                        Instance: {$instanceName} ({$instanceId})
                                        CPU ………………………………………………………… \$ {$cpu}
                                        RAM ………………………………………………………… \$ {$ram}
                                        Ephemeral Storage ……………………………… \$ {$disk}
                                        GPU ………………………………………………………… \$ {$gpu}
                                        Subscription Rate ……………………………… \$ {$subscription}
                                        TFlops ……………………………………………………… \$ {$tflops}
                                        vRAM ………………………………………………………… \$ {$vram}
                                        DESC;

                        $invoiceItems["itemdescription{$itemCount}"] = $description;
                        $invoiceItems["itemamount{$itemCount}"] = $instanceTotalCost;
                        $invoiceItems["itemtaxed{$itemCount}"] = true;

                        $totalWithoutTax += $instanceTotalCost;
                        $itemCount++;
                    }

                // Add GPUaaS pool billing (if available)
                if (!empty($responseData->gpuaas_billing_by_pool)) {
                    foreach ($responseData->gpuaas_billing_by_pool as $poolId => $poolData) {
                        $poolName = $poolData->pool_name ?? "Pool {$poolId}";
                        $modelType = $poolData->model_type ?? 'N/A';
                        $vendorType = $poolData->vendor_type ?? 'N/A';
                        $intervalsArray = (array)$poolData->intervals;
                        $interval = reset($intervalsArray);

                        $gpuCost = number_format($interval->Cost_Of_GPUConsumed ?? 0, 2);
                        $vramCost = number_format($interval->Cost_Of_vRAMConsumed ?? 0, 2);
                        $tflopsCost = number_format($interval->Cost_Of_TotalTFlopsConsumed ?? 0, 2);
                        $poolHours = number_format($interval->GPU_Pool_Hours ?? 0, 2);
                        $totalCost = number_format($interval->total_cost, 2);

                        $description = <<<DESC
                        GPU Pool: {$poolName} ({$modelType} - {$vendorType})
                        GPU Subscription ........................ \$ {$gpuCost}
                        vRAM Consumption ........................ \$ {$vramCost}
                        TFlops Consumption ...................... \$ {$tflopsCost}
                        Pool Hours .............................. {$poolHours} hrs
                        DESC;

                        $invoiceItems["itemdescription{$itemCount}"] = $description;
                        $invoiceItems["itemamount{$itemCount}"] = $interval->total_cost; // Use raw float for invoice
                        $invoiceItems["itemtaxed{$itemCount}"] = true;

                        $totalWithoutTax += $interval->total_cost;
                        $itemCount++;
                    }
                }

                // Add PCI Device (GPU Card) billing (if available)
                if (!empty($responseData->pci_devices) && isset($responseData->pci_devices->pci_devices)) {
                    foreach ($responseData->pci_devices->pci_devices as $cardId => $cardData) {
                        $intervalsArray = (array)$cardData;
                        $interval = reset($intervalsArray);
                        
                        $totalHoursDecimal = $interval->total_hours ?? 0;
                        $totalHoursFormatted = $helper->formatHoursMinutes($totalHoursDecimal);
                        $totalCost = number_format($interval->total_cost ?? 0, 2);
                        
                        // Get VM usage details
                        $vmUsageDetails = '';
                        if (!empty($interval->vm_usage)) {
                            foreach ($interval->vm_usage as $vmUsage) {
                                $vmId = $vmUsage->VMID ?? 'Unknown';
                                $vmHoursDecimal = $vmUsage->Hours ?? 0;
                                $vmHoursFormatted = $helper->formatHoursMinutes($vmHoursDecimal);
                                $vmCost = number_format($vmUsage->Cost ?? 0, 2);
                                $vmUsageDetails .= "\n                        VM {$vmId}: {$vmHoursFormatted} (\${$vmCost})";
                            }
                        }

                        $description = <<<DESC
                        GPU Card: {$cardId}
                        Total Hours ............................. {$totalHoursFormatted}{$vmUsageDetails}
                        DESC;

                        $invoiceItems["itemdescription{$itemCount}"] = $description;
                        $invoiceItems["itemamount{$itemCount}"] = $interval->total_cost; // Use raw float for invoice
                        $invoiceItems["itemtaxed{$itemCount}"] = true;

                        $totalWithoutTax += $interval->total_cost;
                        $itemCount++;
                    }
                }

                // Add Team Metrics billing (if available)
                if (!empty($responseData->team_metrics)) {
                    $teamMetricsArray = (array)$responseData->team_metrics;
                    $teamMetricsInterval = reset($teamMetricsArray);
                    
                    $teamRAM = number_format($teamMetricsInterval->RAM ?? 0, 2);
                    $teamCPU = number_format($teamMetricsInterval->CPU ?? 0, 2);
                    $teamGPU = number_format($teamMetricsInterval->GPU ?? 0, 2);
                    $teamGRAM = number_format($teamMetricsInterval->GRAM ?? 0, 2);
                    $teamTFlops = number_format($teamMetricsInterval->TFlops ?? 0, 2);
                    $teamTotal = number_format($teamMetricsInterval->total_cost ?? 0, 2);

                    if ($teamTotal > 0) {
                        $description = <<<DESC
                        Team-Level Resource Usage
                        RAM ..................................... \$ {$teamRAM}
                        CPU ..................................... \$ {$teamCPU}
                        GPU ..................................... \$ {$teamGPU}
                        GRAM .................................... \$ {$teamGRAM}
                        TFlops .................................. \$ {$teamTFlops}
                        DESC;

                        $invoiceItems["itemdescription{$itemCount}"] = $description;
                        $invoiceItems["itemamount{$itemCount}"] = $teamMetricsInterval->total_cost; // Use raw float for invoice
                        $invoiceItems["itemtaxed{$itemCount}"] = true;

                        $totalWithoutTax += $teamMetricsInterval->total_cost;
                        $itemCount++;
                    }
                }
                }
                } else {
                    logActivity("No workspace billing data found for TeamID {$team->teamid}");
                }
            } else {
                logActivity("Main billing API failed for TeamID {$team->teamid} - HTTP Code: " . $response['httpcode']);
            }

            // ALWAYS process Shared Storage billing (regardless of main billing status)
            $sharedStorageResponse = $helper->getTeamSharedStorageBilling($team->teamid);
            if ($sharedStorageResponse['httpcode'] === 200 && !empty($sharedStorageResponse['result'])) {
                $sharedStorageData = $sharedStorageResponse['result'];
                logActivity("Shared storage billing for TeamID {$team->teamid}: " . json_encode($sharedStorageData));
                
                if (isset($sharedStorageData->details) && !empty($sharedStorageData->details)) {
                    foreach ($sharedStorageData->details as $volumeName => $volumeData) {
                        $volumeArray = (array)$volumeData;
                        $interval = reset($volumeArray);
                        
                        $cost = number_format($interval->cost ?? 0, 2);
                        $hoursDecimal = $interval->hours ?? 0;
                        $hoursFormatted = $helper->formatHoursMinutes($hoursDecimal);
                        
                        if ($cost > 0) {
                            $description = <<<DESC
                            Shared Storage: {$volumeName}
                            Hours ................................... {$hoursFormatted}
                            Cost .................................... \$ {$cost}
                            DESC;

                            $invoiceItems["itemdescription{$itemCount}"] = $description;
                            $invoiceItems["itemamount{$itemCount}"] = $interval->cost; // Use raw float for invoice
                            $invoiceItems["itemtaxed{$itemCount}"] = true;

                            $totalWithoutTax += $interval->cost;
                            $itemCount++;
                        }
                    }
                }
            } else {
                logActivity("Shared storage billing failed or empty for TeamID {$team->teamid} - HTTP Code: " . ($sharedStorageResponse['httpcode'] ?? 'unknown'));
            }

            // ALWAYS process Enhanced GPUaaS Pool billing with Ephemeral Storage (regardless of main billing status)
            $gpuaasPoolResponse = $helper->getTeamGpuaasPoolBilling($team->teamid);
            if ($gpuaasPoolResponse['httpcode'] === 200 && !empty($gpuaasPoolResponse['result'])) {
                $gpuaasPoolData = $gpuaasPoolResponse['result'];
                logActivity("GPUaaS pool billing for TeamID {$team->teamid}: " . json_encode($gpuaasPoolData));
                
                if (isset($gpuaasPoolData->details) && !empty($gpuaasPoolData->details)) {
                    foreach ($gpuaasPoolData->details as $poolName => $poolData) {
                        $intervalsArray = (array)$poolData->intervals;
                        $interval = reset($intervalsArray);
                        
                        $gpuCost = number_format($interval->GPU->cost ?? 0, 2);
                        $vramCost = number_format($interval->vRAM->cost ?? 0, 2);
                        $subscriptionCost = number_format($interval->SubscriptionRate->cost ?? 0, 2);
                        $ephemeralStorageCost = number_format($interval->EphimeralStorage->cost ?? 0, 2);
                        $cpuCost = number_format($interval->CPU->cost ?? 0, 2);
                        $ramCost = number_format($interval->RAM->cost ?? 0, 2);
                        $intervalHoursDecimal = $interval->interval_hours ?? 0;
                        $intervalHoursFormatted = $helper->formatHoursMinutes($intervalHoursDecimal);
                        $totalCost = number_format($interval->interval_cost ?? 0, 2);

                        if ($totalCost > 0) {
                            $description = <<<DESC
                            GPU Pool: {$poolName} ({$poolData->model_type} - {$poolData->vendor_type})
                            GPU Subscription ........................ \$ {$subscriptionCost}
                            GPU Usage ............................... \$ {$gpuCost}
                            vRAM Usage .............................. \$ {$vramCost}
                            CPU Usage ............................... \$ {$cpuCost}
                            RAM Usage ............................... \$ {$ramCost}
                            Ephemeral Storage ....................... \$ {$ephemeralStorageCost}
                            Pool Hours .............................. {$intervalHoursFormatted}
                            DESC;

                            $invoiceItems["itemdescription{$itemCount}"] = $description;
                            $invoiceItems["itemamount{$itemCount}"] = $interval->interval_cost; // Use raw float for invoice
                            $invoiceItems["itemtaxed{$itemCount}"] = true;

                            $totalWithoutTax += $interval->interval_cost;
                            $itemCount++;
                        }
                    }
                }
            } else {
                logActivity("GPUaaS pool billing failed or empty for TeamID {$team->teamid} - HTTP Code: " . ($gpuaasPoolResponse['httpcode'] ?? 'unknown'));
            }

            // Generate Invoice only if there are any costs
            if ($totalWithoutTax > 0) {
                logActivity("Creating invoice for TeamID {$team->teamid} with total amount: \${$totalWithoutTax}");
                $invoiceResult = $helper->createInvoice($team->uid, $invoiceItems, $currencyCode);
                logActivity("Invoice creation response for UID {$team->uid}: " . json_encode($invoiceResult));
                if (isset($invoiceResult['result']) && $invoiceResult['result'] === 'success') {
                    $helper->insert_teamDetail($team->uid, $team->sid, $team->pid, $invoiceResult['invoiceid'], "update");
                    logActivity("Invoice created for UID {$team->uid} - Invoice ID: {$invoiceResult['invoiceid']} - Amount: {$totalWithoutTax}");
                } else {
                    logActivity("Failed to create invoice for UID {$team->uid}: " . json_encode($invoiceResult));
                }

            } else {
                logActivity("No billable costs found for TeamID {$team->teamid} - skipping invoice generation");
            }
        }
    }
    // Suspension & Termination on overdue — monthly mode only (prepaid handled by hourly cron)
    $invoices = Capsule::table('mod_hostdaiteam_details')
        ->where(function ($q) {
            $q->where('billing_mode', 'monthly')->orWhereNull('billing_mode');
        })->get();

    foreach ($invoices as $invoice) {
        $invoice_date = Capsule::table('tblinvoices')->where('id', $invoice->invoiceid)->where('status', 'Unpaid')->value('date');
        $product = Capsule::table('tblproducts')->where('id', $invoice->pid)->first();
    
        if ($invoice_date && $product) {
            $suspend_days = $product->configoption8;
            $terminate_days = $product->configoption9;
    
            if ($suspend_days !== null && $terminate_days !== null) {
                $invoiceDate = new DateTime($invoice_date);
                $today = new DateTime();
                $daysDiff = $invoiceDate->diff($today)->days;
    
                logActivity("Checking service ID {$invoice->sid} - Days since invoice: {$daysDiff}");
    
                if ($daysDiff > $terminate_days) {
                    $helper->suspendTerminate_service($invoice->sid , $invoice->pid , 'ModuleTerminate');
                    logActivity("Service ID {$invoice->sid} TERMINATED - Days since invoice: {$daysDiff} (Limit: {$terminate_days})");
                } elseif ($daysDiff > $suspend_days) {
                    $helper->suspendTerminate_service($invoice->sid, $invoice->pid, 'ModuleSuspend');
                    Capsule::table('mod_hostdaiteam_details')
                        ->where('sid', $invoice->sid)
                        ->update(['suspended_reason' => 'invoice_overdue', 'updated_at' => date('Y-m-d H:i:s')]);
                    logActivity("Service ID {$invoice->sid} SUSPENDED - Days since invoice: {$daysDiff} (Limit: {$suspend_days})");
                }
            } else {
                logActivity("Service ID {$invoice->sid} - Product found but config options missing.");
            }
        } else {
            logActivity("Skipping service ID {$invoice->sid} - Invoice unpaid: " . ($invoice_date ? 'Yes' : 'No') . ", Product found: " . ($product ? 'Yes' : 'No'));
        }
    }

    logActivity("HostedAI Cron completed.");

} catch (\Exception $e) {
    logActivity("Exception in HostedAI Cron: " . $e->getMessage());
}
