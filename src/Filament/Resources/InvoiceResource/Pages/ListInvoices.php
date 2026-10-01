<?php

namespace Modules\SlotaraInvoices\Filament\Resources\InvoiceResource\Pages;

use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Modules\SlotaraInvoices\Filament\Pages\InvoiceSettingsPage;
use Modules\SlotaraInvoices\Filament\Resources\InvoiceResource;
use Modules\SlotaraInvoices\Filament\Widgets\InvoiceStats;

class ListInvoices extends ListRecords
{
    protected static string $resource = InvoiceResource::class;

    public function getSubheading(): ?string
    {
        return __('Bookings are invoiced automatically. Share an invoice with its customer link.');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('settings')->label(__('Settings'))->icon('heroicon-o-cog-6-tooth')->color('gray')->url(InvoiceSettingsPage::getUrl()),
            CreateAction::make()->label(__('New invoice'))->icon('heroicon-o-plus'),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [InvoiceStats::class];
    }
}
