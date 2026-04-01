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
        }
    }

    /* Generate Bill */
    public function generateBill($teamid)
    {
        try {

            // Production: Bill for last month
            $start_date = date('Y-m-01\T00:00', strtotime('first day of last month'));
            $end_date = date('Y-m-t\T23:59', strtotime('last month'));

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
            if (!$start_date) {
                $start_date = date('Y-m-01\T00:00', strtotime('first day of last month'));
            }
            if (!$end_date) {
                $end_date = date('Y-m-t\T23:59', strtotime('last month'));
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
            if (!$start_date) {
                $start_date = date('Y-m-01\T00:00', strtotime('first day of last month'));
            }
            if (!$end_date) {
                $end_date = date('Y-m-t\T23:59', strtotime('last month'));
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
            if (!$start_date) {
                $start_date = date('Y-m-01\T00:00', strtotime('first day of last month'));
            }
            if (!$end_date) {
                $end_date = date('Y-m-t\T23:59', strtotime('last month'));
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
            if (!$start_date) {
                $start_date = date('Y-m-01\T00:00', strtotime('first day of last month'));
            }
            if (!$end_date) {
                $end_date = date('Y-m-t\T23:59', strtotime('last month'));
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
        }
    }

    /** Change package based on teamID */
    public function changeHostedaiTeamPackage($pricing_id, $resource_id, $teamId)
    {
        try {

            $updatePricingPolicy = $this->updatePricing($pricing_id, $teamId); 
            $updateResourcePolicy = $this->updateResource($resource_id, $teamId);

            if($updatePricingPolicy['httpcode'] == 200 && $updateResourcePolicy['httpcode'] == 200) {
                return [
                    'status' => 'success',
                    'message' => 'Team package updated successfully.',
                ];
            } else {
                return [
                    'status' => 'error',
                    'message' => 'Error to change the team package.',
                ];
            }

        } catch (Exception $e) {
            logActivity('Failed to Change hostedai team package ID:' .$teamId.  ', Error: ', $e->getMessage());
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

            if ($command = 'ModuleTerminate') {
                if ($results['httpcode'] == 200 && $results['result'] == 'success') {
                    $this->delete_teamDetail($serviceId, $pid);
                }
            }

            return $results;
        } catch (Exception $e) {
            logActivity($command . ' failed, Error:' . $e->getMessage());
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
    public function insert_teamDetail($userId, $serviceId, $pid, $actionId, $action)
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
                    $table->timestamps();
                });
            }

            if ($action == 'insert') {

                Capsule::table('mod_hostdaiteam_details')->insert([
                    'uid' => $userId,
                    'sid' => $serviceId,
                    'pid' => $pid,
                    'teamid' => $actionId,
                    'invoiceid' => '',
                    'status' => 'pending',
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
            } elseif ($action == 'update') {
                Capsule::table('mod_hostdaiteam_details')->where('uid', $userId)->where('pid', $pid)->where('sid', $serviceId)->update([
                    'invoiceid' => $actionId,
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
            }
        } catch (\Exception $e) {
            logActivity('Function (insert_teamDetail) Hostedai Error: ' . $e->getMessage());
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
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($curl, CURLOPT_MAXREDIRS, 10);
        curl_setopt($curl, CURLOPT_TIMEOUT, 10); //timeout in seconds
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

        if ($action == 'suspendHostedaiTeam') {
            $response = json_encode(['response' => 'Hostedai team suspended successfully.']);
        } elseif ($action == 'unsuspendHostedaiTeam') {
            $response = json_encode(['response' => 'Hostedai team unsuspended successfully.']);
        } elseif ($action == 'terminateHostedaiTeam') {
            $response = json_encode(['response' => 'Hostedai team terminated successfully.']);
        }

        logModuleCall("Hostedai", $action, $data, json_decode($response));

        return ['httpcode' => $httpCode, 'result' => json_decode($response)];
    }
}
