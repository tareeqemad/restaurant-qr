<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Branch;
use App\Models\FixedAsset;
use App\Models\FixedAssetDepreciation;
use App\Models\JournalEntry;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Services\Accounting\AccountingService;
use App\Support\BranchContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FixedAssetAccountingTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;
    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::create(['code' => 'fixed-assets', 'name' => 'Fixed Assets', 'is_active' => true]);
        BranchContext::set($this->branch->id);

        Role::firstOrCreate(['name' => 'admin'], ['label' => 'Admin', 'is_system' => true]);
        $this->seed(\Database\Seeders\PermissionSeeder::class);

        $this->admin = User::create([
            'name' => 'Asset Admin',
            'username' => 'asset-admin',
            'role' => 'admin',
            'status' => 'active',
            'password' => bcrypt('password'),
        ]);
        $this->admin->branches()->attach($this->branch->id, ['is_primary' => true, 'joined_at' => now()]);

        Setting::put('accounting_base_currency', 'USD', 'accounting', 'string');
        Setting::put('accounting_currency_symbol', '$', 'accounting', 'string');
    }

    protected function tearDown(): void
    {
        BranchContext::clear();
        parent::tearDown();
    }

    public function test_fixed_asset_purchase_and_monthly_depreciation_post_journal_entries(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.accounting.fixed-assets.store'), [
                'asset_number' => 'FA-TEST-001',
                'name' => 'Pizza Oven',
                'category' => 'Equipment',
                'acquisition_date' => '2026-05-01',
                'in_service_date' => '2026-05-01',
                'foreign_cost' => 12000,
                'foreign_salvage_value' => 0,
                'currency_code' => 'USD',
                'exchange_rate' => 1,
                'useful_life_months' => 12,
                'payment_method' => 'bank_transfer',
            ])
            ->assertRedirect();

        $asset = FixedAsset::where('asset_number', 'FA-TEST-001')->firstOrFail();
        $this->assertEqualsWithDelta(12000, (float) $asset->cost, 0.01);
        $this->assertNotNull($asset->purchase_journal_entry_id);

        $purchase = JournalEntry::where('event_type', 'fixed_asset_acquired')->with('lines.account')->firstOrFail();
        $this->assertEntryAccountTotals($purchase, AccountingService::FIXED_ASSETS, 12000, 0);
        $this->assertEntryAccountTotals($purchase, AccountingService::BANK, 0, 12000);

        $this->actingAs($this->admin)
            ->post(route('admin.accounting.fixed-assets.depreciation', $asset), [
                'period_month' => '2026-05',
                'posted_on' => '2026-05-31',
            ])
            ->assertRedirect(route('admin.accounting.fixed-assets.show', $asset));

        $asset->refresh();
        $this->assertEqualsWithDelta(1000, (float) $asset->accumulated_depreciation, 0.01);
        $this->assertSame('active', $asset->status);

        $depreciation = JournalEntry::where('event_type', 'fixed_asset_depreciation')->with('lines.account')->firstOrFail();
        $this->assertEntryAccountTotals($depreciation, AccountingService::DEPRECIATION_EXPENSE, 1000, 0);
        $this->assertEntryAccountTotals($depreciation, AccountingService::ACCUMULATED_DEPRECIATION, 0, 1000);
    }

    public function test_fixed_asset_disposal_posts_gain_or_loss_against_book_value(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.accounting.fixed-assets.store'), [
                'asset_number' => 'FA-TEST-002',
                'name' => 'Delivery Bike',
                'category' => 'Vehicles',
                'acquisition_date' => '2026-05-01',
                'in_service_date' => '2026-05-01',
                'foreign_cost' => 12000,
                'foreign_salvage_value' => 0,
                'currency_code' => 'USD',
                'exchange_rate' => 1,
                'useful_life_months' => 12,
                'payment_method' => 'bank_transfer',
            ])
            ->assertRedirect();

        $asset = FixedAsset::where('asset_number', 'FA-TEST-002')->firstOrFail();

        foreach (['2026-05', '2026-06'] as $month) {
            $this->actingAs($this->admin)
                ->post(route('admin.accounting.fixed-assets.depreciation', $asset), [
                    'period_month' => $month,
                    'posted_on' => $month.'-28',
                ])
                ->assertRedirect();
            $asset->refresh();
        }

        $this->actingAs($this->admin)
            ->post(route('admin.accounting.fixed-assets.dispose', $asset), [
                'disposed_on' => '2026-07-01',
                'disposal_proceeds' => 11000,
                'disposal_payment_method' => 'bank_transfer',
            ])
            ->assertRedirect(route('admin.accounting.fixed-assets.show', $asset));

        $asset->refresh();
        $this->assertSame('disposed', $asset->status);
        $this->assertNotNull($asset->disposal_journal_entry_id);

        $disposal = JournalEntry::where('event_type', 'fixed_asset_disposal')->with('lines.account')->firstOrFail();
        $this->assertEntryAccountTotals($disposal, AccountingService::ACCUMULATED_DEPRECIATION, 2000, 0);
        $this->assertEntryAccountTotals($disposal, AccountingService::BANK, 11000, 0);
        $this->assertEntryAccountTotals($disposal, AccountingService::FIXED_ASSETS, 0, 12000);
        $this->assertEntryAccountTotals($disposal, AccountingService::FIXED_ASSET_DISPOSAL_GAIN, 0, 1000);
    }

    public function test_monthly_depreciation_run_posts_all_due_assets_once(): void
    {
        foreach ([
            ['asset_number' => 'FA-BATCH-001', 'name' => 'Kitchen Hood', 'foreign_cost' => 6000],
            ['asset_number' => 'FA-BATCH-002', 'name' => 'Walk-in Fridge', 'foreign_cost' => 12000],
        ] as $assetData) {
            $this->actingAs($this->admin)
                ->post(route('admin.accounting.fixed-assets.store'), [
                    ...$assetData,
                    'category' => 'Equipment',
                    'acquisition_date' => '2026-05-01',
                    'in_service_date' => '2026-05-01',
                    'foreign_salvage_value' => 0,
                    'currency_code' => 'USD',
                    'exchange_rate' => 1,
                    'useful_life_months' => 12,
                    'payment_method' => 'bank_transfer',
                ])
                ->assertRedirect();
        }

        $this->actingAs($this->admin)
            ->post(route('admin.accounting.fixed-assets.depreciation-run'), [
                'period_month' => '2026-05',
                'posted_on' => '2026-05-31',
                'notes' => 'May close',
            ])
            ->assertRedirect(route('admin.accounting.fixed-assets.index'));

        $this->assertSame(2, FixedAssetDepreciation::count());
        $this->assertSame(2, JournalEntry::where('event_type', 'fixed_asset_depreciation')->count());
        $this->assertEqualsWithDelta(500, (float) FixedAsset::where('asset_number', 'FA-BATCH-001')->firstOrFail()->accumulated_depreciation, 0.01);
        $this->assertEqualsWithDelta(1000, (float) FixedAsset::where('asset_number', 'FA-BATCH-002')->firstOrFail()->accumulated_depreciation, 0.01);

        $this->actingAs($this->admin)
            ->post(route('admin.accounting.fixed-assets.depreciation-run'), [
                'period_month' => '2026-05',
                'posted_on' => '2026-05-31',
            ])
            ->assertRedirect(route('admin.accounting.fixed-assets.index'));

        $this->assertSame(2, FixedAssetDepreciation::count());
        $this->assertSame(2, JournalEntry::where('event_type', 'fixed_asset_depreciation')->count());
    }

    public function test_fixed_asset_accounts_are_seeded_as_system_accounts(): void
    {
        $this->assertTrue((bool) Account::where('code', AccountingService::FIXED_ASSETS)->firstOrFail()->is_system);
        $this->assertSame('credit', Account::where('code', AccountingService::ACCUMULATED_DEPRECIATION)->firstOrFail()->normal_balance);
        $this->assertSame('expense', Account::where('code', AccountingService::DEPRECIATION_EXPENSE)->firstOrFail()->type);
    }

    public function test_accumulated_depreciation_system_account_can_be_renamed(): void
    {
        $account = Account::where('code', AccountingService::ACCUMULATED_DEPRECIATION)->firstOrFail();

        $updated = app(\App\Services\Accounting\AccountService::class)->update($account, [
            'code' => $account->code,
            'name' => 'Accumulated depreciation updated',
            'type' => 'asset',
            'normal_balance' => 'credit',
            'is_active' => true,
        ]);

        $this->assertSame('Accumulated depreciation updated', $updated->name);
        $this->assertSame('credit', $updated->normal_balance);
    }

    private function assertEntryAccountTotals(JournalEntry $entry, string $accountCode, float $expectedDebit, float $expectedCredit): void
    {
        $lines = $entry->lines->filter(fn ($line) => $line->account?->code === $accountCode);

        $this->assertEqualsWithDelta($expectedDebit, (float) $lines->sum('debit'), 0.01);
        $this->assertEqualsWithDelta($expectedCredit, (float) $lines->sum('credit'), 0.01);
    }
}
