<?php

namespace Modules\SlotaraInvoices\Filament\Pages;

use App\Support\TenantContext;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Modules\SlotaraInvoices\Filament\Resources\InvoiceResource;
use Modules\SlotaraInvoices\Models\InvoiceSettings;
use Pacific\Licentra\Modules\Laravel\GatedByModule;

class InvoiceSettingsPage extends Page implements HasForms
{
    use GatedByModule;
    use InteractsWithForms;

    protected static string $module = 'slotara-invoices';

    protected static ?string $slug = 'invoices/settings';

    protected static bool $shouldRegisterNavigation = false; // reached from Invoices → Settings

    protected string $view = 'slotara-invoices::filament.settings';

    public ?array $data = [];

    public function getTitle(): string
    {
        return __('Invoice settings');
    }

    public function getBreadcrumbs(): array
    {
        return [InvoiceResource::getUrl() => __('Invoices'), __('Settings')];
    }

    public function mount(): void
    {
        $this->form->fill($this->settings()->toArray());
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            Section::make(__('Your business on invoices'))
                ->description(__('Shown at the top of every invoice.'))
                ->icon('heroicon-o-building-storefront')
                ->columns(2)
                ->schema([
                    TextInput::make('business_name')->label(__('Business name'))->maxLength(255),
                    Textarea::make('business_details')->label(__('Address, tax number, contact'))->rows(4)
                        ->placeholder(__("12 Market Street, Springfield\nVAT: GB123456789\nbilling@yourbusiness.com")),
                ]),
            Section::make(__('Numbers, tax and terms'))
                ->icon('heroicon-o-hashtag')
                ->columns(3)
                ->schema([
                    TextInput::make('prefix')->label(__('Number prefix'))->maxLength(20)->required()
                        ->helperText(fn () => __('Next invoice: :n', ['n' => $this->settings()->nextNumberPreview()])),
                    TextInput::make('tax_label')->label(__('Tax name'))->maxLength(30)->required()->placeholder('VAT, GST, Sales tax'),
                    TextInput::make('tax_rate')->label(__('Default tax rate'))->numeric()->minValue(0)->maxValue(100)->suffix('%')->required(),
                    TextInput::make('due_days')->label(__('Payment due after'))->numeric()->minValue(0)->maxValue(365)->suffix(__('days'))->required(),
                    Toggle::make('auto_from_bookings')->label(__('Invoice new bookings automatically'))
                        ->helperText(__('Paid online bookings are marked paid; others are due after the days above.'))
                        ->columnSpan(2)->inline(false),
                ]),
            Section::make(__('Bottom of the invoice'))
                ->icon('heroicon-o-banknotes')
                ->columns(2)
                ->schema([
                    Textarea::make('payment_instructions')->label(__('How to pay'))->rows(3)
                        ->placeholder(__("Bank transfer to: Your Business Ltd\nIBAN GB00 0000 0000 0000\nReference: the invoice number")),
                    Textarea::make('footer')->label(__('Footer'))->rows(3)->placeholder(__('Thank you for choosing us!')),
                ]),
        ]);
    }

    public function save(): void
    {
        $this->settings()->update($this->form->getState());
        Notification::make()->success()->title(__('Invoice settings saved'))->send();
    }

    private function settings(): InvoiceSettings
    {
        return InvoiceSettings::for(TenantContext::id() ?? auth()->user()->tenant_id);
    }
}
