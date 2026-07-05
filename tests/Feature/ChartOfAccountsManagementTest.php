<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountMapping;
use App\Models\Branch;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Role;
use App\Models\User;
use App\Services\Accounting\AccountService;
use App\Services\Accounting\AccountingService;
use App\Support\BranchContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Accountant-facing chart-of-accounts management. The risks this
 * protects against are documented in AccountService:
 *
 *   - System account codes are immutable (operational AccountingService
 *     references them by hard-coded constant).
 *   - System accounts cannot be deactivated (would break next invoice).
 *   - Deletion requires zero history + zero children + not system.
 *   - Parent/child must share TYPE.
 *   - No cycles allowed (proposed parent ↔ descendant).
 *   - Permission gating: chart_of_accounts.* perms enforce admin/manager only.
 */
class ChartOfAccountsManagementTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;
    protected User $admin;
    protected User $cashier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::create(['code' => 'a', 'name' => 'A', 'is_active' => true]);
        BranchContext::set($this->branch->id);
        Role::firstOrCreate(['name' => 'admin'],   ['label' => 'Admin', 'is_system' => true]);
        Role::firstOrCreate(['name' => 'cashier'], ['label' => 'Cashier', 'is_system' => true]);
        $this->seed(\Database\Seeders\PermissionSeeder::class);

        $this->admin = $this->user('a_chart', 'admin');
        $this->cashier = $this->user('c_chart', 'cashier');
    }

    protected function tearDown(): void
    {
        BranchContext::clear();
        parent::tearDown();
    }

    // ───────────────────────────────────────────────────────────────
    // CRUD basics
    // ───────────────────────────────────────────────────────────────

    public function test_accountant_can_create_a_custom_account_under_an_existing_parent(): void
    {
        // Codes prefixed 9xxx to avoid collision with the canonical
        // system accounts pre-seeded by accounting migrations.
        $parent = Account::firstOrCreate(
            ['code' => '9100'],
            ['name' => 'Operating Expenses', 'type' => 'expense', 'normal_balance' => 'debit', 'is_system' => true],
        );

        $child = app(AccountService::class)->create([
            'code' => '9101', 'name' => 'Maintenance',
            'type' => 'expense', 'normal_balance' => 'debit',
            'parent_account_id' => $parent->id,
        ]);

        $this->assertSame($parent->id, (int) $child->parent_account_id);
        $this->assertFalse($child->is_system,
            'User-created accounts are NEVER auto-flagged as system.');
        $this->assertStringContainsString('9100', $child->path());
        $this->assertStringContainsString('9101', $child->path());
    }

    public function test_system_account_code_is_locked_on_update(): void
    {
        $sys = Account::firstOrCreate(
            ['code' => '9200'],
            ['name' => 'Cash', 'type' => 'asset', 'normal_balance' => 'debit', 'is_system' => true],
        );

        app(AccountService::class)->update($sys, [
            'code' => '9200_LEGACY',
            'name' => 'New name allowed',
            'type' => 'liability',
            'normal_balance' => 'credit',
            'is_active' => false,
        ]);

        $sys->refresh();
        $this->assertSame('9200', $sys->code, 'Code stays locked on system accounts.');
        $this->assertSame('asset', $sys->type, 'Type stays locked on system accounts.');
        $this->assertSame('debit', $sys->normal_balance, 'Normal balance stays locked on system accounts.');
        $this->assertTrue($sys->is_active, 'System accounts cannot be disabled through update payloads.');
        $this->assertSame('New name allowed', $sys->name, 'Name IS editable even on system accounts.');
    }

    public function test_normal_balance_must_match_account_type(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/الطبيعة المحاسبية/u');

        app(AccountService::class)->create([
            'code' => '9210', 'name' => 'Broken asset',
            'type' => 'asset', 'normal_balance' => 'credit',
        ]);
    }

    public function test_cannot_deactivate_system_account(): void
    {
        $sys = Account::firstOrCreate(
            ['code' => '9300'],
            ['name' => 'Sales Revenue', 'type' => 'revenue', 'normal_balance' => 'credit', 'is_system' => true],
        );

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/حساب نظامي/u');
        app(AccountService::class)->setActive($sys, false);
    }

    public function test_custom_account_core_fields_are_locked_after_journal_history(): void
    {
        $account = app(AccountService::class)->create([
            'code' => '9220', 'name' => 'Posted custom expense',
            'type' => 'expense', 'normal_balance' => 'debit',
        ]);

        $entry = JournalEntry::create([
            'branch_id' => $this->branch->id, 'posted_on' => now()->toDateString(),
            'description' => 'posted custom account history', 'status' => 'posted',
        ]);
        JournalLine::create([
            'journal_entry_id' => $entry->id, 'account_id' => $account->id,
            'branch_id' => $this->branch->id, 'line_no' => 1,
            'debit' => 10, 'credit' => 0,
        ]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/قيود محاسبية|journal lines|Cannot change/u');

        app(AccountService::class)->update($account, [
            'code' => '9220-NEW', 'name' => 'Changed',
            'type' => 'asset', 'normal_balance' => 'debit',
        ]);
    }

    public function test_custom_account_name_can_change_after_journal_history(): void
    {
        $account = app(AccountService::class)->create([
            'code' => '9221', 'name' => 'Posted custom expense',
            'type' => 'expense', 'normal_balance' => 'debit',
        ]);

        $entry = JournalEntry::create([
            'branch_id' => $this->branch->id, 'posted_on' => now()->toDateString(),
            'description' => 'posted custom account history', 'status' => 'posted',
        ]);
        JournalLine::create([
            'journal_entry_id' => $entry->id, 'account_id' => $account->id,
            'branch_id' => $this->branch->id, 'line_no' => 1,
            'debit' => 10, 'credit' => 0,
        ]);

        $updated = app(AccountService::class)->update($account, [
            'code' => '9221', 'name' => 'Renamed after posting',
            'type' => 'expense', 'normal_balance' => 'debit',
            'is_active' => false,
        ]);

        $this->assertSame('9221', $updated->code);
        $this->assertSame('expense', $updated->type);
        $this->assertSame('debit', $updated->normal_balance);
        $this->assertSame('Renamed after posting', $updated->name);
        $this->assertFalse($updated->is_active);
    }

    public function test_can_deactivate_user_created_account(): void
    {
        $custom = app(AccountService::class)->create([
            'code' => '9401', 'name' => 'Maintenance',
            'type' => 'expense', 'normal_balance' => 'debit',
        ]);

        app(AccountService::class)->setActive($custom, false);
        $this->assertFalse($custom->fresh()->is_active);
    }

    // ───────────────────────────────────────────────────────────────
    // Hierarchy + validation
    // ───────────────────────────────────────────────────────────────

    public function test_parent_must_have_same_type(): void
    {
        $assetParent = Account::firstOrCreate(
            ['code' => '9500'],
            ['name' => 'Cash', 'type' => 'asset', 'normal_balance' => 'debit'],
        );

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/نفس النوع/u');
        app(AccountService::class)->create([
            'code' => '9501', 'name' => 'Wrong type child',
            'type' => 'expense', 'normal_balance' => 'debit',
            'parent_account_id' => $assetParent->id,
        ]);
    }

    public function test_cannot_create_parent_cycle(): void
    {
        $a = app(AccountService::class)->create([
            'code' => '9601', 'name' => 'A',
            'type' => 'expense', 'normal_balance' => 'debit',
        ]);
        $b = app(AccountService::class)->create([
            'code' => '9602', 'name' => 'B',
            'type' => 'expense', 'normal_balance' => 'debit',
            'parent_account_id' => $a->id,
        ]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/حلقة|cycle/iu');
        app(AccountService::class)->update($a, [
            'code' => '9601', 'name' => 'A',
            'type' => 'expense', 'normal_balance' => 'debit',
            'parent_account_id' => $b->id,
        ]);
    }

    public function test_parent_account_type_cannot_change_if_children_would_mismatch(): void
    {
        $parent = app(AccountService::class)->create([
            'code' => '9650', 'name' => 'Parent',
            'type' => 'expense', 'normal_balance' => 'debit',
        ]);
        app(AccountService::class)->create([
            'code' => '9651', 'name' => 'Child',
            'type' => 'expense', 'normal_balance' => 'debit',
            'parent_account_id' => $parent->id,
        ]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/حسابات فرعية|child accounts|type/u');

        app(AccountService::class)->update($parent, [
            'code' => '9650', 'name' => 'Parent',
            'type' => 'asset', 'normal_balance' => 'debit',
        ]);
    }

    public function test_duplicate_code_is_rejected(): void
    {
        Account::create([
            'code' => '9701', 'name' => 'X',
            'type' => 'expense', 'normal_balance' => 'debit',
        ]);
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/مستخدم مسبقاً/u');
        app(AccountService::class)->create([
            'code' => '9701', 'name' => 'Y',
            'type' => 'expense', 'normal_balance' => 'debit',
        ]);
    }

    // ───────────────────────────────────────────────────────────────
    // Delete protections
    // ───────────────────────────────────────────────────────────────

    public function test_cannot_delete_account_with_journal_lines(): void
    {
        $acc = Account::create([
            'code' => '9801', 'name' => 'X',
            'type' => 'expense', 'normal_balance' => 'debit',
        ]);
        // Create a journal entry that references it.
        $entry = JournalEntry::create([
            'branch_id' => $this->branch->id, 'posted_on' => now()->toDateString(),
            'description' => 'test', 'status' => 'posted',
        ]);
        JournalLine::create([
            'journal_entry_id' => $entry->id, 'account_id' => $acc->id,
            'branch_id' => $this->branch->id, 'line_no' => 1,
            'debit' => 10, 'credit' => 0,
        ]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/قيود محاسبية سابقة/u');
        app(AccountService::class)->delete($acc);
    }

    public function test_cannot_delete_system_account_even_without_history(): void
    {
        $sys = Account::firstOrCreate(
            ['code' => '9802'],
            ['name' => 'Cash', 'type' => 'asset', 'normal_balance' => 'debit', 'is_system' => true],
        );
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/نظامي/u');
        app(AccountService::class)->delete($sys);
    }

    public function test_can_delete_user_account_with_no_history(): void
    {
        $acc = app(AccountService::class)->create([
            'code' => '9899', 'name' => 'Temp',
            'type' => 'expense', 'normal_balance' => 'debit',
        ]);
        app(AccountService::class)->delete($acc);
        $this->assertNull(Account::find($acc->id));
    }

    // ───────────────────────────────────────────────────────────────
    // Permission gating via controller
    // ───────────────────────────────────────────────────────────────

    public function test_cashier_is_forbidden_from_chart_management(): void
    {
        $this->actingAs($this->cashier)
            ->get(route('admin.accounts.index'))
            ->assertForbidden();
    }

    public function test_admin_can_load_the_chart_index(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.accounts.index'))
            ->assertOk();
    }

    public function test_admin_can_save_posting_role_mapping_from_account_mappings_screen(): void
    {
        $customRevenue = app(AccountService::class)->create([
            'code' => '9945',
            'name' => 'Custom Sales Revenue',
            'type' => 'revenue',
            'normal_balance' => 'credit',
        ]);

        $this->actingAs($this->admin)
            ->from(route('admin.accounting.mappings'))
            ->post(route('admin.accounting.mappings.store'), [
                'posting_role_accounts' => [
                    'sales_revenue' => $customRevenue->id,
                ],
            ])
            ->assertRedirect(route('admin.accounting.mappings'));

        $this->assertDatabaseHas('account_mappings', [
            'context' => AccountMapping::CONTEXT_POSTING_ROLE,
            'key' => 'sales_revenue',
            'account_id' => $customRevenue->id,
        ]);
    }

    public function test_account_mappings_screen_renders_stable_posting_role_keys(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.accounting.mappings'))
            ->assertOk()
            ->assertSee('posting_role_accounts[sales_revenue]', false)
            ->assertSee('posting_role_accounts[cost_of_goods_sold]', false);
    }

    public function test_posting_role_mapping_rejects_accounts_with_wrong_type(): void
    {
        $wrongType = app(AccountService::class)->create([
            'code' => '9946',
            'name' => 'Wrong Type For Revenue',
            'type' => 'asset',
            'normal_balance' => 'debit',
        ]);

        $this->actingAs($this->admin)
            ->from(route('admin.accounting.mappings'))
            ->post(route('admin.accounting.mappings.store'), [
                'posting_role_accounts' => [
                    'sales_revenue' => $wrongType->id,
                ],
            ])
            ->assertRedirect(route('admin.accounting.mappings'))
            ->assertSessionHas('error');

        $this->assertDatabaseMissing('account_mappings', [
            'context' => AccountMapping::CONTEXT_POSTING_ROLE,
            'key' => 'sales_revenue',
            'account_id' => $wrongType->id,
        ]);
    }

    // ───────────────────────────────────────────────────────────────
    // Manual journal entry — bridges custom accounts to actual usage
    // ───────────────────────────────────────────────────────────────

    public function test_admin_can_post_a_manual_journal_to_a_custom_account(): void
    {
        // Custom expense account.
        $marketing = app(AccountService::class)->create([
            'code' => '9910', 'name' => 'مصاريف تسويق',
            'type' => 'expense', 'normal_balance' => 'debit',
        ]);
        // Already-seeded cash account.
        $cash = \App\Models\Account::where('code', '1000')->first();
        $this->assertNotNull($cash, 'Cash account should exist from seeded migrations.');

        $this->actingAs($this->admin)
            ->post(route('admin.accounting.manual-entry.store'), [
                'posted_on'   => now()->toDateString(),
                'description' => 'مصاريف تسويق نقدية',
                'lines' => [
                    ['account_id' => $marketing->id, 'debit'  => 200, 'credit' => 0],
                    ['account_id' => $cash->id,      'debit'  => 0,   'credit' => 200],
                ],
            ])
            ->assertRedirect(route('admin.accounting.journal'));

        $entry = \App\Models\JournalEntry::where('event_type', 'manual_journal')
            ->with('lines.account')
            ->latest('id')->first();
        $this->assertNotNull($entry, 'Manual journal MUST create a JournalEntry.');
        $this->assertCount(2, $entry->lines);

        $debit  = $entry->lines->firstWhere(fn ($l) => $l->debit > 0);
        $credit = $entry->lines->firstWhere(fn ($l) => $l->credit > 0);
        $this->assertSame('9910', $debit->account->code, 'Debit lands on the user-created account.');
        $this->assertSame('1000', $credit->account->code, 'Credit lands on cash.');
        $this->assertEqualsWithDelta(200.0, (float) $debit->debit, 0.01);
    }

    public function test_admin_can_post_multiple_manual_journals(): void
    {
        $cash = \App\Models\Account::where('code', '1000')->first();
        $bank = \App\Models\Account::where('code', '1010')->first();

        foreach ([25, 40] as $amount) {
            $this->actingAs($this->admin)
                ->post(route('admin.accounting.manual-entry.store'), [
                    'posted_on' => now()->toDateString(),
                    'description' => "Manual transfer {$amount}",
                    'lines' => [
                        ['account_id' => $bank->id, 'debit' => $amount, 'credit' => 0],
                        ['account_id' => $cash->id, 'debit' => 0, 'credit' => $amount],
                    ],
                ])
                ->assertRedirect(route('admin.accounting.journal'));
        }

        $this->assertSame(2, \App\Models\JournalEntry::where('event_type', 'manual_journal')->count(),
            'Manual journals are not idempotent by user; each approved posting is a separate journal entry.');
    }

    public function test_admin_can_reverse_and_correct_a_journal_entry_from_adjustment_screen(): void
    {
        $marketing = app(AccountService::class)->create([
            'code' => '9930',
            'name' => 'مصاريف تسويق مصححة',
            'type' => 'expense',
            'normal_balance' => 'debit',
        ]);
        $cash = Account::where('code', '1000')->firstOrFail();
        $bank = Account::where('code', '1010')->firstOrFail();

        $entry = app(AccountingService::class)->post(
            eventType: 'manual_journal',
            source: null,
            branchId: $this->branch->id,
            postedOn: now()->toDateString(),
            description: 'قيد خاطئ للتحويل',
            lines: [
                ['account' => $bank->code, 'debit' => 75, 'credit' => 0],
                ['account' => $cash->code, 'debit' => 0, 'credit' => 75],
            ],
            createdBy: $this->admin->id,
        );

        $this->actingAs($this->admin)
            ->post(route('admin.accounting.journal.adjust.store', $entry), [
                'mode' => 'correct',
                'posted_on' => now()->toDateString(),
                'reason' => 'اختيار الحساب كان خاطئا',
                'correction_description' => 'القيد المصحح',
                'lines' => [
                    ['account_id' => $marketing->id, 'debit' => 75, 'credit' => 0, 'description' => 'مصروف تسويق'],
                    ['account_id' => $cash->id, 'debit' => 0, 'credit' => 75, 'description' => 'سداد نقدي'],
                ],
            ])
            ->assertRedirect(route('admin.accounting.journal'));

        $reversal = JournalEntry::where('event_type', 'manual_entry_reversal_'.$entry->id)
            ->with('lines.account')
            ->firstOrFail();
        $correction = JournalEntry::where('event_type', 'manual_journal_correction')
            ->with('lines.account')
            ->firstOrFail();

        $this->assertSame($entry->id, (int) $reversal->metadata['reverses_entry_id']);
        $this->assertSame($entry->id, (int) $correction->metadata['corrects_entry_id']);
        $this->assertEqualsWithDelta(75.0, (float) $reversal->lines
            ->first(fn ($line) => $line->account?->code === $bank->code)?->credit, 0.01);
        $this->assertEqualsWithDelta(75.0, (float) $correction->lines
            ->first(fn ($line) => $line->account?->code === $marketing->code)?->debit, 0.01);
    }

    public function test_manual_journal_rejects_inactive_accounts(): void
    {
        $inactive = app(AccountService::class)->create([
            'code' => '9920', 'name' => 'Inactive expense',
            'type' => 'expense', 'normal_balance' => 'debit',
        ]);
        app(AccountService::class)->setActive($inactive, false);

        $cash = \App\Models\Account::where('code', '1000')->first();

        $this->actingAs($this->admin)
            ->post(route('admin.accounting.manual-entry.store'), [
                'posted_on' => now()->toDateString(),
                'description' => 'Should not post to inactive account',
                'lines' => [
                    ['account_id' => $inactive->id, 'debit' => 50, 'credit' => 0],
                    ['account_id' => $cash->id, 'debit' => 0, 'credit' => 50],
                ],
            ])
            ->assertSessionHas('error');

        $this->assertSame(0, \App\Models\JournalEntry::where('event_type', 'manual_journal')->count());
    }

    public function test_unbalanced_manual_entry_is_rejected(): void
    {
        $cash = \App\Models\Account::where('code', '1000')->first();
        $bank = \App\Models\Account::where('code', '1010')->first();

        // 100 DR but 50 CR → debits ≠ credits → AccountingService throws.
        $this->actingAs($this->admin)
            ->post(route('admin.accounting.manual-entry.store'), [
                'posted_on'   => now()->toDateString(),
                'description' => 'بدون توازن',
                'lines' => [
                    ['account_id' => $cash->id, 'debit' => 100, 'credit' => 0],
                    ['account_id' => $bank->id, 'debit' => 0,   'credit' => 50],
                ],
            ])
            ->assertSessionHas('error');

        $this->assertSame(0,
            \App\Models\JournalEntry::where('event_type', 'manual_journal')->count(),
            'Unbalanced entries must NOT be persisted.');
    }

    public function test_cashier_cannot_post_manual_journal(): void
    {
        $cash = \App\Models\Account::where('code', '1000')->first();
        $this->actingAs($this->cashier)
            ->post(route('admin.accounting.manual-entry.store'), [
                'posted_on'   => now()->toDateString(),
                'description' => 'محاولة من كاشير',
                'lines' => [
                    ['account_id' => $cash->id, 'debit' => 50, 'credit' => 0],
                    ['account_id' => $cash->id, 'debit' => 0,  'credit' => 50],
                ],
            ])
            ->assertForbidden();
    }

    // ─── helpers ──────────────────────────────────────────────────────

    protected function user(string $username, string $role): User
    {
        $u = User::create([
            'name'              => ucfirst($username),
            'username'          => $username,
            'password'          => bcrypt('x'),
            'status'            => 'active',
            'role'              => $role,
            'primary_branch_id' => $this->branch->id,
        ]);
        $u->branches()->attach($this->branch->id, ['is_primary' => true]);
        return $u;
    }
}
