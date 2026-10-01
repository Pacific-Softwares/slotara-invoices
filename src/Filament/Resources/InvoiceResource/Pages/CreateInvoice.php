<?php

namespace Modules\SlotaraInvoices\Filament\Resources\InvoiceResource\Pages;

use App\Support\TenantContext;
use Filament\Resources\Pages\CreateRecord;
use Modules\SlotaraInvoices\Filament\Resources\InvoiceResource;

class CreateInvoice extends CreateRecord
{
    protected static string $resource = InvoiceResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return $data + ['tenant_id' => TenantContext::id() ?? auth()->user()?->tenant_id];
    }

    protected function afterCreate(): void
    {
        $this->record->recalculate();
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('edit', ['record' => $this->record]);
    }
}
