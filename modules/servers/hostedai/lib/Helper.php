<?php

namespace WHMCS\Module\Server\HosteDai;

use Exception;
use WHMCS\Database\Capsule;

use WHMCS\Module\Server;

class Helper
{
    public $baseUrl = '';
    public $token = '';

    /**
     * Convert decimal hours to hours:minutes format
     * @param float $decimalHours
     * @return string
     */
    public function formatHoursMinutes($decimalHours)
    {
        $hours = floor($decimalHours);
        $minutes = round(($decimalHours - $hours) * 60);
        
        // Handle edge case where rounding gives 60 minutes
        if ($minutes >= 60) {
            $hours += 1;
            $minutes = 0;
        }
        
        return sprintf('%d:%02d', $hours, $minutes);
    }
    public $key = '';
    public $method = 'GET';
    public $data = [];
    public $header = [];
    public $endPoint = '';
    public function __construct($params = NULL)
    {
        global $whmcs;

        $servername = $params['serverhostname'];
        if ($servername == '') {
            $servername = Capsule::table('tblservers')->where('type', 'hostedai')->where('disabled', 0)->value('hostname');
        }

        $this->baseUrl = "https://" . $servername . "/api/";

        $this->token = $params['serverpassword'];
        if ($this->token == '') {
            $password = Capsule::table('tblservers')->where('type', 'hostedai')->where('disabled', 0)->value('password');
            $this->token = decrypt($password);
        }
    }

    /** Get the policy data based on type */
    public function getPolicyItems($type = null)
    {

        try {
            /** api to get the license items */
            $endPoint = $type;

            $baseUrl = $this->baseUrl;
            $getUrl =  $baseUrl . $endPoint;
            $curlResponse = $this->curlCall("GET", "getPolicyItems", $endPoint, $getUrl);

            return $curlResponse;
        } catch (Exception $e) {
            logActivity('Error in function (getPolicyItems), Error: ', $e->getMessage());
            return ['httpcode' => 500, 'result' => null];
        }
    }

    /** Api to create the Team */
    public function createHostedaiTeam($apiData)
    {
        try {
            $endPoint = 'team';
            $curlResponse = $this->curlCall("POST", "createHostedaiTeam", $endPoint, $apiData);

            return $curlResponse;
        } catch (Exception $e) {
            logActivity('Unable to create Hostedai Team, Error: ', $e->getMessage());
            return ['httpcode' => 500, 'result' => null];
        }
    }

    /** Invite members to a team with pre-onboarding */
    public function inviteTeamMembers($teamId, $members)
    {
        try {
            $endPoint = 'team/' . $teamId . '/invite';
            $curlResponse = $this->curlCall("POST", "inviteTeamMembers", $endPoint, $members);
            return $curlResponse;
        } catch (Exception $e) {
            logActivity('Unable to invite team members, Error: ' . $e->getMessage());
            return ['httpcode' => 500, 'result' => null];
        }
    }

    /** Onboard a user (complete registration without email flow) */
    public function onboardUser($email, $name, $password)
    {
        try {
            $endPoint = 'onboard';
            $data = [
                'email' => $email,
                'name' => $name,
                'old_password' => $password,
                'new_password' => $password,
            ];
            $curlResponse = $this->curlCall("POST", "onboardUser", $endPoint, $data);
            return $curlResponse;
        } catch (Exception $e) {
            logActivity('Unable to onboard user, Error: ' . $e->getMessage());
            return ['httpcode' => 500, 'result' => null];
        }
    }

    /** Get the team based on teamID */
    public function getTeamDetail($teamid)
    {
        try {
            $endPoint = 'team/' . $teamid;
            $curlResponse = $this->curlCall("GET", "getTeamDetail", $endPoint, '');

            return $curlResponse;
        } catch (Exception $e) {
            logActivity('Unable to get team details, Error: ', $e->getMessage());
            return ['httpcode' => 500, 'result' => null];
        }
    }

    /** Get the team members based on teamID */
    public function getTeamMembers($teamid)
    {
        try {
            $endPoint = 'team/' . $teamid . '/members?page=1&itemsPerPage=50';
            $curlResponse = $this->curlCall("GET", "getTeamMembers", $endPoint, '');

            return $curlResponse;
        } catch (Exception $e) {
            logActivity('Unable to get team details, Error: ', $e->getMessage());
            return ['httpcode' => 500, 'result' => null];
        }
    }

    /** Get the resource overview based on teamID */
    public function getResourceOverview($teamid)
    {
        try {
            $endPoint = 'team/' . $teamid . '/resource-overview';
            $curlResponse = $this->curlCall("GET", "getResourceOverview", $endPoint, '');

            return $curlResponse;
        } catch (Exception $e) {
            logActivity('Unable to get Resource Overview, Error: ', $e->getMessage());
            return ['httpcode' => 500, 'result' => null];
        }
    }

    /** Suspend team based on teamID */
    public function suspendHostedaiTeam($teamid)
    {
        try {
            $endPoint = 'team/' . $teamid . '/suspend';
            $curlResponse = $this->curlCall("POST", "suspendHostedaiTeam", $endPoint, '');

            return $curlResponse;
        } catch (Exception $e) {
            logActivity('Failed to Suspend hostedai team, Error: ', $e->getMessage());
            return ['httpcode' => 500, 'result' => null];
        }
    }

    /** Unsuspend team based on teamID */
    public function unsuspendHostedaiTeam($teamid)
    {
        try {
            $endPoint = 'team/' . $teamid . '/unsuspend';
            $curlResponse = $this->curlCall("POST", "unsuspendHostedaiTeam", $endPoint, '');

            return $curlResponse;
        } catch (Exception $e) {
            logActivity('Failed to Unsuspend hostedai team, Error: ', $e->getMessage());
            return ['httpcode' => 500, 'result' => null];
        }
    }

    /** Terminate team based on teamID */
    public function terminateHostedaiTeam($teamid)
    {
        try {
            $endPoint = 'team/' . $teamid;
            $curlResponse = $this->curlCall("DELETE", "terminateHostedaiTeam", $endPoint, '');

            return $curlResponse;
        } catch (Exception $e) {
            logActivity('Failed to Terminate hostedai team, Error: ', $e->getMessage());
            return ['httpcode' => 500, 'result' => null];
        }
    }

    /**
     * Default billing window for the previous calendar month, expressed in UTC to
     * match the `timezone=UTC` query parameter every billing endpoint is called with.
     * Building it from the server-local clock (date()/strtotime) shifted the window by
     * the server's UTC offset, mis-billing at hour/month boundaries when the WHMCS
     * server is not on UTC. Computed with gmmktime/gmdate so it is timezone-safe.
     *
     * @return array [start 'Y-m-01\TH:i', end 'Y-m-t\TH:i']
     */
    private function lastMonthWindowUtc()
    {
        $y = (int) gmdate('Y');
        $m = (int) gmdate('n') - 1;
        if ($m < 1) { $m = 12; $y -= 1; }
        $firstOfLastMonth = gmmktime(0, 0, 0, $m, 1, $y);
        return [
            gmdate('Y-m-01\T00:00', $firstOfLastMonth),
            gmdate('Y-m-t\T23:59', $firstOfLastMonth),
        ];
    }

    /* Generate Bill */
    public function generateBill($teamid)
    {
        try {

            // Production: Bill for last month (UTC window — see lastMonthWindowUtc)
            [$start_date, $end_date] = $this->lastMonthWindowUtc();

            $endPoint = "team-billing/group-by-workspace/" . $teamid . "/" . $start_date . "/" . $end_date . "/monthly?timezone=UTC";
            
            // Debug logging (disabled in production for security)
            // logActivity("DEBUG generateBill: TeamID={$teamid}, StartDate={$start_date}, EndDate={$end_date}");
            // logActivity("DEBUG generateBill: Full URL=" . $this->baseUrl . $endPoint);

            $curlResponse = $this->curlCall("GET", "generateBill", $endPoint, '');

            return $curlResponse;
        } catch (Exception $e) {
            logActivity('Unable to Generate the bill, Error: ' . $e->getMessage());
            return ['httpcode' => 500, 'result' => null];
        }
    }

    /* Generate Detailed Team Bill with enhanced data */
    public function generateDetailedTeamBill($teamid, $start_date = null, $end_date = null, $interval = 'monthly')
    {
        try {
            if (!$start_date || !$end_date) {
                [$defaultStart, $defaultEnd] = $this->lastMonthWindowUtc();
                if (!$start_date) { $start_date = $defaultStart; }
                if (!$end_date)   { $end_date   = $defaultEnd; }
            }

            $endPoint = "team-billing/" . $teamid . "/" . $start_date . "/" . $end_date . "/" . $interval . "?timezone=UTC";

            $curlResponse = $this->curlCall("GET", "generateDetailedTeamBill", $endPoint, '');

            return $curlResponse;
        } catch (Exception $e) {
            logActivity('Unable to Generate detailed team bill, Error: ', $e->getMessage());
            return ['httpcode' => 500, 'result' => null];
        }
    }

    /* Get Workspace Billing */
    public function getWorkspaceBilling($workspaceId, $start_date = null, $end_date = null, $interval = 'monthly')
    {
        try {
            if (!$start_date || !$end_date) {
                [$defaultStart, $defaultEnd] = $this->lastMonthWindowUtc();
                if (!$start_date) { $start_date = $defaultStart; }
                if (!$end_date)   { $end_date   = $defaultEnd; }
            }

            $endPoint = "workspace-billing/" . $workspaceId . "/" . $start_date . "/" . $end_date . "/" . $interval . "?timezone=UTC";

            $curlResponse = $this->curlCall("GET", "getWorkspaceBilling", $endPoint, '');

            return $curlResponse;
        } catch (Exception $e) {
            logActivity('Unable to Get workspace billing, Error: ', $e->getMessage());
            return ['httpcode' => 500, 'result' => null];
        }
    }

    /* Get Shared Storage Billing for Team by Region */
    public function getTeamSharedStorageBilling($teamId, $regionId = 'all', $start_date = null, $end_date = null, $interval = 'monthly')
    {
        try {
            if (!$start_date || !$end_date) {
                [$defaultStart, $defaultEnd] = $this->lastMonthWindowUtc();
                if (!$start_date) { $start_date = $defaultStart; }
                if (!$end_date)   { $end_date   = $defaultEnd; }
            }

            $endPoint = "team-billing/shared-storage/" . $teamId . "/" . $start_date . "/" . $end_date . "/" . $interval . "?region_id=" . urlencode($regionId) . "&timezone=UTC";

            $curlResponse = $this->curlCall("GET", "getTeamSharedStorageBilling", $endPoint, '');

            return $curlResponse;
        } catch (Exception $e) {
            logActivity('Unable to Get shared storage billing, Error: ', $e->getMessage());
            return ['httpcode' => 500, 'result' => null];
        }
    }

    /* Get GPUaaS Pool Billing for Team by Region */
    public function getTeamGpuaasPoolBilling($teamId, $regionId = 'all', $start_date = null, $end_date = null, $interval = 'monthly')
    {
        try {
            if (!$start_date || !$end_date) {
                [$defaultStart, $defaultEnd] = $this->lastMonthWindowUtc();
                if (!$start_date) { $start_date = $defaultStart; }
                if (!$end_date)   { $end_date   = $defaultEnd; }
            }

            $endPoint = "team-billing/gpuaas-pool/" . $teamId . "/" . $start_date . "/" . $end_date . "/" . $interval . "?region_id=" . urlencode($regionId) . "&timezone=UTC";

            $curlResponse = $this->curlCall("GET", "getTeamGpuaasPoolBilling", $endPoint, '');

            return $curlResponse;
        } catch (Exception $e) {
            logActivity('Unable to Get GPUaaS pool billing, Error: ', $e->getMessage());
            return ['httpcode' => 500, 'result' => null];
        }
    }

    /* Generate Invoice */
    public function createInvoice($id, $invoice, $currencyCode = null)
    {
        try {
            $command = 'CreateInvoice';
            $postData = [
                'userid' => $id,
                'date' => date('Y-m-d'),
                'duedate' => date('Y-m-d', strtotime('+7 days')),
            ];
            
            // Add currency if provided by API
            if ($currencyCode && $currencyCode !== 'USD') {
                // Get WHMCS currency ID for the currency code
                $currencyId = \WHMCS\Database\Capsule::table('tblcurrencies')
                    ->where('code', $currencyCode)
                    ->value('id');
                    
                if ($currencyId) {
                    $postData['currency'] = $currencyId;
                    logActivity("Invoice created with currency: {$currencyCode} (ID: {$currencyId})");
                } else {
                    logActivity("Warning: Currency {$currencyCode} not found in WHMCS, using default currency");
                }
            }
            
            $postData = array_merge($postData, $invoice);

            $results = localAPI($command, $postData);
            return $results;
        } catch (Exception $e) {
            logActivity('Unable to generate invoice for user ' . $id . ', WHMCS LOCAL API ERROR: ', $e->getMessage());
            return ['result' => 'error', 'message' => $e->getMessage()];
        }
    }

    /* Generate bill for the last hour (prepaid mode). Dates optional — default to the
       last hour in UTC (to match ?timezone=UTC); the cron passes an explicit window so
       all category queries cover the identical hour. */
    public function generateHourlyBill($teamid, $start_date = null, $end_date = null)
    {
        try {
            $end_date   = $end_date ?: gmdate('Y-m-d\TH:i');
            $start_date = $start_date ?: gmdate('Y-m-d\TH:i', time() - 3600);
            $endPoint   = "team-billing/group-by-workspace/{$teamid}/{$start_date}/{$end_date}/hourly?timezone=UTC";
            return $this->curlCall("GET", "generateHourlyBill", $endPoint, '');
        } catch (Exception $e) {
            logActivity('generateHourlyBill error: ' . $e->getMessage());
            return ['httpcode' => 500, 'result' => null];
        }
    }

    /**
     * Per-instance cost breakdown, summed by category label across all billing
     * intervals, over the three buckets the API returns per interval:
     *   - Resources (CPU, RAM, GPU, vRAM, TFlops, Ephemeral/Disk Storage, Public IP, …)
     *   - Services  (service-policy fees) → grouped under a single "Service" label
     *   - pci_dev   (PCI / GPU passthrough cards) → grouped under "PCI (GPU card)"
     * The roll-up "total_cost" key inside Resources is skipped so it is not counted.
     *
     * IMPORTANT — verified against the live API: the platform's authoritative per-instance
     * charge (its `total_billing`, and the user-panel figure) = Resources + Services +
     * pci_dev. The `instance.total_cost` field is **Resources-only** and understates the
     * bill by the Service/PCI amount, so it must NOT be used as the amount — the crons
     * bill the sum of this breakdown instead. Example (verified): Resources 18.30 +
     * Services 8.13 = 26.43 = total_billing = panel.
     *
     * @return array<string,float>
     */
    public function instanceCostBreakdown($instanceData)
    {
        $out = [];
        foreach ((array) ($instanceData->intervals ?? []) as $interval) {
            foreach ((array) ($interval->Resources ?? []) as $key => $entry) {
                if ($key === 'total_cost' || !is_object($entry) || !isset($entry->cost)) {
                    continue;
                }
                $out[$key] = ($out[$key] ?? 0) + floatval($entry->cost);
            }
            foreach ((array) ($interval->Services ?? []) as $entry) {
                if (is_object($entry) && isset($entry->cost)) {
                    $out['Service'] = ($out['Service'] ?? 0) + floatval($entry->cost);
                }
            }
            foreach ((array) ($interval->pci_dev ?? []) as $entry) {
                if (is_object($entry) && isset($entry->cost)) {
                    $out['PCI (GPU card)'] = ($out['PCI (GPU card)'] ?? 0) + floatval($entry->cost);
                }
            }
        }
        return $out;
    }

    /**
     * Authoritative per-instance charge = sum of the breakdown (Resources + Services +
     * pci_dev), which the live API confirms equals the platform's `total_billing` / panel
     * figure. Used as the per-instance amount by both crons (do NOT use the API's
     * `instance.total_cost`, which is Resources-only and understates the bill).
     */
    public function sumInstanceResourceCost($instanceData)
    {
        return array_sum($this->instanceCostBreakdown($instanceData));
    }

    /**
     * Create a usage-deduction invoice and immediately pay it from the client's credit.
     *
     * $lineItems (optional): [['description' => string, 'amount' => float], ...] for an
     * itemized invoice (e.g. a compute line + a shared-storage line). When omitted, a
     * single line built from $amount/$description is used (back-compatible).
     *
     * Note: WHMCS invoices carry no currency of their own (tblinvoices has no currency
     * column) — they are always denominated in the client's currency; a `currency` param
     * would have no effect. warnOnCurrencyMismatch() surfaces a mismatch instead.
     */
    public function createAndPayHourlyInvoice($userId, $amount, $description, $lineItems = null)
    {
        try {
            $items = (is_array($lineItems) && $lineItems)
                ? $lineItems
                : [['description' => $description, 'amount' => $amount]];

            $params = [
                'userid'  => $userId,
                'date'    => date('Y-m-d'),
                'duedate' => date('Y-m-d'),
            ];
            $total = 0.0;
            $i = 1;
            foreach ($items as $it) {
                $params["itemdescription{$i}"] = $it['description'];
                $params["itemamount{$i}"]      = $it['amount'];
                $params["itemtaxed{$i}"]       = false;
                $total += floatval($it['amount']);
                $i++;
            }

            $invoice = localAPI('CreateInvoice', $params);

            if (!isset($invoice['result']) || $invoice['result'] !== 'success') {
                logActivity("createAndPayHourlyInvoice: CreateInvoice failed for UID {$userId}: " . json_encode($invoice));
                return ['result' => 'error', 'message' => 'CreateInvoice failed'];
            }

            $invoiceId    = $invoice['invoiceid'];
            $creditResult = localAPI('ApplyCredit', ['invoiceid' => $invoiceId, 'amount' => $total]);
            logActivity("Hourly deduction: UID={$userId} amount=\${$total} invoice=#{$invoiceId} items=" . count($items) . " credit=" . json_encode($creditResult));

            return ['result' => 'success', 'invoiceid' => $invoiceId, 'credit_result' => $creditResult];
        } catch (Exception $e) {
            logActivity('createAndPayHourlyInvoice error: ' . $e->getMessage());
            return ['result' => 'error', 'message' => $e->getMessage()];
        }
    }

    /**
     * Create a WHMCS Add Funds invoice. When the client pays it, WHMCS natively
     * adds the amount to their credit balance (the deposit is recorded once — no
     * double-counting). There is no CreateInvoice flag for an Add Funds item type,
     * so we create the invoice then flip its line item to type 'AddFunds'.
     *
     * @return array ['result' => 'success', 'invoiceid' => int] | error shape
     */
    public function createAddFundsInvoice($userId, $amount, $sendEmail = true)
    {
        try {
            $amount = round(floatval($amount), 2);
            if ($amount <= 0) {
                return ['result' => 'error', 'message' => 'amount must be > 0'];
            }

            $invoice = localAPI('CreateInvoice', [
                'userid'           => $userId,
                'date'             => date('Y-m-d'),
                'duedate'          => date('Y-m-d'),
                'itemdescription1' => 'Add Funds',
                'itemamount1'      => $amount,
                'itemtaxed1'       => false,
                'sendinvoice'      => $sendEmail ? true : false,
            ]);

            if (!isset($invoice['result']) || $invoice['result'] !== 'success') {
                logActivity("createAddFundsInvoice: CreateInvoice failed for UID {$userId}: " . json_encode($invoice));
                return ['result' => 'error', 'message' => 'CreateInvoice failed'];
            }

            $invoiceId = $invoice['invoiceid'];

            // Flip the line item to an Add Funds deposit so WHMCS credits the
            // client's wallet natively on payment (no custom AddCredit needed).
            Capsule::table('tblinvoiceitems')
                ->where('invoiceid', $invoiceId)
                ->update(['type' => 'AddFunds', 'relid' => 0, 'description' => 'Add Funds']);

            logActivity("createAddFundsInvoice: Add Funds invoice #{$invoiceId} for UID {$userId} amount \${$amount}");
            return ['result' => 'success', 'invoiceid' => $invoiceId];
        } catch (Exception $e) {
            logActivity('createAddFundsInvoice error: ' . $e->getMessage());
            return ['result' => 'error', 'message' => $e->getMessage()];
        }
    }

    /**
     * True if the client already has an unpaid Add Funds invoice. Used to avoid
     * stacking multiple auto top-up invoices (credit balance is per client).
     */
    public function hasOpenAddFundsInvoice($userId)
    {
        try {
            return Capsule::table('tblinvoices')
                ->join('tblinvoiceitems', 'tblinvoices.id', '=', 'tblinvoiceitems.invoiceid')
                ->where('tblinvoices.userid', $userId)
                ->where('tblinvoices.status', 'Unpaid')
                ->where('tblinvoiceitems.type', 'AddFunds')
                ->exists();
        } catch (Exception $e) {
            logActivity('hasOpenAddFundsInvoice error: ' . $e->getMessage());
            return false;
        }
    }

    /* Get credit balance for a client */
    public function getClientCreditBalance($userId)
    {
        try {
            $result = localAPI('GetClientsDetails', ['clientid' => $userId, 'stats' => true]);
            if (isset($result['result']) && $result['result'] === 'success') {
                return floatval($result['credit'] ?? 0);
            }
            return null;
        } catch (Exception $e) {
            logActivity('getClientCreditBalance error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Warn when the currency the hosted·ai API bills in differs from the client's
     * WHMCS currency. WHMCS has no per-invoice currency (tblinvoices has no currency
     * column) — every invoice is denominated in the client's currency — so a mismatch
     * means the API's numeric amount is silently recorded in the wrong currency. The
     * operator must align the client's WHMCS currency with the pricing policy.
     *
     * @return bool true when a mismatch was detected and logged.
     */
    public function warnOnCurrencyMismatch($userId, $apiCurrencyCode)
    {
        try {
            if (empty($apiCurrencyCode)) {
                return false;
            }
            $clientCurrency = Capsule::table('tblclients')
                ->join('tblcurrencies', 'tblclients.currency', '=', 'tblcurrencies.id')
                ->where('tblclients.id', $userId)
                ->value('tblcurrencies.code');

            if ($clientCurrency && strtoupper($clientCurrency) !== strtoupper($apiCurrencyCode)) {
                logActivity(
                    "hostedai: currency mismatch for UID {$userId} — pricing policy bills in "
                    . "{$apiCurrencyCode} but the client's WHMCS currency is {$clientCurrency}. "
                    . "WHMCS records the amount in {$clientCurrency}; align the client's currency "
                    . "with the policy to avoid mis-denominated charges."
                );
                return true;
            }
            return false;
        } catch (\Exception $e) {
            logActivity('warnOnCurrencyMismatch error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Ensure low_balance_notified_at column exists in mod_hostdaiteam_details.
     * Called by the hourly cron before querying the table.
     */
    public function ensureWalletColumns()
    {
        try {
            if (!Capsule::schema()->hasTable('mod_hostdaiteam_details')) {
                return;
            }
            if (!Capsule::schema()->hasColumn('mod_hostdaiteam_details', 'low_balance_notified_at')) {
                Capsule::schema()->table('mod_hostdaiteam_details', function ($table) {
                    $table->dateTime('low_balance_notified_at')->nullable();
                });
            }
        } catch (\Exception $e) {
            logActivity('ensureWalletColumns error: ' . $e->getMessage());
        }
    }

    /**
     * Send a low-balance warning email to the client.
     * Auto-creates the email template in WHMCS if it does not exist.
     */
    public function sendLowBalanceWarning($userId, $serviceId, $balance, $minBalance)
    {
        try {
            $templateName = 'hostedai_low_balance_warning';

            $exists = Capsule::table('tblemailtemplates')
                ->where('name', $templateName)
                ->exists();

            if (!$exists) {
                Capsule::table('tblemailtemplates')->insert([
                    'type'      => 'general',
                    'name'      => $templateName,
                    'subject'   => 'Low Wallet Balance — Action Required',
                    'message'   => '<p>Dear {$client_name},</p>'
                        . '<p>Your prepaid wallet balance for service #' . '{$service_id}' . ' is currently <strong>$' . '{$balance}' . '</strong>.</p>'
                        . '<p>The minimum balance threshold is <strong>$' . '{$threshold}' . '</strong>. '
                        . 'Please top up your wallet to avoid service suspension.</p>',
                    'disabled'  => 0,
                    'custom'    => 1,
                    'fromname'  => '',
                    'fromemail' => '',
                ]);
            }

            $customVars = base64_encode(serialize([
                'service_id' => $serviceId,
                'balance'    => number_format($balance, 2),
                'threshold'  => number_format($minBalance, 2),
            ]));

            $result = localAPI('SendEmail', [
                'messagename' => $templateName,
                'id'          => $userId,
                'customvars'  => $customVars,
            ]);

            logActivity("hostedai: Low balance warning sent to UID {$userId} for service {$serviceId} — balance \${$balance}");

            return $result;
        } catch (\Exception $e) {
            logActivity('sendLowBalanceWarning error: ' . $e->getMessage());
            return ['result' => 'error', 'message' => $e->getMessage()];
        }
    }

    /**
     * True if a policy assign-team call reached the desired state. The pricing endpoint
     * returns 200 when the team is already assigned, but the resource endpoint returns
     * 400 "already linked to this resource policy" for the same no-op. Both mean the
     * team already has that policy — a success, not a failure. Without this, a package
     * change that leaves the resource policy unchanged (e.g. a pricing-only upgrade, or
     * a re-run) would wrongly report an error.
     */
    private function policyAssignSucceeded($response)
    {
        $code = $response['httpcode'] ?? 0;
        if ($code == 200 || $code == 201) {
            return true;
        }
        if ($code != 400) {
            return false;
        }
        // "Already assigned/linked" is a no-op success, not a failure. The resource
        // endpoint reports it in the top-level message ("already linked to this resource
        // policy"); the generic policy/{type}/assign-team endpoint reports it nested in
        // errors[] ("policy already assigned to team" / "Team already assigned to policy").
        $result = $response['result'] ?? null;
        $texts  = [];
        if (is_object($result)) {
            if (isset($result->message)) {
                $texts[] = $result->message;
            }
            if (isset($result->errors) && is_array($result->errors)) {
                foreach ($result->errors as $err) {
                    if (isset($err->message)) { $texts[] = $err->message; }
                    if (isset($err->detail))  { $texts[] = $err->detail; }
                }
            }
        }
        $blob = strtolower(implode(' | ', $texts));
        return strpos($blob, 'already linked') !== false || strpos($blob, 'already assigned') !== false;
    }

    /** True if $id looks like a policy UUID (skips '', '0', 'Select Option', labels). */
    private function isPolicyId($id)
    {
        return is_string($id)
            && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $id) === 1;
    }

    /* Assign a general policy (service / instance-type / image) to a team. */
    public function assignPolicyToTeam($policyType, $policyId, $teamId)
    {
        try {
            $endPoint = 'policy/' . $policyType . '/assign-team';
            $data = ['team_id' => $teamId, 'policy_id' => $policyId];
            return $this->curlCall('POST', 'assignPolicyToTeam', $endPoint, $data);
        } catch (Exception $e) {
            logActivity('Failed to assign ' . $policyType . ' policy to team, Error: ' . $e->getMessage());
            return ['httpcode' => 500, 'result' => null];
        }
    }

    /** Change package based on teamID */
    public function changeHostedaiTeamPackage($pricing_id, $resource_id, $teamId, $service_id = null, $instance_type_id = null, $image_id = null)
    {
        try {

            // Propagate all configured policies so an upgrade to a product with different
            // policies actually applies them (previously only pricing + resource were sent,
            // so image/instance-type/service changes were silently dropped). Each general
            // policy is only sent when a valid policy id is configured for the product.
            $results = [
                'pricing'  => $this->updatePricing($pricing_id, $teamId),
                'resource' => $this->updateResource($resource_id, $teamId),
            ];
            $generalPolicies = [
                'service'       => $service_id,
                'instance-type' => $instance_type_id,
                'image'         => $image_id,
            ];
            foreach ($generalPolicies as $policyType => $policyId) {
                if ($this->isPolicyId($policyId)) {
                    $results[$policyType] = $this->assignPolicyToTeam($policyType, $policyId, $teamId);
                }
            }

            $failures = [];
            foreach ($results as $label => $resp) {
                if (!$this->policyAssignSucceeded($resp)) {
                    $msg = $resp['result']->message ?? ('HTTP ' . ($resp['httpcode'] ?? '?'));
                    $failures[] = "{$label}: {$msg}";
                }
            }

            if (empty($failures)) {
                return [
                    'status' => 'success',
                    'message' => 'Team package updated successfully.',
                ];
            }

            // Surface the real reason(s) instead of a generic message.
            return [
                'status'  => 'error',
                'message' => 'Failed to change team package (' . implode('; ', $failures) . ').',
            ];

        } catch (Exception $e) {
            logActivity('Failed to Change hostedai team package ID:' .$teamId.  ', Error: ', $e->getMessage());
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }


    /* Update pricing */
    public function updatePricing($pricing_id, $team_id)
    {
        try {
            $endPoint = 'pricing-policy/'. $pricing_id .'/assign-team';

            $data = ["team_id" => $team_id];

            $curlResponse = $this->curlCall("POST", "updatePricing", $endPoint, $data);

            return $curlResponse;

        } catch (Exception $e) {
            logActivity('Failed to Change hostedai team package, Error: ', $e->getMessage());
            return ['httpcode' => 500, 'result' => null];
        }
    }


    /* Update resource */
    public function updateResource($resource_id, $team_id)
    {
        try {
            $endPoint = 'resource-policy/assign-team';

            $data = ["policy_id" => $resource_id, "team_id" => $team_id];

            $curlResponse = $this->curlCall("POST", "updateResource", $endPoint, $data);
            
            return $curlResponse;

        } catch (Exception $e) {
            logActivity('Failed to Change hostedai team package, Error: ', $e->getMessage());
            return ['httpcode' => 500, 'result' => null];
        }
    }


    /* Suspend or Terminate Hostedai Service */
    public function suspendTerminate_service($serviceId, $pid, $command)
    {
        try {
            $postData = array(
                'serviceid' => $serviceId,
            );

            $results = localAPI($command, $postData);

            if ($command == 'ModuleTerminate') {
                if ($results['httpcode'] == 200 && $results['result'] == 'success') {
                    $this->delete_teamDetail($serviceId, $pid);
                }
            }

            return $results;
        } catch (Exception $e) {
            logActivity($command . ' failed, Error:' . $e->getMessage());
            return ['result' => 'error', 'message' => $e->getMessage()];
        }
    }

    /** Create the custom fields */
    public function createHostedaiCustomFields($customfieldarray)
    {
        foreach ($customfieldarray as $fieldname => $customfieldarrays) {

            if (Capsule::table('tblcustomfields')->where('type', $customfieldarrays['type'])->where('relid', $customfieldarrays['relid'])->where('fieldname', 'like', '%' . $fieldname . '%')->count() == 0) {
                Capsule::table('tblcustomfields')->insert($customfieldarrays);
            }
        }
    }

    /** Update custom fields data  */
    public function insert_hostedai_custom_fields_value($serviceid, $package_id, $fields = [])
    {
        try {
            foreach ($fields as $key => $value) {
                $custom_field_data = Capsule::table('tblcustomfields')->where("type", "product")->where("fieldname", "like", "%$key%")->where("relid", $package_id)->first();

                if ($custom_field_data) {
                    $field_value = Capsule::table('tblcustomfieldsvalues')->where("fieldid", "=", $custom_field_data->id)->where("relid", "=", $serviceid)->first();

                    if ($field_value->id) {
                        $field_value = Capsule::table('tblcustomfieldsvalues')->where("fieldid", "=", $custom_field_data->id)->where("relid", "=", $serviceid)->update(["value" => $value]);
                    } else {
                        $field_value = Capsule::table('tblcustomfieldsvalues')->insert(["fieldid" => $custom_field_data->id, "relid" => $serviceid, "value" => $value]);
                    }
                }
            }

            return "success";
        } catch (\Exception $e) {
            logActivity('funtion(insert_hostedai_custom_fields_value) Hostedai Error:', $e->getMessage());
            return $e->getMessage();
        }
    }

    /* Insert Team details in custom table */
    public function insert_teamDetail($userId, $serviceId, $pid, $actionId, $action, $billingMode = 'monthly')
    {
        try {
            if (!Capsule::schema()->hasTable('mod_hostdaiteam_details')) {
                Capsule::schema()->create('mod_hostdaiteam_details', function ($table) {
                    $table->increments('id');
                    $table->string('uid');
                    $table->string('sid');
                    $table->string('pid');
                    $table->string('teamid');
                    $table->string('invoiceid');
                    $table->string('status');
                    $table->string('billing_mode')->default('monthly');
                    $table->string('suspended_reason')->nullable();
                    $table->dateTime('last_billed_at')->nullable();
                    $table->dateTime('low_balance_notified_at')->nullable();
                    $table->timestamps();
                });
            } else {
                if (!Capsule::schema()->hasColumn('mod_hostdaiteam_details', 'billing_mode')) {
                    Capsule::schema()->table('mod_hostdaiteam_details', function ($table) {
                        $table->string('billing_mode')->default('monthly');
                    });
                }
                if (!Capsule::schema()->hasColumn('mod_hostdaiteam_details', 'suspended_reason')) {
                    Capsule::schema()->table('mod_hostdaiteam_details', function ($table) {
                        $table->string('suspended_reason')->nullable();
                    });
                }
                if (!Capsule::schema()->hasColumn('mod_hostdaiteam_details', 'last_billed_at')) {
                    Capsule::schema()->table('mod_hostdaiteam_details', function ($table) {
                        $table->dateTime('last_billed_at')->nullable();
                    });
                }
                $this->ensureWalletColumns();
            }

            if ($action == 'insert') {
                Capsule::table('mod_hostdaiteam_details')->insert([
                    'uid'          => $userId,
                    'sid'          => $serviceId,
                    'pid'          => $pid,
                    'teamid'       => $actionId,
                    'invoiceid'    => '',
                    'status'       => 'pending',
                    'billing_mode' => $billingMode,
                    'created_at'   => date('Y-m-d H:i:s'),
                    'updated_at'   => date('Y-m-d H:i:s'),
                ]);
            } elseif ($action == 'update') {
                Capsule::table('mod_hostdaiteam_details')
                    ->where('uid', $userId)
                    ->where('pid', $pid)
                    ->where('sid', $serviceId)
                    ->update([
                        'invoiceid'  => $actionId,
                        'updated_at' => date('Y-m-d H:i:s'),
                    ]);
            }

            return true;
        } catch (\Exception $e) {
            logActivity('Function (insert_teamDetail) Hostedai Error: ' . $e->getMessage());
            return false;
        }
    }

    /* Delete Custom table values */
    public function delete_teamDetail($serviceId, $pid)
    {
        try {
            Capsule::table('mod_hostdaiteam_details')->where('sid', $serviceId)->where('pid', $pid)->delete();
        } catch (\Exception $e) {
            logActivity('Function (delete_teamDetail) Hostedai Error: ' . $e->getMessage());
        }
    }

    /** Create One Time Login Token */
    public function createOneTimeLoginToken($userEmail, $fullData = null)
    {
        try {
            $endPoint = 'create-otl';
            if ($fullData) {
                $data = $fullData;
            } else {
                $data = [
                    'email' => $userEmail,
                    'send_email_invite' => false
                ];
            }
            $curlResponse = $this->curlCall("POST", "createOneTimeLoginToken", $endPoint, $data);
            return $curlResponse;
        } catch (Exception $e) {
            logActivity('Unable to create OTL token, Error: ' . $e->getMessage());
            return ['httpcode' => 500, 'result' => null];
        }
    }

    /* Retrieve the Curl API response.*/
    public function curlCall($method, $action, $endpoint = null, $data = null)
    {

        $baseUrl = $this->baseUrl;

        $curl = curl_init();
        switch ($method) {
            case 'POST':
                curl_setopt($curl, CURLOPT_POST, 1);
                curl_setopt($curl, CURLOPT_POSTFIELDS, (count((array) $data) > 0 ? json_encode($data) : ""));
                break;
            case 'PUT':
                curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'PUT');
                curl_setopt($curl, CURLOPT_POSTFIELDS, (count((array) $data) > 0 ? json_encode($data) : ""));
                break;
            case 'DELETE':
                curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'DELETE');
                curl_setopt($curl, CURLOPT_POSTFIELDS, (count((array) $data) > 0 ? json_encode($data) : ""));
                break;
            default:
                curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'GET');
        }

        curl_setopt($curl, CURLOPT_URL, $baseUrl . $endpoint);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($curl, CURLOPT_MAXREDIRS, 10);
        // 30s total — team creation/provisioning can take longer than a read.
        // CONNECTTIMEOUT (5s) still fails fast if the host is unreachable.
        curl_setopt($curl, CURLOPT_TIMEOUT, 30); //timeout in seconds
        curl_setopt($curl, CURLOPT_FOLLOWLOCATION, 1);
        if (isset($this->token) && $this->token != '')
            curl_setopt($curl, CURLOPT_HTTPHEADER, array('accept: application/json', 'Content-Type: application/json', 'x-api-key: ' . $this->token));
        else
            curl_setopt($curl, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));

        $response = curl_exec($curl);

        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($curl);

        // Debug logging (disabled in production for security)
        // logActivity("DEBUG curlCall: URL=" . $baseUrl . $endpoint . ", Method={$method}, HTTPCode={$httpCode}");
        if ($curl_error) {
            logActivity("CURL Error: Connection failed"); // Sanitized error logging
        }
        if ($httpCode >= 400) {
            logActivity("API Error: HTTP {$httpCode}"); // Sanitized error logging
        }
        if (empty($this->token)) {
            logActivity("Configuration Error: API token not configured");
        }

        if (curl_errno($curl)) {
            throw new \Exception(curl_error($curl));
        }
        curl_close($curl);
        $status = ($httpCode == 201 || $httpCode == 200) ? "success" : "failed";

        if ($data == '') {
            $data = ['url' =>  $baseUrl . $endpoint];
        }

        // Log and return the real API response — never fabricate a body.
        // Success/failure is determined by the HTTP code, not the body.
        logModuleCall("Hostedai", $action, $data, json_decode($response));

        return ['httpcode' => $httpCode, 'result' => json_decode($response)];
    }
}
