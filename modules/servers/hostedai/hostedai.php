<?php

use WHMCS\Module\Server\HosteDai\Helper;

use WHMCS\Database\Capsule;

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

define('HOSTEDAI_MODULE_VERSION', '2.4.0');

function hostedai_MetaData()
{
    return array(
        'DisplayName' => 'hosted·ai',
        'APIVersion' => '1.0',
        'RequiresServer' => true,
        'Version' => HOSTEDAI_MODULE_VERSION,
    );
}


function hostedai_ConfigOptions(array $params)
{   

    global $whmcs;
    
    // Get the product ID
    $pid = $whmcs->get_req_var("id");
    
    // Try to get server information based on the product's server group
    $serverParams = [];
    
    // Debug logging removed - functionality working correctly
    
    if ($pid) {
        try {
            // Get the product details
            $product = Capsule::table('tblproducts')->where('id', $pid)->first();
            
            if ($product) {
                $productServerGroup = $product->servergroup ?: 0;
                
                // Also check if servergroup is being passed in the request
                $requestServerGroup = 0;
                if (isset($_GET['servergroup']) && $_GET['servergroup']) {
                    $requestServerGroup = $_GET['servergroup'];
                }
                
                $serverGroupToUse = $requestServerGroup ?: $productServerGroup;
                
                if ($serverGroupToUse > 0) {
                    // In WHMCS, server groups are managed through tblservergroupsrel table
                    // We need to join tblservers with tblservergroupsrel to find servers in a group
                    // Get enabled servers from the assigned server group
                    $server = Capsule::table('tblservers')
                        ->join('tblservergroupsrel', 'tblservers.id', '=', 'tblservergroupsrel.serverid')
                        ->where('tblservergroupsrel.groupid', $serverGroupToUse)
                        ->where('tblservers.type', 'hostedai')
                        ->where('tblservers.disabled', 0)
                        ->select('tblservers.*')
                        ->first();
                    
                    if ($server) {
                        $serverParams = [
                            'serverhostname' => $server->hostname,
                            'serverpassword' => decrypt($server->password)
                        ];
                    }
                }
            }
        } catch (Exception $e) {
            // Log error but continue with default behavior
            logActivity('hostedai_ConfigOptions error', $e->getMessage());
        }
    }
    
    $helper = new Helper($serverParams);

    /** Get the API to fetch the pricing policy items */
    $pricingOptions = ['Select Option'];
    try {
        $getPricingPolicy = $helper->getPolicyItems('pricing-policy');
        if (isset($getPricingPolicy['result']) && is_array($getPricingPolicy['result'])) {
            foreach ($getPricingPolicy['result'] as $value) {
                if (isset($value->policy->id)) {
                    $pricingOptions[$value->policy->id] = $value->policy->name;
                }
            }
        }
    } catch (Exception $e) {
        logActivity('hostedai: Failed to load pricing policies: ' . $e->getMessage());
    }

    /** Get the API to fetch the resource policy items */
    $resourceOptions = ['Select Option'];
    try {
        $getResourcePolicy = $helper->getPolicyItems('resource-policy');
        if (isset($getResourcePolicy['result']) && is_array($getResourcePolicy['result'])) {
            foreach ($getResourcePolicy['result'] as $value) {
                $resourceOptions[$value->id] = $value->name;
            }
        }
    } catch (Exception $e) {
        logActivity('hostedai: Failed to load resource policies: ' . $e->getMessage());
    }

    /** Get the API to fetch the Service policy items */
    $serviceOptions = ['Select Option'];
    try {
        $getServicePolicy = $helper->getPolicyItems('policy/service');
        if (isset($getServicePolicy['result']) && is_array($getServicePolicy['result'])) {
            foreach ($getServicePolicy['result'] as $policy) {
                $serviceOptions[$policy->id] = $policy->name;
            }
        }
    } catch (Exception $e) {
        logActivity('hostedai: Failed to load service policies: ' . $e->getMessage());
    }

    /** Get the API to fetch the Instance policy items */
    $instanceOptions = ['Select Option'];
    try {
        $getInstancePolicy = $helper->getPolicyItems('policy/instance-type');
        if (isset($getInstancePolicy['result']) && is_array($getInstancePolicy['result'])) {
            foreach ($getInstancePolicy['result'] as $policy) {
                $instanceOptions[$policy->id] = $policy->name;
            }
        }
    } catch (Exception $e) {
        logActivity('hostedai: Failed to load instance type policies: ' . $e->getMessage());
    }

    /** Get the API to fetch the Image policy items */
    $imageOptions = ['Select Option'];
    try {
        $getImagePolicy = $helper->getPolicyItems('policy/image');
        if (isset($getImagePolicy['result']) && is_array($getImagePolicy['result'])) {
            foreach ($getImagePolicy['result'] as $policy) {
                $imageOptions[$policy->id] = $policy->name;
            }
        }
    } catch (Exception $e) {
        logActivity('hostedai: Failed to load image policies: ' . $e->getMessage());
    }

    // Role is no longer configurable — first user is always Team Admin

     /** create the custom fields */
    $customfieldarray = [
        'team_id' =>
        [
            'type' => 'product',
            'fieldname' => 'team_id|Team Id',
            'relid' => $pid,
            'fieldtype' => 'text',
            'description' => '',
            'adminonly' => 'on',
            'sortorder' => '1',
        ],
    ];
    $helper->createHostedaiCustomFields($customfieldarray);

    return array(

        'pricing_policy' => array(
            'FriendlyName' => 'Pricing Policy',
            'Type' => 'dropdown',
            'Size' => '25',
            'Options' => $pricingOptions,
            'Description' => '',
        ),
        'resource_policy' => array(
            'FriendlyName' => 'Resources Policy',
            'Type' => 'dropdown',
            'Size' => '25',
            'Options' => $resourceOptions,
            'Description' => '',
        ),
        'service_policy' => array(
            'FriendlyName' => 'Service Policy',
            'Type' => 'dropdown',
            'Size' => '25',
            'Options' => $serviceOptions,
            'Description' => '',
        ),
        'instance_type_policy' => array(
            'FriendlyName' => 'Instance Type Policy',
            'Type' => 'dropdown',
            'Size' => '25',
            'Options' => $instanceOptions,
            'Description' => '',
        ),
        'image_policy' => array(
            'FriendlyName' => 'Image Policy',
            'Type' => 'dropdown',
            'Size' => '25',
            'Options' => $imageOptions,
            'Description' => '',
        ),
        'color' => array(
            'FriendlyName' => 'Color',
            'Type' => 'dropdown',
            'Size' => '25',
            'Options' => array(
                '#414141' => 'black',
                '#305EFB' => 'blue',
                '#104822' => 'green',
                '#FF5738' => 'orange',
                '#FFC352' => 'yellow',
            ),
            'Description' => '',
        ),
        'loginUrl' => array(
            'FriendlyName' => 'Login URL',
            'Type' => 'text',
            'Size' => '255',
        ),
        'suspentionDays' => array(
            'FriendlyName' => 'No. of Suspension Days',
            'Type' => 'text',
            'Size' => '25',
        ),
        'termminationDays' => array(
            'FriendlyName' => 'No. of Termination Days',
            'Type' => 'text',
            'Size' => '25',
        ),
        'billing_mode' => array(
            'FriendlyName' => 'Billing Mode',
            'Type' => 'dropdown',
            'Options' => 'monthly,prepaid',
            'Description' => 'monthly = invoice at end of month; prepaid = deduct from wallet each hour',
        ),
        'min_balance' => array(
            'FriendlyName' => 'Min Wallet Balance ($)',
            'Type' => 'text',
            'Size' => '10',
            'Default' => '1.00',
            'Description' => 'Suspend when wallet drops to or below this amount (prepaid mode only)',
        ),

    );

}


/**
 * Test connection with the given server parameters.
 *
 * Allows an admin user to verify that an API connection can be
 * successfully made with the given configuration parameters for a
 * server.
 *
 * When defined in a module, a Test Connection button will appear
 * alongside the Server Type dropdown when adding or editing an
 * existing server.
 */
function hostedai_TestConnection(array $params)
{
    
    try {

        $helper = new Helper($params);
        $getPricingPolicy = $helper->getPolicyItems('pricing-policy');
        if($getPricingPolicy['httpcode'] == 200){
         
            $success = true;
        }
        else{
            $errorMsg = $getPricingPolicy['result']->message;
        }
    } catch (Exception $e) {
        // Record the error in WHMCS's module log.
        logModuleCall(
            'hostedai',
            __FUNCTION__,
            array_diff_key($params, array_flip(['serverpassword', 'serverusername'])),
            $e->getMessage(),
            $e->getTraceAsString()
        );

        $success = false;
        $errorMsg = $e->getMessage();
    }

    return array(
        'success' => $success,
        'error' => $errorMsg,
    );
}

function hostedai_CreateAccount(array $params)
{
    try {
        global $whmcs;
        $helper = new Helper($params);

        $serviceId = $params['serviceid'];
        $pid = $params['pid'];
        $teamId = $params['customfields']['team_id'];
        $userId = $params['userid'];

        $pricingPolicyID = $params['configoption1'];
        $resourcePolicyID =  $params['configoption2'];
        $servicePolicyID =  $params['configoption3'];
        $instancePolicyID =  $params['configoption4'];
        $imagePolicyID =  $params['configoption5'];
        $color =  $params['configoption6'];

        $email =  $params['clientsdetails']['email'];
        $name =  $params['clientsdetails']['fullname'];

        // First user is always Team Admin — find admin role ID from API
        $roleID = '';
        $getRoles = $helper->getPolicyItems('roles');
        if (isset($getRoles['result']->roles)) {
            foreach ($getRoles['result']->roles as $role) {
                if ($role->name === 'team_admin') {
                    $roleID = $role->id;
                    break;
                }
            }
        }

        $postData = [
            'color' => $color ?? '#414141',
            'description' => '',
            'has_general_policies' => true,
            'general' => [
                'image_policy_id' => $imagePolicyID ?? '',
                'instance_type_policy_id' => $instancePolicyID ?? '',
                'pricing_policy_id' => $pricingPolicyID ?? '',
                'resource_policy_id' => $resourcePolicyID ?? '',
                'service_policy_id' => $servicePolicyID ?? '',
            ],
            'members' => [
                [
                    'email' => $email ?? '',
                    'name' => $name ?? '',
                    'role' => $roleID,
                    'pre_onboard' => true,
                ]
            ],
            'name' => preg_replace('/\s+/', '-', trim($name ?? '')) . '-' . $serviceId,
        ];
        

        if(isset($teamId) && $teamId != '')
        {

        }else{
            
            $getResponse = $helper->createHostedaiTeam($postData);
            
            if($getResponse['httpcode'] == 200)
            {

                if (isset($getResponse['result']->id)) {
                    $teamId = $getResponse['result']->id;

                    $fields = ["team_id" => $teamId];
                    $helper->insert_hostedai_custom_fields_value($serviceId, $pid, $fields);

                    $billingMode = $params['configoption10'] ?: 'monthly';
                    $helper->ensureWalletColumns();
                    $helper->insert_teamDetail($userId, $serviceId, $pid, $teamId, 'insert', $billingMode);
                }
                
            }else{
                return $getResponse['result']->message;
            }
        }

    } catch (Exception $e) {
        // Record the error in WHMCS's module log.
        logModuleCall(
            'hostedai',
            __FUNCTION__,
            array_diff_key($params, array_flip(['serverpassword', 'serverusername'])),
            $e->getMessage(),
            $e->getTraceAsString()
        );

        return $e->getMessage();
    }

    return 'success';
}


/**
 * Suspend an instance of a product/service.
 *
 * Called when a suspension is requested. This is invoked automatically by WHMCS
 * when a product becomes overdue on payment or can be called manually by admin
 * user.
 */
function hostedai_SuspendAccount(array $params)
{
    try {
        $helper = new Helper($params);
        $team_id = $params['customfields']['team_id'];

        $getResponse = $helper->suspendHostedaiTeam($team_id);

        if($getResponse['httpcode'] == 200)
        {
            return 'success';   
        }else{
            return 'error';
        }

    } catch (Exception $e) {
        // Record the error in WHMCS's module log.
        logModuleCall(
            'hostedai',
            __FUNCTION__,
            array_diff_key($params, array_flip(['serverpassword', 'serverusername'])),
            $e->getMessage(),
            $e->getTraceAsString()
        );

        return $e->getMessage();
    }
}


/**
 * Un-suspend instance of a product/service.
 *
 * Called when an un-suspension is requested. This is invoked
 * automatically upon payment of an overdue invoice for a product, or
 * can be called manually by admin user.
 */
function hostedai_UnsuspendAccount(array $params)
{
    try {

        $helper = new Helper($params);
        $team_id = $params['customfields']['team_id'];

        $getResponse = $helper->unsuspendHostedaiTeam($team_id);

        if($getResponse['httpcode'] == 200)
        {
            return 'success';   
        }else{
            return 'error';
        }

    } catch (Exception $e) {
        // Record the error in WHMCS's module log.
        logModuleCall(
            'hostedai',
            __FUNCTION__,
            array_diff_key($params, array_flip(['serverpassword', 'serverusername'])),
            $e->getMessage(),
            $e->getTraceAsString()
        );

        return $e->getMessage();
    }

}


/**
 * Terminate instance of a product/service.
 *
 * Called when a termination is requested. This can be invoked automatically for
 * overdue products if enabled, or requested manually by an admin user.
 */
function hostedai_TerminateAccount(array $params)
{
    try {

        $helper = new Helper($params);
        $serviceId = $params['serviceid'];
        $pid = $params['pid'];
        $team_id = $params['customfields']['team_id'];

        // Terminate
        $getResponse = $helper->terminateHostedaiTeam($team_id);

        if($getResponse['httpcode'] == 200)
        {
            $fields = ["team_id" => ''];
            $helper->insert_hostedai_custom_fields_value($serviceId, $pid, $fields);
            $helper->delete_teamDetail($serviceId, $pid);
            return 'success';   
        }else{
            return 'error';
        }

    } catch (Exception $e) {
        // Record the error in WHMCS's module log.
        logModuleCall(
            'hostedai',
            __FUNCTION__,
            array_diff_key($params, array_flip(['serverpassword', 'serverusername'])),
            $e->getMessage(),
            $e->getTraceAsString()
        );

        return $e->getMessage();
    }
}


/**
 * Upgrade or downgrade an instance of a product/service.
 *
 * Called to apply any change in product assignment or parameters. It
 * is called to provision upgrade or downgrade orders, as well as being
 * able to be invoked manually by an admin user.

 */
function hostedai_ChangePackage(array $params)
{
    try {
        $helper = new Helper($params);
        $pricing_policy_id = $params['configoption1'];
        $resource_policy_id = $params['configoption2'];
        $teamId = $params['customfields']['team_id'];

        $changePackage = $helper->changeHostedaiTeamPackage($pricing_policy_id, $resource_policy_id, $teamId); 
        if($changePackage['status'] == 'success') {
            return 'success';
        } else {
            return $changePackage['message'];
        }

    } catch (Exception $e) {
        // Record the error in WHMCS's module log.
        logModuleCall(
            'hostedai',
            __FUNCTION__,
            array_diff_key($params, array_flip(['serverpassword', 'serverusername'])),
            $e->getMessage(),
            $e->getTraceAsString()
        );

        return $e->getMessage();
    }

}




/**
 * Admin services tab additional fields.
 *
 * Define additional rows and fields to be displayed in the admin area service
 * information and management page within the clients profile.

 */
function hostedai_AdminServicesTabFields(array $params)
{
    try {
        global $CONFIG;
        global $whmcs;

        $loginURL = !empty($params['configoption7']) ? $params['configoption7'] : '#';
        $helper = new Helper($params);

        // Phase 6: wallet panel — built from DB only, no external API dependency
        $serviceId   = $params['serviceid'];
        $userId      = $params['userid'];
        $wDetail     = Capsule::table('mod_hostdaiteam_details')->where('sid', $serviceId)->first();
        $billingMode = $wDetail ? ($wDetail->billing_mode ?? 'monthly') : 'monthly';
        $minBalance  = !empty($params['configoption11']) ? floatval($params['configoption11']) : 1.00;

        if ($billingMode === 'prepaid') {
            $cr      = localAPI('GetClientsDetails', ['clientid' => $userId, 'stats' => true]);
            $balance = (isset($cr['result']) && $cr['result'] === 'success') ? floatval($cr['credit'] ?? 0) : null;
            $balFmt  = $balance !== null ? '$' . number_format($balance, 2) : 'N/A';
            $balStyle = ($balance !== null && $balance <= $minBalance)
                ? 'color:#c0392b;font-weight:bold'
                : 'color:#27ae60;font-weight:bold';
            $modeLabel  = '<span class="label label-info" style="font-size:13px">prepaid</span>';
            $walletRows = '
                <tr><td style="width:45%"><strong>Wallet Balance</strong></td>
                    <td style="' . $balStyle . '">' . $balFmt . '</td></tr>
                <tr><td><strong>Min Balance</strong></td>
                    <td>$' . number_format($minBalance, 2) . '</td></tr>
                <tr><td><strong>Last Billed</strong></td>
                    <td>' . htmlspecialchars($wDetail->last_billed_at ?? '—') . '</td></tr>
                <tr><td><strong>Suspended Reason</strong></td>
                    <td>' . htmlspecialchars($wDetail->suspended_reason ?? '—') . '</td></tr>
                <tr><td><strong>Last Warning Sent</strong></td>
                    <td>' . htmlspecialchars($wDetail->low_balance_notified_at ?? '—') . '</td></tr>';
        } else {
            $modeLabel  = '<span class="label label-default" style="font-size:13px">monthly</span>';
            $walletRows = '';
        }

        $selMonthly = $billingMode === 'monthly' ? ' selected' : '';
        $selPrepaid = $billingMode === 'prepaid' ? ' selected' : '';
        $switchRow  = '
                <tr>
                    <td><strong>Switch Billing Mode</strong></td>
                    <td>
                        <select name="billing_mode_switch" class="form-control"
                                style="width:auto;display:inline-block;min-width:120px">
                            <option value="monthly"' . $selMonthly . '>Monthly</option>
                            <option value="prepaid"' . $selPrepaid . '>Prepaid</option>
                        </select>
                        <span class="text-muted" style="margin-left:8px;font-size:12px">
                            Click &ldquo;Save Changes&rdquo; to apply
                        </span>
                    </td>
                </tr>';

        $walletPanel = '
            <div class="panel panel-info" style="max-width:600px">
                <div class="panel-heading"><strong>Wallet &amp; Billing</strong></div>
                <div class="panel-body" style="padding:0">
                    <table class="table" style="margin:0">
                        <tr><td style="width:45%"><strong>Billing Mode</strong></td>
                            <td>' . $modeLabel . '</td></tr>
                        ' . $walletRows . $switchRow . '
                    </table>
                </div>
            </div>';

        $assets = $CONFIG['SystemURL'] . "/modules/servers/hostedai/assets";

        $language = $CONFIG['Language'];
        $langfilename = __DIR__ . '/lang/' . $language . '.php';
        if (file_exists($langfilename)) {
            require($langfilename);
        } else {
            require(__DIR__ . '/lang/english.php');
        }

        $key = $params['customfields']['team_id'];

        if($key != '') {
            $getTeamdata = $helper->getTeamDetail($key);

            // Get team data
            if($getTeamdata['httpcode'] == 200)
            {
    
                if($getTeamdata['result']->team->is_suspended == true) {
                    $is_suspend = "<span class='btn btn-danger'>Suspended</span>";
                } else {
                    $is_suspend = "<span class='btn btn-success'>Active</span>";
                }
    
    
                $getTeamMembers = $helper->getTeamMembers($key);
    
                if($getTeamMembers['httpcode'] == 200) {
                    
                    $members = $getTeamMembers['result']->members;
                    foreach ($members as $member) {
                        $teamEmail  = htmlspecialchars($member->user->email ?? '', ENT_QUOTES, 'UTF-8');
                        $teamStatus = htmlspecialchars($member->status ?? '', ENT_QUOTES, 'UTF-8');
                        $teamRole   = htmlspecialchars($member->role->label ?? '', ENT_QUOTES, 'UTF-8');

                        $teamMemberHTML .= '<tbody>
                                <tr>
                                    <td>' . $teamEmail . '</td>
                                    <td>' . ucfirst($teamStatus) . '</td>
                                    <td>' . $teamRole . '</td>
                                </tr>
                            </tbody>';
                    }
        
                    // Get resource overview
                    $getResourceOverview = $helper->getResourceOverview($key); 
                    
                    if($getResourceOverview['httpcode'] == 200) {
                        $resourceOverviewData = $getResourceOverview['result'];
        
                        $resourceHTML = '';
            
                        foreach ($resourceOverviewData as $resourceType => $resource) {

                            $used = $resource->used;
                            $available = $resource->available;

                            if($available > 0) {
                                $percentage = ($used/$available)*100;
                            } else {
                                $percentage = 0;
                            }
            
                            if ($resourceType == 'cores') {
                                $unit = 'Cores';
                            } elseif ($resourceType == 'gpus') {
                                $unit = 'No. of cards';
                            } else {
                                $unit = 'GB';
                            }

                            if($available == -1) {
                                $aval = 'Unlimited';
                                $used = $resource->used . " " . $unit.  ' (∞)';
                            } else {
                                $aval = $available . " " . $unit;
                                $used = $resource->used . " " . $unit . " (".$percentage."%)";
                            }
                            
                            $safeType  = htmlspecialchars($resourceType, ENT_QUOTES, 'UTF-8');
                            $imagePath = $CONFIG['SystemURL'] . "/modules/servers/hostedai/assets/images/" . $safeType . ".svg";

                            $resourceHTML .= '<div class="col-lg-6 mt-2">
                                    <div class="overview-card">
                                        <div class="overview-card-header">
                                            <img src="'. $imagePath .'" alt="'. $safeType .'">
                                            <h3>'.strtoupper($safeType).'</h3>
                                        </div>
                                        <div class="overview-card-detail">
                                            <p>'.$used.'</p>
                                            <p>'.$aval.'</p>
                                        </div>
                                        <div class="progress">
                                            <div class="progress-bar" role="progressbar" aria-valuenow="'.$percentage.'" aria-valuemin="'.$percentage.'" aria-valuemax="'.$percentage.'" style="width:'.$percentage.'%">'.$percentage.'%</div>
                                        </div>
                                    </div>
                                </div>';
                        }
                        $assets = $CONFIG['SystemURL'] . "/modules/servers/hostedai/assets";
                        $random = substr(str_shuffle('abcdefghijklmnopqrstuvwxyz0123456789'), 0, 3);
                
                        $informationHtml = '
                        <link href="' . $assets . '/css/style.css?v=' . $random . '" rel="stylesheet">
                        <script src="https://cdnjs.cloudflare.com/ajax/libs/validator/13.7.0/validator.min.js"></script>
                        <script src="' . $assets . '/js/custom.js?v=' . $random . '"></script>
                
                        <table class="ad_on_table_dash table table-striped" width="100%" cellspacing="0" cellpadding="0" border="0">
                            <tbody>
                                <tr>
                                    <td style="width:50%" class="hading-td">
                                        <div class="hosting-information">
                                            <div class="panel panel-primary">
                                                <div class="panel-heading"><p>Resource Overview</p> <p>'.$is_suspend.'</p> </div>
                                                <div class="panel-body overview-main">
                                                    <div class="row">
                                                        '.$resourceHTML.'
                                                    </div>
                                                </div>
                                            </div>
                
                                            <div class="panel panel-success">
                                                <div class="panel-heading">Team Members List</div>
                                                <div class="panel-body">
                                                    <table class="table table-bordered">
                                                        <thead class="members-list-head">
                                                            <tr>
                                                                <th scope="col">Email</th>
                                                                <th scope="col">Role</th>
                                                                <th scope="col">Status</th>
                                                            </tr>
                                                        </thead>
                                                            '.$teamMemberHTML.'
                                                    </table>
                
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>';
                
                        return [
                            " Hosting Information" => $informationHtml,
                            " Wallet & Billing"    => $walletPanel,
                        ];

                    }

                }

            }

        }

        // Team API unavailable — still surface wallet info
        return [" Wallet & Billing" => $walletPanel];

    } catch (Exception $e) {
        logModuleCall(
            'hostedai',
            __FUNCTION__,
            array_diff_key($params, array_flip(['serverpassword', 'serverusername'])),
            $e->getMessage(),
            $e->getTraceAsString()
        );
    }
    return array();
}

/**
 * Save handler for the Wallet & Billing admin tab.
 *
 * Called by WHMCS when admin saves the service page. Reads billing_mode_switch
 * from POST and updates mod_hostdaiteam_details accordingly.
 */
function hostedai_AdminServicesTabFieldsSave(array $params)
{
    try {
        $serviceId = $params['serviceid'];
        $newMode   = $params['billing_mode_switch'] ?? null;

        if (!in_array($newMode, ['monthly', 'prepaid'], true)) {
            return;
        }

        $exists = Capsule::table('mod_hostdaiteam_details')->where('sid', $serviceId)->exists();
        if (!$exists) {
            return;
        }

        $current = Capsule::table('mod_hostdaiteam_details')
            ->where('sid', $serviceId)
            ->value('billing_mode');

        if ($current === $newMode) {
            return;
        }

        Capsule::table('mod_hostdaiteam_details')
            ->where('sid', $serviceId)
            ->update([
                'billing_mode'            => $newMode,
                'suspended_reason'        => null,
                'last_billed_at'          => null,
                'low_balance_notified_at' => null,
                'updated_at'              => date('Y-m-d H:i:s'),
            ]);

        logActivity("hostedai: Admin switched service {$serviceId} billing_mode {$current} → {$newMode}");

    } catch (Exception $e) {
        logModuleCall(
            'hostedai',
            __FUNCTION__,
            array_diff_key($params, array_flip(['serverpassword', 'serverusername'])),
            $e->getMessage(),
            $e->getTraceAsString()
        );
    }
}

/**
 * Client area output logic handling.
 *
 * This function is used to define module specific client area output. It should
 * return an array consisting of a template file and optional additional
 * template variables to make available to that template.
 *
 
 */
function hostedai_ClientArea(array $params)
{
    
    try {
        
        global $CONFIG;
        global $whmcs;
        $helper = new Helper($params);

        $loginURL = !empty($params['configoption7']) ? $params['configoption7'] : '#';

        $assets = $CONFIG['SystemURL'] . "/modules/servers/hostedai/assets";

        $language = $CONFIG['Language'];
        $langfilename = __DIR__ . '/lang/' . $language . '.php';
        if (file_exists($langfilename)) {
            require($langfilename);
        } else {
            require(__DIR__ . '/lang/english.php');
        }

        $key = $params['customfields']['team_id'];
        if($key != '') {
            $getTeamdata = $helper->getTeamDetail($key);

            $getTeamdata = $helper->getTeamDetail($key);
    
            if($getTeamdata && $getTeamdata['httpcode'] == 200)
            {
                $getTeamMembers = $helper->getTeamMembers($key);
    
                $responseData  = [
                    'name' => $getTeamdata['result']->team->name,
                    'teamId' =>$key,
                ];
    
                $getResourceOverview = $helper->getResourceOverview($key); 
                
                $templateFile = 'templates/manage.tpl';
    
                if($getResourceOverview['httpcode'] == 200) {
    
                    $resourceOverviewData = $getResourceOverview['result'];

                    foreach ($resourceOverviewData as $key => $resource) {
                        if (isset($resource->available) && $resource->available > 0) {
                            $resource->percent = ($resource->used / $resource->available) * 100;
                        } else {
                            $resource->percent = 0;
                        }
                    }
            
                    // Wallet vars for prepaid services
                    $walletVars = [];
                    $serviceId  = $params['serviceid'];
                    $userId     = $params['clientsdetails']['userid'] ?? null;
                    if ($userId) {
                        $detail      = Capsule::table('mod_hostdaiteam_details')->where('sid', $serviceId)->first();
                        $billingMode = $detail ? ($detail->billing_mode ?? 'monthly') : 'monthly';
                        if ($billingMode === 'prepaid') {
                            $creditResult = localAPI('GetClientsDetails', ['clientid' => $userId, 'stats' => true]);
                            $balance      = floatval($creditResult['credit'] ?? 0);
                            $minBalance   = floatval(!empty($params['configoption11']) ? $params['configoption11'] : 1.00);
                            $walletVars   = [
                                'walletBillingMode' => 'prepaid',
                                'walletBalance'     => number_format($balance, 2),
                                'walletMinBalance'  => number_format($minBalance, 2),
                                'walletLastBilled'  => $detail->last_billed_at ?? null,
                                'walletLowBalance'  => ($balance > $minBalance && $balance <= $minBalance * 2),
                                'walletSuspended'   => isset($detail->suspended_reason) && $detail->suspended_reason === 'balance_zero',
                            ];
                        }
                    }

                    return array(
                        'templatefile' => $templateFile,
                        'templateVariables' => array_merge(array(
                            'responseData' => $responseData,
                            'teammembers' => $getTeamMembers ? $getTeamMembers['result']->members : '',
                            'resourcesData' => $resourceOverviewData,
                            'loginURL' => $loginURL,
                            'serviceId' => $params['serviceid'],
                            'userEmail' => $params['clientsdetails']['email'],
                            'assets' => $assets,
                            'LANG' => $_ADDONLANG
                        ), $walletVars),
                    );
    
                }
                
            }

        }

    } catch (Exception $e) {
        // Record the error in WHMCS's module log.
        logModuleCall(
            'hostedai',
            __FUNCTION__,
            array_diff_key($params, array_flip(['serverpassword', 'serverusername'])),
            $e->getMessage(),
            $e->getTraceAsString()
        );

        // In an error condition, display an error page.
        return array(
            'tabOverviewReplacementTemplate' => 'error.tpl',
            'templateVariables' => array(
                'usefulErrorHelper' => $e->getMessage(),
            ),
        );
    }
}

// InvoicePaid hook lives in includes/hooks/hostedai_wallet.php
// (WHMCS auto-loads that directory; add_hook() here is not guaranteed to fire)
