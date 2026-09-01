<?php

namespace Tests\Feature;

use App\Helpers\Money;
use App\Models\Account;
use App\Models\Branch;
use App\Models\JournalEntry;
use App\Models\Role;
use App\Models\Setting;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Accounting\AccountingService;
use App\Services\SalesTaxService;
use App\Services\SupplierInvoiceService;
use App\Support\BranchContext;
use App\Support\TaxConfiguration;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TaxConfigurationFlexibilityTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;

    protected User $accountant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::create([
            'code' => 'tax-flex',
            'name' => 'Tax Flex',
            'is_active' => true,
        ]);
        BranchContext::set($this->branch->id);

        Role::firstOrCreate(['name' => 'accountant'], [
            'label' => 'Accountant',
            'is_system' => true,
        ]);
        $this->seed(PermissionSeeder::class);

        $this->accountant = User::create([
            'name' => 'Restaurant Accountant',
            'username' => 'tax-flex-accountant',
            'role' => 'accountant',
            'status' => 'active',
            'password' => bcrypt('password'),
        ]);
        $this->accountant->branches()->attach($this->branch->id, [
            'is_primary' => true,
            'joined_at' => now(),
        ]);
    }

    protected function tearDown(): void
    {
        BranchContext::clear();
        parent::tearDown();
    }

    public function test_accountant_can_disable_tax_then_schedule_its_return_without_early_effect(): void
    {
        $today = now()->toDateString();
        $future = now()->addMonths(3)->toDateString();

        $this->actingAs($this->accountant)
            ->withSession(['active_branch_id' => $this->branch->id])
            ->post(route('admin.accounting.tax-configuration.store'), [
                'tax_enabled' => 0,
                'tax_rate' => 16,
                'tax_effective_from' => $today,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertFalse(TaxConfiguration::switchIsOn());
        $this->assertSame(0.0, app(SalesTaxService::class)->rateForBranch($this->branch->id, $today));
        $this->assertSame(['tax' => 0.0, 'rate' => 0.0], Money::applyTax(100));

        Setting::put('service_enabled', true, 'billing', 'bool');
        Setting::put('service_rate', 16, 'billing', 'float');
        $this->assertSame(['service' => 16.0, 'rate' => 16.0], Money::applyService(100));

        $this->actingAs($this->accountant)
            ->withSession(['active_branch_id' => $this->branch->id])
            ->get(route('admin.accounts.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Accounts/Index')
                ->where('sections', fn ($sections) => $this->visibleAccountCodes($sections)
                    ->contains(AccountingService::INPUT_VAT)
                    && ! $this->visibleAccountCodes($sections)->contains(AccountingService::OUTPUT_VAT))
            );

        $this->actingAs($this->accountant)
            ->withSession(['active_branch_id' => $this->branch->id])
            ->post(route('admin.accounting.tax-configuration.store'), [
                'tax_enabled' => 1,
                'tax_rate' => 16,
                'tax_effective_from' => $future,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertFalse(TaxConfiguration::switchIsOn());
        $this->assertFalse(TaxConfiguration::isEnabled($today));
        $this->assertTrue(TaxConfiguration::isEnabled($future));
        $this->assertSame(0.0, app(SalesTaxService::class)->rateForBranch($this->branch->id, $today));
        $this->assertSame(16.0, app(SalesTaxService::class)->rateForBranch($this->branch->id, $future));

        $this->actingAs($this->accountant)
            ->withSession(['active_branch_id' => $this->branch->id])
            ->get(route('admin.accounting.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Accounting/Index')
                ->where('tax.nextSchedule.date', $future)
                ->where('tax.nextSchedule.enabled', true)
            );
    }

    public function test_disabling_tax_preserves_history_but_blocks_new_tax_postings(): void
    {
        Setting::put('tax_enabled', true, 'billing', 'bool');
        Setting::put('tax_rate', 16, 'billing', 'float');
        Setting::put('tax_effective_from', now()->subMonth()->toDateString(), 'billing', 'string');

        app(AccountingService::class)->post(
            eventType: 'historical_tax_probe',
            source: null,
            branchId: $this->branch->id,
            postedOn: now()->subDay()->toDateString(),
            description: 'حركة ضريبية تاريخية',
            lines: [
                ['account' => AccountingService::ACCOUNTS_RECEIVABLE, 'debit' => 16, 'credit' => 0],
                ['account' => AccountingService::OUTPUT_VAT, 'debit' => 0, 'credit' => 16],
            ],
            createdBy: $this->accountant->id,
        );

        $this->actingAs($this->accountant)
            ->withSession(['active_branch_id' => $this->branch->id])
            ->post(route('admin.accounting.tax-configuration.store'), [
                'tax_enabled' => 0,
                'tax_rate' => 16,
                'tax_effective_from' => now()->toDateString(),
            ])
            ->assertSessionHas('success');

        $this->assertTrue(TaxConfiguration::hasHistory());
        $this->assertSame(1, JournalEntry::where('event_type', 'historical_tax_probe')->count());

        $this->actingAs($this->accountant)
            ->withSession(['active_branch_id' => $this->branch->id])
            ->get(route('admin.accounts.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Accounts/Index')
                ->where('sections', fn ($sections) => ! $this->visibleAccountCodes($sections)
                    ->contains(AccountingService::OUTPUT_VAT)
                    && $this->visibleAccountCodes($sections)->contains(AccountingService::INPUT_VAT))
            );

        $this->actingAs($this->accountant)
            ->withSession(['active_branch_id' => $this->branch->id])
            ->get(route('admin.accounting.tax-report'))
            ->assertOk();

        $receivables = Account::where('code', AccountingService::ACCOUNTS_RECEIVABLE)->firstOrFail();
        $outputTax = Account::where('code', AccountingService::OUTPUT_VAT)->firstOrFail();

        $this->actingAs($this->accountant)
            ->withSession(['active_branch_id' => $this->branch->id])
            ->post(route('admin.accounting.manual-entry.store'), [
                'posted_on' => now()->toDateString(),
                'description' => 'يجب منع قيد ضريبي جديد',
                'lines' => [
                    ['account_id' => $receivables->id, 'debit' => 10, 'credit' => 0],
                    ['account_id' => $outputTax->id, 'debit' => 0, 'credit' => 10],
                ],
            ])
            ->assertSessionHas('error');

        $this->assertSame(0, JournalEntry::where('event_type', 'manual_journal')->count());
        $this->assertSame(1, JournalEntry::where('event_type', 'historical_tax_probe')->count());
    }

    public function test_customer_tax_defaults_to_off_and_supports_multiple_effective_rates(): void
    {
        $this->assertFalse(TaxConfiguration::isEnabled());
        $this->assertSame(0.0, app(SalesTaxService::class)->rateForBranch($this->branch->id));

        $first = now()->addMonth()->startOfMonth()->toDateString();
        $second = now()->addYear()->startOfYear()->toDateString();

        foreach ([[$first, 5.5], [$second, 7.25]] as [$date, $rate]) {
            $this->actingAs($this->accountant)
                ->withSession(['active_branch_id' => $this->branch->id])
                ->post(route('admin.accounting.tax-configuration.store'), [
                    'tax_enabled' => 1,
                    'tax_rate' => $rate,
                    'tax_effective_from' => $date,
                ])
                ->assertSessionHas('success');
        }

        $this->assertSame(0.0, app(SalesTaxService::class)->rateForBranch($this->branch->id, now()->toDateString()));
        $this->assertSame(5.5, app(SalesTaxService::class)->rateForBranch($this->branch->id, $first));
        $this->assertSame(7.25, app(SalesTaxService::class)->rateForBranch($this->branch->id, $second));
    }

    public function test_supplier_invoice_tax_is_independent_from_customer_invoice_tax_switch(): void
    {
        Setting::put('tax_enabled', false, 'billing', 'bool');
        $supplier = Supplier::create(['name' => 'Independent Tax Supplier', 'active' => true]);
        $supplier->branches()->attach($this->branch->id);

        $invoice = app(SupplierInvoiceService::class)->create([
            'number' => 'SUP-TAX-001',
            'supplier_id' => $supplier->id,
            'invoice_date' => now()->toDateString(),
            'subtotal' => 100,
            'tax_total' => 16,
            'total' => 116,
        ], $this->accountant->id);

        $this->assertSame('16.0000', $invoice->tax_total);
        $entry = JournalEntry::where('event_type', 'supplier_invoice_created')->latest('id')->firstOrFail();
        $this->assertEqualsWithDelta(
            16,
            (float) $entry->lines()->whereHas('account', fn ($query) => $query->where('code', AccountingService::INPUT_VAT))->value('debit'),
            0.01,
        );
        $this->assertFalse(TaxConfiguration::isEnabled());
    }

    private function visibleAccountCodes(iterable $sections): \Illuminate\Support\Collection
    {
        $codes = collect();
        $walk = function (iterable $nodes) use (&$walk, $codes): void {
            foreach ($nodes as $node) {
                $codes->push((string) $node['code']);
                $walk($node['children'] ?? []);
            }
        };

        foreach ($sections as $section) {
            $walk($section['nodes'] ?? []);
        }

        return $codes;
    }
}
