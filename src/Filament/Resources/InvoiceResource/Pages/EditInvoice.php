<?php

namespace Modules\SlotaraInvoices\Filament\Resources\InvoiceResource\Pages;

use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Modules\SlotaraInvoices\Filament\Resources\InvoiceResource;
use Modules\SlotaraInvoices\Models\Invoice;

class EditInvoice extends EditRecord
{
    protected static string $resource = InvoiceResource::class;

    public function getTitle(): string
    {
        return __('Invoice :number', ['number' => $this->record->number]);
    }

    public function getSubheading(): ?string
    {
        $r = $this->record;
        $status = $r->isOverdue() ? __('Overdue') : (Invoice::statusOptions()[$r->status] ?? $r->status);

        return $status.' · '.InvoiceResource::money((float) $r->total, $r->currency)
            .($r->paid_at ? ' · '.__('paid :date', ['date' => $r->paid_at->format('M j, Y')]) : '');
    }

    protected function getHeaderActions(): array
    {
        return [
            InvoiceResource::openAction(),
            InvoiceResource::markPaidAction()->after(fn () => $this->refreshFormData(['status'])),
            InvoiceResource::markSentAction()->after(fn () => $this->refreshFormData(['status'])),
            ActionGroup::make([
                InvoiceResource::duplicateAction(),
                InvoiceResource::voidAction()->after(fn () => $this->refreshFormData(['status'])),
                DeleteAction::make()->visible(fn () => in_array($this->record->status, [Invoice::DRAFT, Invoice::VOID], true)),
            ]),
        ];
    }

    protected function afterSave(): void
    {
        $this->record->recalculate();
    }
}
