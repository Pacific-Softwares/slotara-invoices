<?php

namespace Modules\SlotaraInvoices\Support;

use App\Models\SlotReservation;
use Modules\SlotaraInvoices\Models\Invoice;
use Modules\SlotaraInvoices\Models\InvoiceSettings;

/**
 * Turns a booking into an invoice: one line for the service, the business's tax and terms,
 * already "paid" when the booking was paid online. Called from the booking hook and the
 * "Create from booking" action; never creates a second invoice for the same booking.
 */
class BookingInvoicer
{
    public static function forBooking(SlotReservation $booking, bool $force = false): ?Invoice
    {
        $settings = InvoiceSettings::for($booking->tenant_id);
        if ((! $force && ! $settings->auto_from_bookings) || (float) $booking->amount <= 0) {
            return null;
        }
        if ($existing = Invoice::withoutGlobalScope('tenant')->where('booking_id', $booking->id)->first()) {
            return $existing;
        }

        $paid = in_array($booking->payment_status, ['paid', 'completed', 'succeeded'], true);
        $service = $booking->service()->withoutGlobalScopes()->first();
        $when = $booking->date ? \Illuminate\Support\Carbon::parse($booking->date)->format('M j, Y').($booking->start_time ? ' '.substr((string) $booking->start_time, 0, 5) : '') : null;

        $invoice = Invoice::create([
            'tenant_id' => $booking->tenant_id,
            'booking_id' => $booking->id,
            'status' => $paid ? Invoice::PAID : Invoice::SENT,
            'paid_at' => $paid ? now() : null,
            'customer_name' => $booking->name ?: __('Customer'),
            'customer_email' => $booking->email,
            'customer_details' => $booking->phone,
            'issue_date' => today(),
            'due_date' => $paid ? null : today()->addDays($settings->due_days),
            'currency' => $booking->currency ?: ($booking->tenant?->currency ?? 'USD'),
            'tax_label' => $settings->tax_label,
            'tax_rate' => $settings->tax_rate,
        ]);
        $invoice->items()->create([
            'description' => trim(($service?->name ?? __('Appointment')).($when ? " · {$when}" : '')),
            'quantity' => 1,
            // Booking amounts already include tax when a tax rate is set: show the net price.
            'unit_price' => round((float) $booking->amount / (1 + (float) $settings->tax_rate / 100), 2),
        ]);

        return $invoice->recalculate();
    }
}
