<?php

namespace Modules\SlotaraInvoices\Models;

use App\Models\SlotReservation;
use App\Models\Tenant;
use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 *   draft ──send──▶ sent ──mark paid──▶ paid
 *     │               │  (past due date: shown as "overdue")
 *     └──────void─────┴──▶ void
 *
 * BelongsToTenant scopes every query to the current business in its panel.
 */
class Invoice extends Model
{
    use BelongsToTenant;

    public const DRAFT = 'draft';

    public const SENT = 'sent';

    public const PAID = 'paid';

    public const VOID = 'void';

    protected $table = 'slotara_invoices';

    protected $guarded = ['id', 'public_token'];

    protected function casts(): array
    {
        return [
            'issue_date' => 'date',
            'due_date' => 'date',
            'paid_at' => 'datetime',
            'subtotal' => 'decimal:2',
            'tax_rate' => 'decimal:2',
            'tax_total' => 'decimal:2',
            'total' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $invoice) {
            $invoice->public_token ??= Str::random(40);
            $invoice->number ??= InvoiceSettings::for($invoice->tenant_id)->takeNumber();
        });
    }

    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class)->orderBy('sort')->orderBy('id');
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(SlotReservation::class, 'booking_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** Totals from the line items and tax rate. */
    public function recalculate(): static
    {
        $subtotal = round((float) $this->items()->sum('amount'), 2);
        $tax = round($subtotal * (float) $this->tax_rate / 100, 2);
        $this->forceFill(['subtotal' => $subtotal, 'tax_total' => $tax, 'total' => $subtotal + $tax])->saveQuietly();

        return $this;
    }

    public function isOverdue(): bool
    {
        return $this->status === self::SENT && $this->due_date !== null && $this->due_date->isBefore(today());
    }

    /** draft | sent | overdue | paid | void, for badges. */
    public function displayStatus(): string
    {
        return $this->isOverdue() ? 'overdue' : $this->status;
    }

    public function scopeOutstanding(Builder $query): Builder
    {
        return $query->where('status', self::SENT);
    }

    public function scopeOverdue(Builder $query): Builder
    {
        return $query->where('status', self::SENT)->whereDate('due_date', '<', today());
    }

    public function markPaid(): void
    {
        $this->forceFill(['status' => self::PAID, 'paid_at' => now()])->save();
    }

    public function publicUrl(): string
    {
        return route('slotara-invoices.view', $this->public_token);
    }

    public static function statusOptions(): array
    {
        return [self::DRAFT => __('Draft'), self::SENT => __('Sent'), self::PAID => __('Paid'), self::VOID => __('Void')];
    }

    public static function statusColor(string $status): string
    {
        return match ($status) {
            self::PAID => 'success',
            'overdue' => 'danger',
            self::SENT => 'info',
            self::VOID => 'gray',
            default => 'warning',
        };
    }
}
