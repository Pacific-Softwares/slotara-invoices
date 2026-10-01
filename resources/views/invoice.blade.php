@php
    $money = fn ($v) => \Modules\SlotaraInvoices\Filament\Resources\InvoiceResource::money((float) $v, $invoice->currency);
    $status = $invoice->displayStatus();
    $logo = $invoice->tenant?->logo ? \Illuminate\Support\Facades\Storage::disk('public')->url($invoice->tenant->logo) : null;
    $accent = $invoice->tenant?->booking_page_color ?: '#6d28d9';
@endphp
<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ __('Invoice') }} {{ $invoice->number }} · {{ $settings->business_name ?: $invoice->tenant?->name }}</title>
    <link rel="stylesheet" href="{{ asset('modules/slotara-invoices/invoice.css') }}">
    <style>:root{--accent: {{ $accent }};}</style>
</head>
<body>
<div class="bar no-print">
    <span>{{ __('Invoice') }} {{ $invoice->number }}</span>
    <button type="button" onclick="window.print()">{{ __('Print / Save as PDF') }}</button>
</div>

<main class="sheet">
    @if (in_array($status, ['paid', 'void', 'overdue'], true))
        <div class="stamp stamp-{{ $status }}">{{ $status === 'paid' ? __('Paid') : ($status === 'void' ? __('Void') : __('Overdue')) }}</div>
    @endif

    <header class="head">
        <div class="from">
            @if ($logo)<img src="{{ $logo }}" alt="" class="logo">@endif
            <h1>{{ $settings->business_name ?: $invoice->tenant?->name }}</h1>
            @if ($settings->business_details)<p class="muted pre">{{ $settings->business_details }}</p>@endif
        </div>
        <div class="meta">
            <p class="label">{{ __('Invoice') }}</p>
            <p class="number">{{ $invoice->number }}</p>
            <dl>
                <dt>{{ __('Issued') }}</dt><dd>{{ $invoice->issue_date->translatedFormat('M j, Y') }}</dd>
                @if ($invoice->due_date)<dt>{{ __('Due') }}</dt><dd>{{ $invoice->due_date->translatedFormat('M j, Y') }}</dd>@endif
                @if ($invoice->paid_at)<dt>{{ __('Paid') }}</dt><dd>{{ $invoice->paid_at->translatedFormat('M j, Y') }}</dd>@endif
            </dl>
        </div>
    </header>

    <section class="billto">
        <p class="label">{{ __('Bill to') }}</p>
        <p class="strong">{{ $invoice->customer_name }}</p>
        @if ($invoice->customer_email)<p class="muted">{{ $invoice->customer_email }}</p>@endif
        @if ($invoice->customer_details)<p class="muted pre">{{ $invoice->customer_details }}</p>@endif
    </section>

    <table class="items">
        <thead>
            <tr><th>{{ __('Description') }}</th><th class="num">{{ __('Qty') }}</th><th class="num">{{ __('Price') }}</th><th class="num">{{ __('Amount') }}</th></tr>
        </thead>
        <tbody>
            @foreach ($invoice->items as $item)
                <tr>
                    <td>{{ $item->description }}</td>
                    <td class="num">{{ rtrim(rtrim(number_format((float) $item->quantity, 2), '0'), '.') }}</td>
                    <td class="num">{{ $money($item->unit_price) }}</td>
                    <td class="num">{{ $money($item->amount) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div class="totals">
        <div><span>{{ __('Subtotal') }}</span><span>{{ $money($invoice->subtotal) }}</span></div>
        @if ((float) $invoice->tax_rate > 0)
            <div><span>{{ $invoice->tax_label }} ({{ rtrim(rtrim(number_format((float) $invoice->tax_rate, 2), '0'), '.') }}%)</span><span>{{ $money($invoice->tax_total) }}</span></div>
        @endif
        <div class="grand"><span>{{ $status === 'paid' ? __('Total paid') : __('Total due') }}</span><span>{{ $money($invoice->total) }}</span></div>
    </div>

    @if ($invoice->notes || ($settings->payment_instructions && $status !== 'paid'))
        <section class="notes">
            @if ($settings->payment_instructions && $status !== 'paid')
                <div><p class="label">{{ __('How to pay') }}</p><p class="pre">{{ $settings->payment_instructions }}</p></div>
            @endif
            @if ($invoice->notes)
                <div><p class="label">{{ __('Notes') }}</p><p class="pre">{{ $invoice->notes }}</p></div>
            @endif
        </section>
    @endif

    @if ($settings->footer)<footer class="foot pre">{{ $settings->footer }}</footer>@endif
</main>
</body>
</html>
