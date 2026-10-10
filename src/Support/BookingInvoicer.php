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
        // A rescheduled booking keeps the invoice of the booking it replaces.
        if ($moved = self::moveFromRescheduled($booking)) {
            return $moved;
        }

        $settings = InvoiceSettings::for($booking->tenant_id);
        if ((! $force && ! $settings->auto_from_bookings) || (float) $booking->amount <= 0) {
            return null;
        }
        if ($existing = Invoice::withoutGlobalScope('tenant')->where('booking_id', $booking->id)->first()) {
            return $existing;
        }

        $paid = in_array($booking->payment_status, ['paid', 'completed', 'succeeded'], true);

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
            'description' => self::lineDescription($booking),
            'quantity' => 1,
            // Booking amounts already include tax when a tax rate is set: show the net price.
            'unit_price' => round((float) $booking->amount / (1 + (float) $settings->tax_rate / 100), 2),
        ]);

        return $invoice->recalculate();
    }

    /**
     * Slotara reschedules by cancelling the booking (reason "Rescheduled") and
     * creating a new one for the same customer, service and provider in one
     * transaction. When this booking is that new one, move the old booking's
     * invoice over and update its line to the new date, instead of invoicing twice.
     */
    private static function moveFromRescheduled(SlotReservation $booking): ?Invoice
    {
        $previous = SlotReservation::withoutGlobalScope('tenant')
            ->where('tenant_id', $booking->tenant_id)
            ->where('id', '!=', $booking->id)
            ->where('status', 'cancelled')
            ->where('cancellation_reason', 'Rescheduled')
            ->where('cancelled_at', '>=', now()->subMinutes(5))
            ->where('service_id', $booking->service_id)
            ->where('provider_id', $booking->provider_id)
            ->where('email', $booking->email)
            ->whereExists(fn ($q) => $q->from('slotara_invoices')
                ->whereColumn('slotara_invoices.booking_id', 'slot_reservations.id')
                ->where('slotara_invoices.status', '!=', Invoice::VOID))
            ->latest('cancelled_at')
            ->latest('id')
            ->first();

        if (! $previous) {
            return null;
        }

        $invoice = Invoice::withoutGlobalScope('tenant')
            ->where('booking_id', $previous->id)
            ->where('status', '!=', Invoice::VOID)
            ->first();

        $invoice->forceFill(['booking_id' => $booking->id])->saveQuietly();

        // The appointment line carries the date: point it at the new one, unless
        // the business edited the line (then it's theirs to change).
        $line = $invoice->items()->orderBy('sort')->orderBy('id')->first();
        if ($line && $line->description === self::lineDescription($previous)) {
            $line->update(['description' => self::lineDescription($booking)]);
        }

        return $invoice;
    }

    /** "Checkup · Oct 12, 2026 14:00": the service and when. */
    private static function lineDescription(SlotReservation $booking): string
    {
        $service = $booking->service()->withoutGlobalScopes()->first();
        $when = $booking->date ? \Illuminate\Support\Carbon::parse($booking->date)->format('M j, Y').($booking->start_time ? ' '.substr((string) $booking->start_time, 0, 5) : '') : null;

        return trim(($service?->name ?? __('Appointment')).($when ? " · {$when}" : ''));
    }
}
