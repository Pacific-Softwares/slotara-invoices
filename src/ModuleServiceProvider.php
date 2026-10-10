<?php

namespace Modules\SlotaraInvoices;

use App\Models\SlotReservation;
use App\Services\BookingPaymentService;
use Illuminate\Support\Facades\Route;
use Modules\SlotaraInvoices\Filament\InvoicesPlugin;
use Modules\SlotaraInvoices\Models\Invoice;
use Modules\SlotaraInvoices\Support\BookingInvoicer;
use Pacific\Licentra\Modules\Laravel\ModuleServiceProvider as BaseProvider;

class ModuleServiceProvider extends BaseProvider
{
    public function bootModule(): void
    {
        $this->loadModuleResources();
        // Public, print-ready invoice pages at /invoices/view/{private link}.
        $this->loadModuleRoutes('routes/web.php', ['web']);
        // Links sent before 1.1.0 used /slotara-invoices/view/{private link}.
        Route::permanentRedirect('/slotara-invoices/view/{token}', '/invoices/view/{token}')
            ->where('token', '[A-Za-z0-9]{40}');

        // A new booking gets its invoice (unless the business turned that off).
        $this->observe(SlotReservation::class, 'created', fn (SlotReservation $booking) => BookingInvoicer::forBooking($booking));

        // The invoice follows its booking: cancelled and unpaid → void (a reschedule
        // moves it instead), active again → reopened, paid → paid.
        $this->observe(SlotReservation::class, 'updated', function (SlotReservation $booking) {
            $invoices = fn (array $statuses) => Invoice::withoutGlobalScope('tenant')
                ->where('booking_id', $booking->id)->whereIn('status', $statuses)->get();
            $paid = in_array($booking->payment_status, ['paid', 'completed', 'succeeded'], true);

            if ($booking->wasChanged('status')) {
                $wasActive = in_array($booking->getOriginal('status'), ['pending', 'confirmed'], true);
                $isActive  = in_array($booking->status, ['pending', 'confirmed'], true);

                if ($booking->status === 'cancelled' && $wasActive && ! $paid && $booking->cancellation_reason !== 'Rescheduled') {
                    $invoices([Invoice::DRAFT, Invoice::SENT])->each->update(['status' => Invoice::VOID]);
                } elseif ($isActive && $booking->getOriginal('status') === 'cancelled') {
                    $invoices([Invoice::VOID])->each->update(['status' => Invoice::SENT]);
                }
            }

            // Paid later (offline, pay-at-venue, or a payment after the slot hold ended):
            // the money was received, so the invoice is paid even if it had been voided.
            if ($booking->wasChanged('payment_status') && $paid) {
                $invoices([Invoice::DRAFT, Invoice::SENT, Invoice::VOID])->each->markPaid();
            }
        });

        // ...and the other way round: an invoice marked paid (button, edit form or
        // bulk) records the payment on its booking, with the invoice number as reference.
        $this->observe(Invoice::class, 'saved', function (Invoice $invoice) {
            if ($invoice->status !== Invoice::PAID || ! $invoice->booking_id
                || ! ($invoice->wasRecentlyCreated || $invoice->wasChanged('status'))) {
                return;
            }

            $booking = SlotReservation::withoutGlobalScope('tenant')->find($invoice->booking_id);
            if (! $booking || in_array($booking->payment_status, ['paid', 'completed', 'succeeded'], true)) {
                return;
            }

            app(BookingPaymentService::class)->recordOfflinePayment($booking, 'manual', $invoice->number);
        });
    }

    public function filamentPlugins(string $panel): array
    {
        return $panel === 'tenant' ? [InvoicesPlugin::make()] : [];
    }
}
