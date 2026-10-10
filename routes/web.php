<?php

use Illuminate\Support\Facades\Route;
use Modules\SlotaraInvoices\Models\Invoice;
use Modules\SlotaraInvoices\Models\InvoiceSettings;

// GET /invoices/view/{token}, named "slotara-invoices.view": the customer's copy.
// Only someone with the private link can open it (40 random characters).
Route::get('/view/{token}', function (string $token) {
    $invoice = Invoice::withoutGlobalScope('tenant')->with(['items', 'tenant'])
        ->where('public_token', $token)->firstOrFail();

    return response()
        ->view('slotara-invoices::invoice', [
            'invoice' => $invoice,
            'settings' => InvoiceSettings::for($invoice->tenant_id),
        ])
        ->header('X-Robots-Tag', 'noindex, nofollow')
        ->header('Referrer-Policy', 'no-referrer');
})->where('token', '[A-Za-z0-9]{40}')->middleware('throttle:60,1')->name('view');
