<?php

namespace Modules\SlotaraInvoices\Filament\Resources;

use App\Support\TenantContext;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;
use Modules\SlotaraInvoices\Filament\Pages\InvoiceSettingsPage;
use Modules\SlotaraInvoices\Models\Invoice;
use Modules\SlotaraInvoices\Models\InvoiceSettings;
use Pacific\Licentra\Modules\Laravel\GatedByModule;

class InvoiceResource extends Resource
{
    use GatedByModule;

    protected static string $module = 'slotara-invoices';

    protected static ?string $model = Invoice::class;

    protected static ?string $slug = 'slotara-invoices/invoices';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-document-text';

    protected static ?int $navigationSort = 30;

    protected static ?string $recordTitleAttribute = 'number';

    public static function getModelLabel(): string
    {
        return __('Invoice');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Invoices');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Payments');
    }

    /** Outstanding invoices, shown next to the menu item. */
    public static function getNavigationBadge(): ?string
    {
        $count = Invoice::query()->outstanding()->count();

        return $count ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return Invoice::query()->overdue()->exists() ? 'danger' : 'info';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return __('Sent, not paid yet');
    }

    public static function canViewAny(): bool
    {
        return auth()->check() && ! auth()->user()->hasRole('staff');
    }

    // ── Form ────────────────────────────────────────────────────────────────

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(3)->columnSpanFull()->schema([
                Section::make(__('Bill to'))
                    ->icon('heroicon-o-user')
                    ->columnSpan(2)
                    ->columns(2)
                    ->schema([
                        TextInput::make('customer_name')->label(__('Name'))->required()->maxLength(255),
                        TextInput::make('customer_email')->label(__('Email'))->email()->maxLength(255)
                            ->helperText(__('Used to share the invoice.')),
                        Textarea::make('customer_details')->label(__('Address & details'))->rows(2)->columnSpanFull()
                            ->placeholder(__("Street, city\nTax / VAT number")),
                    ]),

                Section::make(__('Details'))
                    ->icon('heroicon-o-document-text')
                    ->columnSpan(1)
                    ->schema([
                        Placeholder::make('number_preview')
                            ->label(__('Invoice number'))
                            ->content(fn (?Invoice $record) => $record?->number ?? InvoiceSettings::for(TenantContext::id())->nextNumberPreview().' '.__('(on save)')),
                        DatePicker::make('issue_date')->label(__('Issued'))->required()->default(today())->native(false),
                        DatePicker::make('due_date')->label(__('Due'))->native(false)
                            ->default(fn () => today()->addDays(InvoiceSettings::for(TenantContext::id())->due_days))
                            ->afterOrEqual('issue_date'),
                        Select::make('status')->label(__('Status'))->options(Invoice::statusOptions())->default(Invoice::DRAFT)->required()->native(false)->selectablePlaceholder(false),
                        TextInput::make('currency')->label(__('Currency'))->required()->maxLength(3)
                            ->default(fn () => TenantContext::current()?->currency ?? 'USD')
                            ->dehydrateStateUsing(fn ($state) => strtoupper((string) $state))
                            ->live(debounce: 400),
                    ]),
            ]),

            Section::make(__('Items'))
                ->icon('heroicon-o-list-bullet')
                ->columnSpanFull()
                ->schema([
                    Repeater::make('items')
                        ->relationship()
                        ->hiddenLabel()
                        ->orderColumn('sort')
                        ->defaultItems(1)
                        ->minItems(1)
                        ->columns(12)
                        ->addActionLabel(__('Add item'))
                        ->live(debounce: 400)
                        ->schema([
                            TextInput::make('description')->label(__('Description'))->required()->maxLength(255)->columnSpan(['default' => 12, 'md' => 6])
                                ->placeholder(__('e.g. Consultation, 60 min')),
                            TextInput::make('quantity')->label(__('Qty'))->numeric()->minValue(0.01)->default(1)->required()->columnSpan(['default' => 4, 'md' => 2]),
                            TextInput::make('unit_price')->label(fn (Get $get) => __('Price').' ('.($get('../../currency') ?: 'USD').')')->numeric()->minValue(0)->default(0)->required()->columnSpan(['default' => 4, 'md' => 2]),
                            Placeholder::make('line_total')->label(__('Amount'))->columnSpan(['default' => 4, 'md' => 2])
                                ->content(fn (Get $get) => self::money((float) $get('quantity') * (float) $get('unit_price'), $get('../../currency'))),
                        ]),
                ]),

            Grid::make(5)->columnSpanFull()->schema([
                Section::make(__('Notes'))
                    ->icon('heroicon-o-chat-bubble-bottom-center-text')
                    ->columnSpan(['default' => 5, 'lg' => 3])
                    ->schema([
                        Textarea::make('notes')->hiddenLabel()->rows(4)
                            ->placeholder(__('Thank you for your business! Shown on the invoice.')),
                    ]),

                Section::make(__('Total'))
                    ->icon('heroicon-o-calculator')
                    ->columnSpan(['default' => 5, 'lg' => 2])
                    ->schema([
                        Grid::make(2)->schema([
                            TextInput::make('tax_label')->label(__('Tax name'))->default(fn () => InvoiceSettings::for(TenantContext::id())->tax_label)->maxLength(30),
                            TextInput::make('tax_rate')->label(__('Rate %'))->numeric()->minValue(0)->maxValue(100)
                                ->default(fn () => (float) InvoiceSettings::for(TenantContext::id())->tax_rate)->live(debounce: 400),
                        ]),
                        Placeholder::make('totals')->hiddenLabel()->content(fn (Get $get) => self::totalsHtml($get)),
                    ]),
            ]),
        ]);
    }

    private static function totalsHtml(Get $get): HtmlString
    {
        $subtotal = collect($get('items') ?? [])->sum(fn ($i) => (float) ($i['quantity'] ?? 0) * (float) ($i['unit_price'] ?? 0));
        $tax = round($subtotal * (float) $get('tax_rate') / 100, 2);
        $c = $get('currency');
        $row = fn ($label, $value, $strong = false) => '<div style="display:flex;justify-content:space-between;padding:.2rem 0;'.($strong ? 'font-weight:700;font-size:1.05rem;border-top:1px solid rgb(229 231 235 / .6);margin-top:.35rem;padding-top:.5rem' : 'color:rgb(107 114 128)').'"><span>'.e($label).'</span><span>'.e($value).'</span></div>';

        return new HtmlString(
            $row(__('Subtotal'), self::money($subtotal, $c))
            .$row(($get('tax_label') ?: __('Tax')).' ('.((float) $get('tax_rate')).'%)', self::money($tax, $c))
            .$row(__('Total'), self::money($subtotal + $tax, $c), true)
        );
    }

    public static function money(float $amount, ?string $currency): string
    {
        $currency = strtoupper($currency ?: 'USD');
        try {
            return (new \NumberFormatter(app()->getLocale(), \NumberFormatter::CURRENCY))->formatCurrency($amount, $currency) ?: "{$currency} ".number_format($amount, 2);
        } catch (\Throwable) {
            return "{$currency} ".number_format($amount, 2);
        }
    }

    // ── Table ───────────────────────────────────────────────────────────────

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('number')->label(__('Invoice'))->searchable()->sortable()->weight('bold')
                    ->description(fn (Invoice $r) => $r->booking_id ? __('From a booking') : null),
                TextColumn::make('customer_name')->label(__('Customer'))->searchable(['customer_name', 'customer_email'])
                    ->description(fn (Invoice $r) => $r->customer_email),
                TextColumn::make('issue_date')->label(__('Issued'))->date()->sortable(),
                TextColumn::make('due_date')->label(__('Due'))->date()->sortable()
                    ->color(fn (Invoice $r) => $r->isOverdue() ? 'danger' : null)
                    ->description(fn (Invoice $r) => $r->isOverdue() ? __(':n days late', ['n' => $r->due_date->diffInDays(today())]) : null),
                TextColumn::make('total')->label(__('Total'))->sortable()->alignEnd()
                    ->formatStateUsing(fn ($state, Invoice $r) => self::money((float) $state, $r->currency)),
                TextColumn::make('status')->label(__('Status'))->badge()
                    ->state(fn (Invoice $r) => $r->displayStatus())
                    ->formatStateUsing(fn (string $state) => $state === 'overdue' ? __('Overdue') : (Invoice::statusOptions()[$state] ?? $state))
                    ->color(fn (string $state) => Invoice::statusColor($state)),
            ])
            ->defaultSort('issue_date', 'desc')
            ->filters([
                SelectFilter::make('status')->label(__('Status'))->options(Invoice::statusOptions()),
                Filter::make('overdue')->label(__('Overdue only'))->toggle()->query(fn (Builder $query) => $query->overdue()),
            ])
            ->recordUrl(fn (Invoice $r) => static::getUrl('edit', ['record' => $r]))
            ->recordActions([
                ActionGroup::make([
                    self::openAction(),
                    self::markPaidAction(),
                    self::markSentAction(),
                    self::duplicateAction(),
                    self::voidAction(),
                    EditAction::make(),
                    DeleteAction::make()->visible(fn (Invoice $r) => in_array($r->status, [Invoice::DRAFT, Invoice::VOID], true)),
                ]),
            ])
            ->emptyStateIcon('heroicon-o-document-text')
            ->emptyStateHeading(__('No invoices yet'))
            ->emptyStateDescription(__('New bookings get an invoice automatically. You can also create one yourself.'))
            ->emptyStateActions([
                Action::make('create')->label(__('New invoice'))->icon('heroicon-o-plus')->url(static::getUrl('create')),
                Action::make('settings')->label(__('Invoice settings'))->color('gray')->url(InvoiceSettingsPage::getUrl()),
            ]);
    }

    // ── Actions (table rows and the edit page) ──────────────────────────────

    public static function openAction(): Action
    {
        return Action::make('open')
            ->label(__('Customer view'))
            ->icon('heroicon-o-arrow-top-right-on-square')
            ->url(fn (Invoice $record) => $record->publicUrl())
            ->openUrlInNewTab()
            ->visible(fn (Invoice $record) => $record->exists);
    }

    public static function markPaidAction(): Action
    {
        return Action::make('markPaid')
            ->label(__('Mark as paid'))
            ->icon('heroicon-o-check-circle')
            ->color('success')
            ->requiresConfirmation()
            ->modalDescription(fn (Invoice $record) => __(':number for :total will be marked paid today.', ['number' => $record->number, 'total' => self::money((float) $record->total, $record->currency)]))
            ->visible(fn (Invoice $record) => in_array($record->status, [Invoice::DRAFT, Invoice::SENT], true))
            ->action(function (Invoice $record) {
                $record->markPaid();
                Notification::make()->success()->title(__(':number marked as paid', ['number' => $record->number]))->send();
            });
    }

    public static function markSentAction(): Action
    {
        return Action::make('markSent')
            ->label(__('Mark as sent'))
            ->icon('heroicon-o-paper-airplane')
            ->color('info')
            ->visible(fn (Invoice $record) => $record->status === Invoice::DRAFT)
            ->action(function (Invoice $record) {
                $record->update(['status' => Invoice::SENT]);
                Notification::make()->success()->title(__(':number is ready to share', ['number' => $record->number]))
                    ->body(__('Send the customer this link: :url', ['url' => $record->publicUrl()]))->persistent()->send();
            });
    }

    public static function duplicateAction(): Action
    {
        return Action::make('duplicate')
            ->label(__('Duplicate'))
            ->icon('heroicon-o-document-duplicate')
            ->color('gray')
            ->action(function (Invoice $record, $livewire) {
                $copy = $record->replicate(['number', 'public_token', 'paid_at', 'booking_id']);
                $copy->fill(['status' => Invoice::DRAFT, 'issue_date' => today(), 'due_date' => today()->addDays(InvoiceSettings::for($record->tenant_id)->due_days)]);
                $copy->number = null;
                $copy->save();
                foreach ($record->items as $item) {
                    $copy->items()->create($item->only(['description', 'quantity', 'unit_price', 'sort']));
                }
                $copy->recalculate();
                $livewire->redirect(static::getUrl('edit', ['record' => $copy]));
            });
    }

    public static function voidAction(): Action
    {
        return Action::make('void')
            ->label(__('Void'))
            ->icon('heroicon-o-no-symbol')
            ->color('danger')
            ->requiresConfirmation()
            ->modalDescription(__('A void invoice stays in your records but is no longer owed.'))
            ->visible(fn (Invoice $record) => in_array($record->status, [Invoice::DRAFT, Invoice::SENT], true))
            ->action(fn (Invoice $record) => $record->update(['status' => Invoice::VOID]));
    }

    public static function getWidgets(): array
    {
        return [\Modules\SlotaraInvoices\Filament\Widgets\InvoiceStats::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => InvoiceResource\Pages\ListInvoices::route('/'),
            'create' => InvoiceResource\Pages\CreateInvoice::route('/create'),
            'edit' => InvoiceResource\Pages\EditInvoice::route('/{record}/edit'),
        ];
    }
}
