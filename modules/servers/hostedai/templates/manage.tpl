{assign var=unique_id value=10|mt_rand:20}
<link href="{$assets}/css/style.css?v={$unique_id}" rel="stylesheet">
<script src="https://cdnjs.cloudflare.com/ajax/libs/validator/13.7.0/validator.min.js"></script>

<script src="{$assets}/js/custom.js?v={$unique_id}"></script>
<div class="container">

    <div class="panel panel-primary">
        <div class="panel-heading"><p>Overview</p> <button class="btn btn-primary otl-login-btn" data-service-id="{$serviceId}" data-user-email="{$userEmail}" data-static-url="{$loginURL}">Login</button> </div>
        <div class="panel-body overview-main">
            <div class="row">
                {foreach from=$resourcesData key=key item=item}
                    <div class="col-lg-6 mt-2">
                        <div class="overview-card">
                            <div class="overview-card-header">
                                <img src="{$assets}/images/{$key}.svg" alt="{$key}">
                                <h3>{$key|upper|replace:'_':' '}</h3>
                            </div>
                            <div class="overview-card-detail">
                                <p>{$item->used} <b>{if $key == 'cores'} {$LANG['cores']} {elseif $key == 'gpus'} {$LANG['no_of_cards']} {else} {$LANG['storage_GB']} {/if}</b> {if $item->available == -1} {$LANG['infinity']} {else} ({$item->percent}%) {/if}</p>
                                <p>{if $item->available == -1} {$LANG['unlimited']} {else} {$item->available}{if $key == 'cores'} {$LANG['cores']} {elseif $key == 'gpus'} {$LANG['no_of_cards']} {else} {$LANG['storage_GB']} {/if} {/if}</p>
                            </div>
                            <div class="progress">
                                <div class="progress-bar" role="progressbar" aria-valuenow="{$item->percent}" aria-valuemin="{$item->percent}" aria-valuemax="{$item->percent}" style="width:{$item->percent}%">{$item->percent}%</div>
                            </div>
                        </div>
                    </div>
                {/foreach}
            </div>
        </div>
    </div>

    {if $walletBillingMode eq 'prepaid'}
    <div class="panel panel-info">
        <div class="panel-heading"><strong>Prepaid Wallet</strong></div>
        <div class="panel-body">
            <div class="row">
                <div class="col-sm-4">
                    <p class="text-muted" style="margin-bottom:2px">Current Balance</p>
                    <h4 style="margin-top:0">{if $walletSuspended}<span class="text-danger">${$walletBalance}</span>{elseif $walletLowBalance}<span class="text-warning">${$walletBalance}</span>{else}<span class="text-success">${$walletBalance}</span>{/if}</h4>
                </div>
                <div class="col-sm-4">
                    <p class="text-muted" style="margin-bottom:2px">Minimum Balance</p>
                    <h4 style="margin-top:0">${$walletMinBalance}</h4>
                </div>
                {if $walletLastBilled}
                <div class="col-sm-4">
                    <p class="text-muted" style="margin-bottom:2px">Last Billed</p>
                    <h4 style="margin-top:0;font-size:14px">{$walletLastBilled}</h4>
                </div>
                {/if}
            </div>
            {if $walletSuspended}
            <div class="alert alert-danger" style="margin-top:10px;margin-bottom:0">
                Service is suspended due to zero balance. Please top up your wallet to reactivate.
            </div>
            {elseif $walletLowBalance}
            <div class="alert alert-warning" style="margin-top:10px;margin-bottom:0">
                Your balance is low. Please top up soon to avoid service suspension.
            </div>
            {/if}
        </div>
    </div>
    {/if}

    <div class="panel panel-success">
        <div class="panel-heading">{$LANG['team_heading']}</div>
        <div class="panel-body">
            <table class="table table-bordered">
                <thead class="members-list-head">
                    <tr>
                        <th scope="col">{$LANG['email']}</th>
                        <th scope="col">{$LANG['role']}</th>
                        <th scope="col">{$LANG['status']}</th>
                    </tr>
                </thead>
                
                <tbody>

                    {foreach from=$teammembers key=key item=member}
                        <tr>
                            <td>{$member->user->email}</td>
                            <td>{$member->role->label}</td>
                            <td>{$member->status|ucfirst}</td>
                        </tr>
                    {/foreach}
                   
                </tbody>
            </table>

        </div>
    </div>
</div>
