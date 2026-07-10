<?php
/**
 * hostedai_wallet.php — prepaid wallet hooks for the hosted·ai WHMCS module
 *
 * Placed in includes/hooks/ so WHMCS auto-loads it on every request.
 * Do not use require/include here — WHMCS bootstraps this file itself.
 */

use WHMCS\Database\Capsule;

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

/**
 * Auto-unsuspend prepaid services when a client pays an invoice and their
 * wallet balance rises above the configured threshold.
 *
 * Only acts on services with suspended_reason = 'balance_zero'.
 * Services suspended for invoice_overdue are intentionally ignored.
 */
add_hook('InvoicePaid', 1, function ($vars) {
    $invoiceId = $vars['invoiceid'] ?? null;
    if (!$invoiceId) {
        return;
    }

    // InvoicePaid only passes invoiceid — look up the client from the invoice
    $invoice = Capsule::table('tblinvoices')->where('id', $invoiceId)->first();
    if (!$invoice) {
        return;
    }
    $userId = $invoice->userid;

    $suspended = Capsule::table('mod_hostdaiteam_details')
        ->where('uid', $userId)
        ->where('billing_mode', 'prepaid')
        ->where('suspended_reason', 'balance_zero')
        ->get();

    if ($suspended->isEmpty()) {
        return;
    }

    $creditResult = localAPI('GetClientsDetails', ['clientid' => $userId, 'stats' => true]);
    $balance      = floatval($creditResult['credit'] ?? 0);

    foreach ($suspended as $service) {
        $product    = Capsule::table('tblproducts')->where('id', $service->pid)->first();
        $minBalance = ($product && !empty($product->configoption11))
            ? floatval($product->configoption11)
            : 1.00;

        if ($balance > $minBalance) {
            localAPI('ModuleUnsuspend', ['accountid' => $service->sid]);
            Capsule::table('mod_hostdaiteam_details')
                ->where('sid', $service->sid)
                ->update(['suspended_reason' => null, 'updated_at' => date('Y-m-d H:i:s')]);
            logActivity("hostedai: Auto-unsuspended service {$service->sid} after top-up — balance \${$balance}");
        }
    }
});
