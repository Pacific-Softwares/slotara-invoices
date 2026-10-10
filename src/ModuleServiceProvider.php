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

        // When a booking is paid later (offline payment, pay-at-venue), its invoice follows.
        $this->observe(SlotReservation::class, 'updated', function (SlotReservation $booking) {
            if ($booking->wasChanged('payment_status') && in_array($booking->payment_status, ['paid', 'completed', 'succeeded'], true)) {
                Invoice::withoutGlobalScope('tenant')->where('booking_id', $booking->id)
                    ->whereIn('status', [Invoice::DRAFT, Invoice::SENT])
                    ->get()->each->markPaid();
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
