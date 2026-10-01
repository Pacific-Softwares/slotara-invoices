<?php

namespace Modules\SlotaraInvoices\Filament\Widgets;

use App\Support\TenantContext;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Modules\SlotaraInvoices\Filament\Resources\InvoiceResource;
use Modules\SlotaraInvoices\Models\Invoice;

/** What's owed, what's late and what came in this month, in the business's currency. */
class InvoiceStats extends StatsOverviewWidget
{
    protected ?string $pollingInterval = null;

    protected static bool $isLazy = false; // no empty box while the page loads

    protected function getStats(): array
    {
        $currency = TenantContext::current()?->currency ?? 'USD';
        $money = fn ($v) => InvoiceResource::money((float) $v, $currency);
        $outstanding = Invoice::query()->outstanding();
        $overdue = Invoice::query()->overdue();
        $paidThisMonth = Invoice::query()->where('status', Invoice::PAID)->where('paid_at', '>=', now()->startOfMonth());

        return [
            Stat::make(__('Outstanding'), $money((clone $outstanding)->sum('total')))
                ->description(trans_choice(':count invoice waiting|:count invoices waiting', (clone $outstanding)->count()))
                ->icon('heroicon-o-clock')
                ->color('info'),
            Stat::make(__('Overdue'), $money((clone $overdue)->sum('total')))
                ->description(trans_choice(':count invoice past due|:count invoices past due', (clone $overdue)->count()))
                ->icon('heroicon-o-exclamation-triangle')
                ->color((clone $overdue)->exists() ? 'danger' : 'gray'),
            Stat::make(__('Paid this month'), $money((clone $paidThisMonth)->sum('total')))
                ->description(trans_choice(':count invoice|:count invoices', (clone $paidThisMonth)->count()))
                ->icon('heroicon-o-banknotes')
                ->color('success'),
        ];
    }
}
