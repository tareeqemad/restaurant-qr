<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Branch;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Services\Accounting\AccountingService;
use App\Support\BranchContext;
use App\Support\TaxConfiguration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Wave 6 (MIGRATION-PILOT.md §13) — شجرة الحسابات on Inertia/Vue.
 *
 * The old screen computed the whole tree inside the Blade template: the
 * parent grouping, the six type sections, the per-node "can I delete this"
 * verdict, even the route strings. All of it now ships as props, so these
 * tests assert the PAYLOAD, never the markup.
 *
 * Four things here are load-bearing for the books and are pinned
 * deliberately:
 *   1. the normal-balance label comes from the stored column, never the
 *      type (a protected contra-asset must print «دائن», not «مدين»);
 *   2. sorting happens in PHP, which compares numeric-ish codes
 *      NUMERICALLY — JavaScript's .sort() would not;
 *   3. per-node delete mirrors AccountPolicy::delete, so the client can
 *      never offer a delete the server refuses;
 *   4. writes answer with a REDIRECT even when the request looks like
 *      XHR — Inertia sends X-Requested-With on every visit, and the old
 *      JSON branches would have broken every save.
 */
class ChartOfAccountsVueTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;
    protected User $admin;
    protected User $cashier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::create(['code' => 'coa', 'name' => 'فرع الحسابات', 'is_active' => true]);
        BranchContext::set($this->branch->id);

        // Roles must exist BEFORE the seeder runs — PermissionSeeder syncs
        // onto roles it can find and silently skips the ones it cannot.
        foreach (['admin', 'cashier', 'accountant'] as $role) {
            Role::firstOrCreate(['name' => $role], ['label' => $role, 'is_system' => true]);
        }
        $this->seed(\Database\Seeders\PermissionSeeder::class);

        $this->admin = $this->staff('admin', 'coa_admin');
        $this->cashier = $this->staff('cashier', 'coa_cashier');

        // Tax off (the default) → the sales-tax account is operationally
        // hidden from the day-to-day chart.
        Setting::put('tax_enabled', false, 'billing', 'bool');
    }

    protected function tearDown(): void
    {
        BranchContext::clear();
        parent::tearDown();
    }

    /**
     * `users` has no branch_id column — membership is primary_branch_id plus
     * the branches() pivot, and the role is a plain column backed by a Role row.
     */
    protected function staff(string $role, string $username): User
    {
        Role::firstOrCreate(['name' => $role], ['label' => $role, 'is_system' => true]);

        $user = User::create([
            'name' => $username,
            'username' => $username,
            'password' => bcrypt('x'),
            'status' => 'active',
            'primary_branch_id' => $this->branch->id,
            'role' => $role,
        ]);
        $user->branches()->attach($this->branch->id, ['is_primary' => true]);

        return $user;
    }

    protected function account(string $code, array $attrs = []): Account
    {
        return Account::create(array_merge([
            'code' => $code,
            'name' => 'حساب '.$code,
            'type' => 'expense',
            'normal_balance' => 'debit',
            'is_system' => false,
            'is_active' => true,
            'display_order' => 0,
        ], $attrs));
    }

    /** The Inertia props of a successful GET on the tree. */
    protected function treeProps(?User $as = null): array
    {
        $props = null;
        $this->actingAs($as ?? $this->admin)
            ->withSession(['active_branch_id' => $this->branch->id])
            ->get(route('admin.accounts.index'))
            ->assertOk()
            ->assertInertia(function (Assert $page) use (&$props) {
                $page->component('Admin/Accounts/Index');
                $props = $page->toArray()['props'];
            });

        return $props;
    }

    /** @return array<int, array> every node of every section, depth-first. */
    protected function flatten(array $sections): array
    {
        $out = [];
        $walk = function (array $node) use (&$walk, &$out) {
            $out[] = $node;
            foreach ($node['children'] as $child) {
                $walk($child);
            }
        };
        foreach ($sections as $section) {
            foreach ($section['nodes'] as $node) {
                $walk($node);
            }
        }

        return $out;
    }

    protected function node(array $sections, string $code): ?array
    {
        foreach ($this->flatten($sections) as $node) {
            if ($node['code'] === $code) {
                return $node;
            }
        }

        return null;
    }

    protected function section(array $sections, string $type): ?array
    {
        foreach ($sections as $section) {
            if ($section['type'] === $type) {
                return $section;
            }
        }

        return null;
    }

    // ───────────────────────────────────────────────────────────────
    // Gates
    // ───────────────────────────────────────────────────────────────

    public function test_cashier_is_forbidden_from_the_chart(): void
    {
        $this->actingAs($this->cashier)
            ->withSession(['active_branch_id' => $this->branch->id])
            ->get(route('admin.accounts.index'))
            ->assertForbidden();
    }

    public function test_admin_gets_every_toolbar_gate_and_the_mappings_link(): void
    {
        $props = $this->treeProps();

        $this->assertTrue($props['can']['create']);
        $this->assertTrue($props['can']['update']);
        $this->assertTrue($props['can']['delete']);
        $this->assertNotNull($props['urls']['mappings']);
        $this->assertNotNull($props['urls']['create']);
        $this->assertArrayHasKey('workspace', $props['urls']);
    }

    public function test_a_view_only_accountant_gets_no_edit_no_delete_and_no_mappings_link(): void
    {
        $viewer = $this->staff('accountant', 'coa_viewer');
        // Per-user deviation: keep viewAny, revoke the rest.
        foreach (['create', 'update', 'delete'] as $verb) {
            $permission = Permission::where('name', "chart_of_accounts.{$verb}")->firstOrFail();
            $viewer->permissions()->attach($permission->id, ['granted' => false]);
        }

        $props = $this->treeProps($viewer);

        $this->assertFalse($props['can']['create']);
        $this->assertFalse($props['can']['update']);
        $this->assertFalse($props['can']['delete']);
        $this->assertNull($props['urls']['mappings']);
        $this->assertNull($props['urls']['create']);

        foreach ($this->flatten($props['sections']) as $node) {
            $this->assertFalse($node['can']['update'], "node {$node['code']} must not offer edit");
            $this->assertFalse($node['can']['delete'], "node {$node['code']} must not offer delete");
        }
    }

    // ───────────────────────────────────────────────────────────────
    // Shape: sections, hierarchy, sorting
    // ───────────────────────────────────────────────────────────────

    public function test_sections_keep_the_fixed_type_order_and_never_ship_empty(): void
    {
        $props = $this->treeProps();

        $order = ['asset', 'liability', 'equity', 'revenue', 'contra_revenue', 'expense'];
        $seen = array_column($props['sections'], 'type');

        $this->assertSame($seen, array_values(array_filter($order, fn ($t) => in_array($t, $seen, true))),
            'sections must follow the fixed type order');

        foreach ($props['sections'] as $section) {
            $this->assertNotEmpty($section['nodes'], "section {$section['type']} would be empty — it should not ship");
            $this->assertSame($section['label'], $section['searchText']);
        }
    }

    public function test_root_codes_sort_numerically_not_lexicographically(): void
    {
        // '9900' < '91000' numerically, but '91000' < '9900' as strings.
        // Sorting in JS would silently invert these two.
        $this->account('9900', ['name' => 'تسعة آلاف وتسعمئة']);
        $this->account('91000', ['name' => 'واحد وتسعون ألفاً']);

        $props = $this->treeProps();
        $codes = array_column($this->section($props['sections'], 'expense')['nodes'], 'code');

        $this->assertContains('9900', $codes);
        $this->assertContains('91000', $codes);
        $this->assertLessThan(array_search('91000', $codes, true), array_search('9900', $codes, true),
            'roots sort by code NUMERICALLY (PHP spaceship), not as strings');
    }

    public function test_children_sort_by_display_order_then_code(): void
    {
        $parent = $this->account('9700');
        $this->account('97002', ['parent_account_id' => $parent->id, 'display_order' => 5]);
        $this->account('97001', ['parent_account_id' => $parent->id, 'display_order' => 1]);
        $this->account('97003', ['parent_account_id' => $parent->id, 'display_order' => 1]);

        $node = $this->node($this->treeProps()['sections'], '9700');

        $this->assertTrue($node['hasChildren']);
        $this->assertSame(['97001', '97003', '97002'], array_column($node['children'], 'code'));
    }

    public function test_a_child_carries_its_parent_label_and_row_title(): void
    {
        $parent = $this->account('9600', ['name' => 'مصاريف تشغيلية']);
        $this->account('96001', ['name' => 'صيانة', 'parent_account_id' => $parent->id, 'description' => 'صيانة دورية']);

        $child = $this->node($this->treeProps()['sections'], '96001');

        $this->assertSame($parent->id, $child['parentId']);
        $this->assertSame('9600 — مصاريف تشغيلية', $child['parentLabel']);
        $this->assertSame('96001 — صيانة • صيانة دورية', $child['title']);
        $this->assertStringContainsString('96001 صيانة · مدين', $child['searchText']);
    }

    // ───────────────────────────────────────────────────────────────
    // Accounting correctness
    // ───────────────────────────────────────────────────────────────

    public function test_normal_balance_label_reads_the_stored_column_not_the_type(): void
    {
        // Accumulated depreciation: an ASSET whose normal balance is CREDIT.
        // Deriving the label from the type would print «مدين» — a debit
        // shown where a credit belongs.
        $this->account('9500', [
            'name' => 'مجمع الإهلاك',
            'type' => 'asset',
            'normal_balance' => 'credit',
            'is_system' => true,
        ]);

        $node = $this->node($this->treeProps()['sections'], '9500');

        $this->assertSame('asset', $node['type']);
        $this->assertSame('credit', $node['normalBalance']);
        $this->assertSame('دائن', $node['normalBalanceLabel']);
        $this->assertStringContainsString('دائن', $node['searchText']);
        $this->assertStringContainsString('نظامي', $node['searchText']);
    }

    public function test_per_node_delete_gate_mirrors_the_policy(): void
    {
        $system = $this->account('9801', ['is_system' => true]);
        $parent = $this->account('9810');
        $this->account('98101', ['parent_account_id' => $parent->id]);
        $posted = $this->account('9820');
        $clean = $this->account('9830');

        app(AccountingService::class)->post(
            eventType: 'coa_probe',
            source: null,
            branchId: $this->branch->id,
            postedOn: now()->toDateString(),
            description: 'حركة اختبار',
            lines: [
                ['account' => $posted->code, 'debit' => 10, 'credit' => 0],
                ['account' => AccountingService::CASH, 'debit' => 0, 'credit' => 10],
            ],
            createdBy: $this->admin->id,
        );

        $sections = $this->treeProps()['sections'];

        $sys = $this->node($sections, $system->code);
        $this->assertTrue($sys['isSystem']);
        $this->assertFalse($sys['isDeletable']);
        $this->assertFalse($sys['can']['delete']);

        $withKids = $this->node($sections, $parent->code);
        $this->assertTrue($withKids['hasChildren']);
        $this->assertFalse($withKids['can']['delete']);

        $withHistory = $this->node($sections, $posted->code);
        $this->assertTrue($withHistory['hasJournalLines']);
        $this->assertFalse($withHistory['can']['delete']);

        $free = $this->node($sections, $clean->code);
        $this->assertFalse($free['hasJournalLines']);
        $this->assertFalse($free['hasChildren']);
        $this->assertTrue($free['isDeletable']);
        $this->assertTrue($free['can']['delete']);
    }

    public function test_stats_count_every_node_not_just_roots(): void
    {
        $parent = $this->account('9400');
        $this->account('94001', ['parent_account_id' => $parent->id, 'is_active' => false]);

        $props = $this->treeProps();

        // The counters describe the VISIBLE chart, so the tax-hidden
        // sales-tax account is outside every one of them — same as the Blade.
        $hidden = TaxConfiguration::operationallyHiddenAccountIds();
        $visible = fn () => Account::query()->whereNotIn('id', $hidden);

        $this->assertGreaterThan(0, $hidden->count(), 'tax is off, so at least one account must be hidden');
        $this->assertEquals($visible()->count(), $props['stats']['total']);
        $this->assertEquals($visible()->where('is_active', false)->count(), $props['stats']['inactive']);
        $this->assertEquals($visible()->where('is_system', true)->count(), $props['stats']['system']);
        $this->assertGreaterThan(0, $props['stats']['inactive']);

        // A child counts as much as a root — the footer strip is per NODE.
        $this->assertCount($props['stats']['total'], $this->flatten($props['sections']),
            'every visible account must appear exactly once in the tree');
    }

    public function test_section_badges_split_active_roots_from_all_roots(): void
    {
        $this->account('9210', ['is_active' => false]);

        $expense = $this->section($this->treeProps()['sections'], 'expense');

        $this->assertGreaterThan($expense['activeRootCount'], $expense['totalRootCount'],
            'the inactive root must count towards totalRootCount only');
    }

    // ───────────────────────────────────────────────────────────────
    // Tax visibility
    // ───────────────────────────────────────────────────────────────

    public function test_hidden_sales_tax_account_leaves_the_tree_but_stays_in_existing_codes(): void
    {
        $props = $this->treeProps();
        $codes = array_column($this->flatten($props['sections']), 'code');

        $this->assertContains(AccountingService::INPUT_VAT, $codes,
            'input VAT stays visible — only the sales-tax account hides');
        $this->assertNotContains(AccountingService::OUTPUT_VAT, $codes);

        // The code-suggestion set is built from the WHOLE table so it can
        // never suggest a code a hidden account already owns.
        $this->assertContains(AccountingService::OUTPUT_VAT, $props['existingCodes']);
    }

    // ───────────────────────────────────────────────────────────────
    // Create / edit pages
    // ───────────────────────────────────────────────────────────────

    public function test_create_page_prefills_from_the_parent_query_string(): void
    {
        $parent = $this->account('9300', ['name' => 'مصاريف إدارية']);

        $this->actingAs($this->admin)
            ->withSession(['active_branch_id' => $this->branch->id])
            ->get(route('admin.accounts.create', ['parent' => $parent->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Accounts/Create')
                ->where('account', null)
                ->where('prefilledParent.id', $parent->id)
                ->where('prefilledParent.type', 'expense')
                ->where('prefilledParent.normalBalance', 'debit')
                ->where('balanceByType.expense', 'debit')
                ->has('parentOptions')
                ->has('typeOptions', 6)
                ->has('urls.workspace')
            );
    }

    public function test_edit_page_ships_the_system_lock_and_the_parent_label(): void
    {
        $parent = $this->account('9200', ['name' => 'أصل نظامي', 'type' => 'asset', 'is_system' => true]);
        $child = $this->account('92001', [
            'name' => 'فرعي', 'type' => 'asset', 'parent_account_id' => $parent->id,
        ]);

        $this->actingAs($this->admin)
            ->withSession(['active_branch_id' => $this->branch->id])
            ->get(route('admin.accounts.edit', $parent))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Accounts/Edit')
                ->where('account.isSystem', true)
                ->where('account.hasChildren', true)
                ->where('account.isDeletable', false)
                ->where('prefilledParent', null)
            );

        $this->actingAs($this->admin)
            ->withSession(['active_branch_id' => $this->branch->id])
            ->get(route('admin.accounts.edit', $child))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Accounts/Edit')
                ->where('account.isSystem', false)
                ->where('account.parentLabel', '9200 — أصل نظامي')
                ->where('account.isDeletable', true)
            );
    }

    public function test_parent_options_exclude_the_account_being_edited(): void
    {
        $account = $this->account('9250');

        $this->actingAs($this->admin)
            ->withSession(['active_branch_id' => $this->branch->id])
            ->get(route('admin.accounts.edit', $account))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('parentOptions', fn ($options) => ! collect($options)->contains('id', $account->id))
            );
    }

    // ───────────────────────────────────────────────────────────────
    // Writes: Inertia looks like XHR — they must still redirect
    // ───────────────────────────────────────────────────────────────

    public function test_store_answers_an_xhr_request_with_a_redirect_not_json(): void
    {
        $this->actingAs($this->admin)
            ->withSession(['active_branch_id' => $this->branch->id])
            ->withHeader('X-Requested-With', 'XMLHttpRequest')
            ->post(route('admin.accounts.store'), [
                'code' => '9910',
                'name' => 'مصاريف اختبار',
                'type' => 'expense',
                'normal_balance' => 'debit',
                'display_order' => 0,
                'is_active' => 1,
            ])
            ->assertRedirect(route('admin.accounts.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('accounts', ['code' => '9910', 'is_system' => false]);
    }

    public function test_a_rejected_store_comes_back_with_errors_not_a_json_body(): void
    {
        // normal_balance must follow the type — the service 422s otherwise.
        $this->actingAs($this->admin)
            ->withSession(['active_branch_id' => $this->branch->id])
            ->withHeader('X-Requested-With', 'XMLHttpRequest')
            ->post(route('admin.accounts.store'), [
                'code' => '9911',
                'name' => 'طبيعة خاطئة',
                'type' => 'expense',
                'normal_balance' => 'credit',
                'display_order' => 0,
                'is_active' => 1,
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('normal_balance');

        $this->assertDatabaseMissing('accounts', ['code' => '9911']);
    }

    /** The exact body the tree modal sends for a ROOT account. */
    public function test_the_modal_payload_creates_a_root_account(): void
    {
        $this->actingAs($this->admin)
            ->withSession(['active_branch_id' => $this->branch->id])
            ->withHeaders([
                'X-Requested-With' => 'XMLHttpRequest',
                'X-Inertia' => 'true',
                'Accept' => 'text/html, application/xhtml+xml',
            ])
            ->post(route('admin.accounts.store'), [
                'code' => '9940',
                'name' => 'التزام من النافذة',
                'description' => '',
                'type' => 'liability',
                'normal_balance' => 'credit',
                'display_order' => 0,
                'parent_account_id' => '',   // «حساب جذر» — must survive as null
                'is_active' => true,
            ])
            ->assertRedirect(route('admin.accounts.index'));

        $account = Account::where('code', '9940')->firstOrFail();
        $this->assertNull($account->parent_account_id);
        $this->assertTrue((bool) $account->is_active);
    }

    /**
     * The modal disables the locked fields but still SENDS their stored
     * values (Inertia posts JS state, not the DOM). AccountService must
     * treat that as a no-op, or every rename of a system or posted account
     * would 422.
     */
    public function test_resending_locked_fields_unchanged_is_a_no_op(): void
    {
        $system = $this->account('9950', ['name' => 'اسم قديم', 'is_system' => true]);

        $this->actingAs($this->admin)
            ->withSession(['active_branch_id' => $this->branch->id])
            ->withHeader('X-Requested-With', 'XMLHttpRequest')
            ->patch(route('admin.accounts.update', $system), [
                'code' => '9950', 'name' => 'اسم جديد', 'description' => 'وصف',
                'type' => 'expense', 'normal_balance' => 'debit',
                'display_order' => 3, 'parent_account_id' => '', 'is_active' => true,
            ])
            ->assertRedirect(route('admin.accounts.index'))
            ->assertSessionHas('success');

        $system->refresh();
        $this->assertSame('اسم جديد', $system->name);
        $this->assertSame('9950', $system->code);
        $this->assertTrue((bool) $system->is_active);

        $posted = $this->account('9960', ['name' => 'قبل']);
        app(AccountingService::class)->post(
            eventType: 'coa_probe2',
            source: null,
            branchId: $this->branch->id,
            postedOn: now()->toDateString(),
            description: 'حركة اختبار',
            lines: [
                ['account' => $posted->code, 'debit' => 5, 'credit' => 0],
                ['account' => AccountingService::CASH, 'debit' => 0, 'credit' => 5],
            ],
            createdBy: $this->admin->id,
        );

        $this->actingAs($this->admin)
            ->withSession(['active_branch_id' => $this->branch->id])
            ->withHeader('X-Requested-With', 'XMLHttpRequest')
            ->patch(route('admin.accounts.update', $posted), [
                'code' => '9960', 'name' => 'بعد', 'description' => '',
                'type' => 'expense', 'normal_balance' => 'debit',
                'display_order' => 0, 'parent_account_id' => '', 'is_active' => true,
            ])
            ->assertRedirect(route('admin.accounts.index'))
            ->assertSessionHas('success');

        $this->assertSame('بعد', $posted->fresh()->name);
    }

    public function test_toggle_and_destroy_flash_instead_of_returning_json(): void
    {
        $account = $this->account('9920');

        $this->actingAs($this->admin)
            ->withSession(['active_branch_id' => $this->branch->id])
            ->withHeader('X-Requested-With', 'XMLHttpRequest')
            ->patch(route('admin.accounts.toggle', $account))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertFalse((bool) $account->fresh()->is_active);

        $system = $this->account('9930', ['is_system' => true]);

        $this->actingAs($this->admin)
            ->withSession(['active_branch_id' => $this->branch->id])
            ->withHeader('X-Requested-With', 'XMLHttpRequest')
            ->delete(route('admin.accounts.destroy', $system))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertDatabaseHas('accounts', ['code' => '9930']);

        $this->actingAs($this->admin)
            ->withSession(['active_branch_id' => $this->branch->id])
            ->withHeader('X-Requested-With', 'XMLHttpRequest')
            ->delete(route('admin.accounts.destroy', $account))
            ->assertRedirect(route('admin.accounts.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('accounts', ['code' => '9920']);
    }
}
