<?php

namespace Tests\Feature;

use App\Models\Provider;
use App\Models\Service;
use App\Models\SlotReservation;
use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
use Filament\Facades\Filament;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Modules\SlotaraInvoices\Filament\Pages\InvoiceSettingsPage;
use Modules\SlotaraInvoices\Filament\Resources\InvoiceResource;
use Modules\SlotaraInvoices\Filament\Resources\InvoiceResource\Pages\CreateInvoice;
use Modules\SlotaraInvoices\Filament\Resources\InvoiceResource\Pages\ListInvoices;
use Modules\SlotaraInvoices\Models\Invoice;
use Modules\SlotaraInvoices\Models\InvoiceSettings;
use Pacific\Licentra\Modules\Laravel\ModuleLoader;
use Tests\TestCase;

/**
 * Runs inside Slotara:  php artisan test ../slotara-invoices/tests/InvoicesModuleTest.php
 * The module is loaded in developer mode from a scratch folder (never your real modules-dev/).
 */
class InvoicesModuleTest extends TestCase
{
    use RefreshDatabase;

    private static string $root;

    private static array $env = [];

    public function createApplication()
    {
        self::$root = sys_get_temp_dir().'/slotara-invoices-test-'.getmypid();
        @mkdir(self::$root.'/modules-dev', 0777, true);
        @symlink(dirname(__DIR__), self::$root.'/modules-dev/slotara-invoices');
        self::$env = [
            'LICENTRA_MODULES_DEV' => 'true',
            'LICENTRA_MODULES_PATH' => self::$root.'/modules',
            'LICENTRA_MODULES_DEV_PATH' => self::$root.'/modules-dev',
            'LICENTRA_MODULES_PUBLIC_PATH' => self::$root.'/public-modules',
            'LICENTRA_MODULES_REGISTRY' => self::$root.'/licentra-modules.php',
        ];
        foreach (self::$env as $key => $value) {
            putenv("{$key}={$value}");
            $_ENV[$key] = $_SERVER[$key] = $value;
        }

        return parent::createApplication();
    }

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('module:migrate', ['slug' => 'slotara-invoices']);
    }

    protected function tearDown(): void
    {
        TenantContext::clear();
        parent::tearDown();
        foreach (array_keys(self::$env) as $key) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
        }
        putenv('LICENTRA_MODULES_DEV=false');
        $_ENV['LICENTRA_MODULES_DEV'] = $_SERVER['LICENTRA_MODULES_DEV'] = 'false';
        @unlink(self::$root.'/modules-dev/slotara-invoices');
        (new Filesystem)->deleteDirectory(self::$root);
    }

    private function business(string $currency = 'USD'): User
    {
        $plan = SubscriptionPlan::create(['name' => 'Pro', 'slug' => 'pro-'.uniqid(), 'is_active' => true]);
        $owner = User::forceCreate(['name' => 'Owner', 'email' => 'o'.uniqid().'@example.com', 'password' => bcrypt('password'), 'email_verified_at' => now()]);
        $tenant = Tenant::create(['name' => 'Clearpath Clinic', 'slug' => 'clearpath-'.uniqid(), 'status' => 'active', 'timezone' => 'UTC', 'currency' => $currency, 'owner_id' => $owner->id, 'plan_id' => $plan->id, 'address' => '1 Main St']);
        $owner->forceFill(['tenant_id' => $tenant->id])->save();

        return $owner->fresh();
    }

    private function booking(Tenant $tenant, array $attrs = []): SlotReservation
    {
        $service = Service::firstOrCreate(['tenant_id' => $tenant->id, 'name' => 'Checkup'], ['duration' => 30, 'price' => 50, 'status' => 'active']);
        $provider = Provider::firstOrCreate(['user_id' => $tenant->owner_id], ['tenant_id' => $tenant->id, 'status' => 'active']);

        return SlotReservation::create($attrs + [
            'tenant_id' => $tenant->id, 'service_id' => $service->id, 'provider_id' => $provider->id,
            'name' => 'Jordan Lee', 'email' => 'jordan@example.com', 'date' => now()->addDays(2)->toDateString(),
            'start_time' => '14:00:00', 'end_time' => '14:30:00', 'status' => 'confirmed', 'amount' => 50,
            'currency' => $tenant->currency, 'payment_status' => 'paid', 'cancellation_token' => (string) Str::uuid(),
        ]);
    }

    private function inPanel(User $user): void
    {
        Filament::setCurrentPanel(Filament::getPanel('tenant'));
        $this->actingAs($user);
        TenantContext::set($user->tenant);
    }

    public function test_module_loads_and_registers_its_panel_pages(): void
    {
        $this->assertTrue(app(ModuleLoader::class)->isLoaded('slotara-invoices'));
        $this->assertContains(InvoiceResource::class, Filament::getPanel('tenant')->getResources());
        $this->assertContains(InvoiceSettingsPage::class, Filament::getPanel('tenant')->getPages());
    }

    public function test_a_paid_booking_gets_a_paid_invoice_with_its_service_line(): void
    {
        $tenant = $this->business()->tenant;

        $booking = $this->booking($tenant);

        $invoice = Invoice::withoutGlobalScope('tenant')->where('booking_id', $booking->id)->firstOrFail();
        $this->assertSame(Invoice::PAID, $invoice->status);
        $this->assertSame('INV-0001', $invoice->number);
        $this->assertSame('50.00', $invoice->total);
        $this->assertStringStartsWith('Checkup', $invoice->items->first()->description);
    }

    public function test_an_unpaid_booking_gets_a_sent_invoice_that_is_marked_paid_when_the_booking_is(): void
    {
        $tenant = $this->business()->tenant;
        InvoiceSettings::for($tenant->id)->update(['due_days' => 7]);

        $booking = $this->booking($tenant, ['payment_status' => 'pending']);
        $invoice = Invoice::withoutGlobalScope('tenant')->where('booking_id', $booking->id)->firstOrFail();
        $this->assertSame(Invoice::SENT, $invoice->status);
        $this->assertTrue($invoice->due_date->isSameDay(today()->addDays(7)));

        $booking->update(['payment_status' => 'paid']);

        $this->assertSame(Invoice::PAID, $invoice->fresh()->status);
    }

    public function test_tax_is_taken_out_of_the_booking_price_and_numbers_increase(): void
    {
        $tenant = $this->business()->tenant;
        InvoiceSettings::for($tenant->id)->update(['tax_rate' => 25, 'tax_label' => 'VAT', 'prefix' => 'CC-']);

        $first = $this->booking($tenant, ['amount' => 125]);
        $second = $this->booking($tenant, ['amount' => 125, 'email' => 'sam@example.com']);

        $a = Invoice::withoutGlobalScope('tenant')->where('booking_id', $first->id)->first();
        $b = Invoice::withoutGlobalScope('tenant')->where('booking_id', $second->id)->first();
        $this->assertSame(['CC-0001', 'CC-0002'], [$a->number, $b->number]);
        $this->assertSame(['100.00', '25.00', '125.00'], [$a->subtotal, $a->tax_total, $a->total]);
    }

    public function test_businesses_can_turn_automatic_invoices_off(): void
    {
        $tenant = $this->business()->tenant;
        InvoiceSettings::for($tenant->id)->update(['auto_from_bookings' => false]);

        $booking = $this->booking($tenant);

        $this->assertFalse(Invoice::withoutGlobalScope('tenant')->where('booking_id', $booking->id)->exists());
    }

    public function test_a_business_sees_only_its_own_invoices(): void
    {
        $this->booking($this->business()->tenant, ['name' => 'Someone Else']);
        $owner = $this->business();
        $this->booking($owner->tenant, ['name' => 'My Customer']);
        $this->inPanel($owner);

        Livewire::test(ListInvoices::class)
            ->assertOk()
            ->assertSee('My Customer')
            ->assertDontSee('Someone Else');
    }

    public function test_creating_an_invoice_by_hand_totals_the_items(): void
    {
        $owner = $this->business();
        $this->inPanel($owner);

        Livewire::test(CreateInvoice::class)
            ->fillForm([
                'customer_name' => 'Acme Ltd',
                'customer_email' => 'billing@acme.test',
                'issue_date' => today()->toDateString(),
                'due_date' => today()->addDays(14)->toDateString(),
                'status' => Invoice::SENT,
                'currency' => 'USD',
                'tax_label' => 'Tax',
                'tax_rate' => 10,
                'items' => [
                    ['description' => 'Consultation', 'quantity' => 2, 'unit_price' => 40],
                    ['description' => 'Report', 'quantity' => 1, 'unit_price' => 20],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $invoice = Invoice::where('customer_name', 'Acme Ltd')->firstOrFail();
        $this->assertSame($owner->tenant_id, $invoice->tenant_id);
        $this->assertSame(['100.00', '10.00', '110.00'], [$invoice->subtotal, $invoice->tax_total, $invoice->total]);
    }

    public function test_the_customer_link_shows_a_print_ready_invoice(): void
    {
        $tenant = $this->business()->tenant;
        $invoice = Invoice::withoutGlobalScope('tenant')->where('booking_id', $this->booking($tenant)->id)->first();

        $this->get($invoice->publicUrl())
            ->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertSee($invoice->number)
            ->assertSee('Jordan Lee')
            ->assertSee('Print / Save as PDF');
    }

    public function test_a_guessed_link_finds_nothing(): void
    {
        $this->get('/slotara-invoices/view/'.str_repeat('a', 40))->assertNotFound();
        $this->get('/slotara-invoices/view/short')->assertNotFound();
    }

    public function test_settings_page_saves_and_shows_the_next_number(): void
    {
        $owner = $this->business();
        $this->inPanel($owner);

        Livewire::test(InvoiceSettingsPage::class)
            ->assertSee('INV-0001')
            ->fillForm(['business_name' => 'Clearpath', 'prefix' => '2026-', 'tax_label' => 'GST', 'tax_rate' => 18, 'due_days' => 30, 'auto_from_bookings' => true])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('2026-0001', InvoiceSettings::for($owner->tenant_id)->nextNumberPreview());
    }

    public function test_the_edit_page_marks_an_invoice_paid(): void
    {
        $owner = $this->business();
        $invoice = Invoice::withoutGlobalScope('tenant')->where('booking_id', $this->booking($owner->tenant, ['payment_status' => 'pending'])->id)->first();
        $this->inPanel($owner);

        Livewire::test(\Modules\SlotaraInvoices\Filament\Resources\InvoiceResource\Pages\EditInvoice::class, ['record' => $invoice->getRouteKey()])
            ->assertOk()
            ->assertSee($invoice->number)
            ->callAction('markPaid')
            ->assertHasNoActionErrors();

        $this->assertSame(Invoice::PAID, $invoice->fresh()->status);
        $this->assertNotNull($invoice->fresh()->paid_at);
    }

    public function test_another_business_cannot_open_an_invoice_in_its_panel(): void
    {
        $invoice = Invoice::withoutGlobalScope('tenant')->where('booking_id', $this->booking($this->business()->tenant)->id)->first();
        $this->inPanel($this->business());

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        Livewire::test(\Modules\SlotaraInvoices\Filament\Resources\InvoiceResource\Pages\EditInvoice::class, ['record' => $invoice->getRouteKey()]);
    }
}
