<?php

namespace Modules\SlotaraInvoices\Models;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/** A business's invoice settings. Created with sensible defaults the first time it's needed. */
class InvoiceSettings extends Model
{
    protected $table = 'slotara_invoices_settings';

    // tenant_id is set only by for(); the settings form never sends it.
    protected $guarded = ['id', 'next_number'];

    protected function casts(): array
    {
        return ['tax_rate' => 'decimal:2', 'auto_from_bookings' => 'boolean'];
    }

    public static function for(int $tenantId): self
    {
        $settings = static::firstOrCreate(['tenant_id' => $tenantId], function () use ($tenantId) {
            $tenant = Tenant::find($tenantId);

            return [
                'business_name' => $tenant?->name,
                'business_details' => collect([$tenant?->address, trim(($tenant?->city ?? '').' '.($tenant?->country ?? '')), $tenant?->email, $tenant?->phone])
                    ->filter()->implode("\n") ?: null,
            ];
        });

        // A new row only has what we inserted; load the column defaults (auto invoicing on, 14 days, ...).
        return $settings->wasRecentlyCreated ? $settings->refresh() : $settings;
    }

    /** Reserve the next invoice number (INV-0001, ...). Safe when two invoices are made at once. */
    public function takeNumber(): string
    {
        return DB::transaction(function () {
            $row = static::whereKey($this->getKey())->lockForUpdate()->first();
            $number = $row->prefix.str_pad((string) $row->next_number, 4, '0', STR_PAD_LEFT);
            $row->forceFill(['next_number' => $row->next_number + 1])->save();

            return $number;
        });
    }

    /** Preview of the next number, for the settings page. */
    public function nextNumberPreview(): string
    {
        return $this->prefix.str_pad((string) $this->next_number, 4, '0', STR_PAD_LEFT);
    }
}
