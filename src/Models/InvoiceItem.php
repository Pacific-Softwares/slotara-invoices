<?php

namespace Modules\SlotaraInvoices\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoiceItem extends Model
{
    protected $table = 'slotara_invoices_items';

    protected $fillable = ['description', 'quantity', 'unit_price', 'amount', 'sort'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:2', 'unit_price' => 'decimal:2', 'amount' => 'decimal:2'];
    }

    protected static function booted(): void
    {
        static::saving(fn (self $item) => $item->amount = round((float) $item->quantity * (float) $item->unit_price, 2));
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
