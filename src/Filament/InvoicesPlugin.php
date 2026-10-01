<?php

namespace Modules\SlotaraInvoices\Filament;

use Filament\Contracts\Plugin;
use Filament\Panel;

class InvoicesPlugin implements Plugin
{
    public static function make(): static
    {
        return new static();
    }

    public function getId(): string
    {
        return 'slotara-invoices';
    }

    public function register(Panel $panel): void
    {
        $panel
            ->resources([Resources\InvoiceResource::class])
            ->pages([Pages\InvoiceSettingsPage::class]);
    }

    public function boot(Panel $panel): void
    {
    }
}
