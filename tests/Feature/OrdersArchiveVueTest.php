<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Order;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Table;
use App\Models\User;
use App\Support\BranchContext;
use Database\Seeders\PermissionSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * أرشيف الطلبات على Inertia/Vue.
 *
 * Three things make this screen worth pinning hard:
 *
 *  1. `OrderController@archive` was LOST in the Wave 3 rewrite; the route
 *     and the sidebar leaf pointed at a method that no longer existed, so
 *     the page was a hard 500. These tests are the guard against losing it
 *     again.
 *  2. The original computed every KPI off `$query->getQuery()`, which
 *     drops BranchScope + SoftDeletingScope. The cards read across ALL
 *     branches while the table below them was scoped — a real leak. The
 *     branch-scoping test below fails on the original code.
 *  3. Prep-time arithmetic must stay signed in MySQL strict mode so an order
 *     that finishes early cannot overflow the archive aggregates.
 *
 * Assertions are on props (Inertia\Testing), never on markup.
 */
class OrdersArchiveVueTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $north;

    protected Branch $south;

    protected User $manager;

    protected int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->north = Branch::create(['code' => 'arx-n', 'name' => 'الفرع الشمالي', 'is_active' => true, 'display_order' => 1]);
        $this->south = Branch::create(['code' => 'arx-s', 'name' => 'الفرع الجنوبي', 'is_active' => true, 'display_order' => 2]);

        BranchContext::set($this->north->id);

        $this->seed(PermissionSeeder::class);

        $this->manager = $this->staff('manager', 'arx_manager', $this->north);
    }

    protected function tearDown(): void
    {
        BranchContext::clear();
        parent::tearDown();
    }

    /** `users` has no branch_id — membership is primary_branch_id + the pivot. */
    protected function staff(string $role, string $username, Branch ...$branches): User
    {
        Role::firstOrCreate(['name' => $role], ['label' => $role, 'is_system' => true]);

        $user = User::create([
            'name' => $username,
            'username' => $username,
            'password' => bcrypt('x'),
            'status' => 'active',
            'primary_branch_id' => $branches[0]->id,
            'role' => $role,
        ]);

        foreach ($branches as $branch) {
            $user->branches()->attach($branch->id);
        }

        return $user;
    }

    protected function table(Branch $branch, string $number): Table
    {
        return Model::unguarded(fn () => Table::withoutGlobalScopes()->create([
            'branch_id' => $branch->id,
            'number' => $number,
            'capacity' => 4,
            'status' => 'available',
            'active' => true,
        ]));
    }

    /**
     * branch_id and created_at are NOT fillable on Order (the trait fills
     * branch_id from BranchContext), so fixtures go through unguarded().
     */
    protected function order(Branch $branch, array $attrs = []): Order
    {
        $this->seq++;

        return Model::unguarded(fn () => Order::create(array_merge([
            'branch_id' => $branch->id,
            'number' => sprintf('ORD-20260801-%04d', $this->seq),
            'status' => 'completed',
            'order_type' => 'dine_in',
            'order_source' => 'dine_in',
            'subtotal' => 100,
            'total' => 100,
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
        ], $attrs)));
    }

    protected function archive(array $query = [], ?User $as = null)
    {
        return $this->actingAs($as ?? $this->manager)
            ->get(route('admin.orders.archive', $query));
    }

    /** Order numbers present on the current page, in order. */
    protected function numbers(Assert $page): array
    {
        return array_column($page->toArray()['props']['orders']['data'], 'number');
    }

    // ── The route works again ────────────────────────────────────────

    public function test_the_archive_route_renders_the_vue_page(): void
    {
        $this->order($this->north, ['number' => 'ORD-20260801-9001']);

        $this->archive()
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Orders/Archive')
                ->has('shell')
                ->has('orders.data', 1)
                ->has('orders.links')
                ->where('orders.total', 1)
                ->where('showBranchColumn', false)
                ->where('hasQuery', false)
                ->has('options.statuses', 7)
                ->missing('options.sources')
                ->missing('options.orderTypes')
                ->missing('summary')
                ->missing('sourceBreakdown')
                ->where('urls.archive', route('admin.orders.archive'))
            );
    }

    public function test_filters_echo_back_normalized_and_default_to_the_last_thirty_days(): void
    {
        $this->archive()->assertInertia(fn (Assert $page) => $page
            ->where('filters.from', now()->subDays(30)->toDateString())
            ->where('filters.to', now()->toDateString())
            ->where('filters.status', [])
            ->where('filters.search', null)
            ->where('filters.sort', 'created_at')
            ->where('filters.dir', 'desc')
            ->where('filters.delayed_only', false)
        );

        // Garbage in the date box used to build 'drop 00:00:00' and match
        // nothing with no explanation; it now falls back to the default.
        $this->archive(['from' => 'drop', 'dir' => 'sideways', 'sort' => 'rm'])
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.from', now()->subDays(30)->toDateString())
                ->where('filters.dir', 'desc')
                ->where('filters.sort', 'created_at')
                ->where('hasQuery', true)
            );
    }

    // ── Gates ────────────────────────────────────────────────────────

    public function test_the_archive_gate_is_role_or_permission_and_denies_everyone_else(): void
    {
        $chef = $this->staff('chef', 'arx_chef', $this->north);

        $this->archive([], $chef)->assertForbidden();

        $chef->permissions()->attach(
            Permission::where('name', 'orders.archive')->value('id'),
            ['granted' => true]
        );

        $this->archive([], $chef->fresh())->assertOk();
    }

    public function test_the_paginated_table_is_scoped_to_the_users_branch(): void
    {
        $this->order($this->north, ['number' => 'ORD-20260801-1001', 'total' => 100]);
        $this->order($this->south, ['number' => 'ORD-20260801-1002', 'total' => 500]);

        $this->archive()->assertInertia(fn (Assert $page) => $page
            ->has('orders.data', 1)
            ->where('orders.data.0.number', 'ORD-20260801-1001')
        );
    }

    /**
     * The old screen built its KPI cards from a scope-free query builder, so
     * a north manager could read the south branch's money from the header.
     */
    public function test_the_kpi_cards_are_branch_scoped_exactly_like_the_table(): void
    {
        $this->order($this->north, ['total' => 100]);
        $this->order($this->south, ['total' => 500]);
        $this->order($this->south, ['total' => 900]);

        $this->archive()->assertInertia(fn (Assert $page) => $page
            ->where('stats.count', 1)
            ->where('stats.gross', fn ($v) => (float) $v === 100.0)
            ->where('stats.avg', fn ($v) => (float) $v === 100.0)
            ->missing('summary')
            ->missing('sourceBreakdown')
        );
    }

    public function test_soft_deleted_orders_stay_out_of_the_cards(): void
    {
        $this->order($this->north, ['total' => 100]);
        $this->order($this->north, ['total' => 400])->delete();

        $this->archive()->assertInertia(fn (Assert $page) => $page
            ->where('stats.count', 1)
            ->where('stats.gross', fn ($v) => (float) $v === 100.0)
        );
    }

    // ── Operational stats ────────────────────────────────────────────

    public function test_the_stat_rail_describes_the_filtered_set_without_marketplace_math(): void
    {
        $this->order($this->north, ['total' => 100, 'status' => 'completed']);
        $this->order($this->north, ['total' => 200, 'status' => 'completed']);
        $this->order($this->north, [
            'total' => 300, 'status' => 'cancelled',
        ]);

        $this->archive()->assertInertia(fn (Assert $page) => $page
            ->where('stats.count', 3)
            ->where('stats.countLabel', '3')
            ->where('stats.gross', fn ($v) => (float) $v === 600.0)
            ->where('stats.grossLabel', '600.00 ₪')
            ->where('stats.avg', fn ($v) => (float) $v === 200.0)
            ->where('stats.cancelled', 1)
            ->missing('summary')
            ->missing('sourceBreakdown')
        );
    }

    public function test_rows_use_the_restaurants_two_real_order_channels(): void
    {
        $table = $this->table($this->north, 'A-1');
        $this->order($this->north, ['number' => 'ORD-20260801-1101', 'table_id' => $table->id]);
        $this->order($this->north, ['number' => 'ORD-20260801-1102', 'table_id' => null]);

        $this->archive(['sort' => 'number', 'dir' => 'asc'])
            ->assertInertia(fn (Assert $page) => $page
                ->where('orders.data.0.channelLabel', 'طلب طاولة')
                ->where('orders.data.0.channelIcon', 'bi-grid-3x3-gap')
                ->where('orders.data.1.channelLabel', 'طلب هاتفي')
                ->where('orders.data.1.channelIcon', 'bi-telephone')
            );
    }

    // ── Filters ──────────────────────────────────────────────────────

    public function test_the_date_range_filter_bounds_both_ends_of_the_day(): void
    {
        $this->order($this->north, ['number' => 'ORD-20260801-2001', 'created_at' => now()->subDays(40)]);
        $this->order($this->north, ['number' => 'ORD-20260801-2002', 'created_at' => now()->subDays(5)->setTime(23, 55)]);
        $this->order($this->north, ['number' => 'ORD-20260801-2003', 'created_at' => now()->subDays(1)]);

        // Default window is the last 30 days — the 40-day-old one is out.
        $this->archive()->assertInertia(fn (Assert $page) => $this->assertSame(
            ['ORD-20260801-2003', 'ORD-20260801-2002'], $this->numbers($page)
        ));

        // A single-day window still catches 23:55 (the ' 23:59:59' bound).
        $this->archive([
            'from' => now()->subDays(5)->toDateString(),
            'to' => now()->subDays(5)->toDateString(),
        ])->assertInertia(fn (Assert $page) => $this->assertSame(
            ['ORD-20260801-2002'], $this->numbers($page)
        ));

        // Widening the window brings the old one back.
        $this->archive(['from' => now()->subDays(60)->toDateString()])
            ->assertInertia(fn (Assert $page) => $page->where('stats.count', 3));
    }

    public function test_status_is_a_multi_select_and_bogus_values_are_dropped(): void
    {
        $this->order($this->north, ['number' => 'ORD-20260801-3001', 'status' => 'completed']);
        $this->order($this->north, ['number' => 'ORD-20260801-3002', 'status' => 'cancelled']);
        $this->order($this->north, ['number' => 'ORD-20260801-3003', 'status' => 'ready']);

        $this->archive(['status' => ['cancelled', 'ready']])
            ->assertInertia(fn (Assert $page) => $page
                ->where('stats.count', 2)
                ->where('filters.status', ['cancelled', 'ready'])
                ->where('hasQuery', true)
            );

        // An unknown status is filtered out entirely — it must not become
        // whereIn('status', ['nope']) and blank the page.
        $this->archive(['status' => ['nope']])
            ->assertInertia(fn (Assert $page) => $page
                ->where('stats.count', 3)
                ->where('filters.status', [])
            );
    }

    public function test_table_filter_is_branch_scoped_and_legacy_marketplace_filters_are_ignored(): void
    {
        $t1 = $this->table($this->north, 'A-1');
        $t2 = $this->table($this->north, 'A-2');

        $this->order($this->north, ['number' => 'ORD-20260801-4001', 'table_id' => null]);
        $this->order($this->north, ['number' => 'ORD-20260801-4002', 'table_id' => $t1->id]);
        $this->order($this->north, ['number' => 'ORD-20260801-4003', 'table_id' => $t2->id]);

        $this->archive(['source' => 'delivery', 'order_type' => 'takeaway'])
            ->assertInertia(fn (Assert $page) => $page
                ->where('stats.count', 3)
                ->missing('filters.source')
                ->missing('filters.order_type')
                ->where('hasQuery', false)
            );

        $this->archive(['table_id' => $t2->id])->assertInertia(fn (Assert $page) => $page
            ->where('stats.count', 1)
            ->where('orders.data.0.number', 'ORD-20260801-4003')
            ->where('orders.data.0.tableLabel', 'A-2')
        );

        // The table dropdown is branch-scoped like everything else.
        $this->archive()->assertInertia(fn (Assert $page) => $page->has('options.tables', 2));
    }

    public function test_the_total_range_filter_is_inclusive_and_ignores_empty_bounds(): void
    {
        $this->order($this->north, ['number' => 'ORD-20260801-5001', 'total' => 50]);
        $this->order($this->north, ['number' => 'ORD-20260801-5002', 'total' => 150]);
        $this->order($this->north, ['number' => 'ORD-20260801-5003', 'total' => 250]);

        $this->archive(['min_total' => 150, 'max_total' => 250])
            ->assertInertia(fn (Assert $page) => $page
                ->where('stats.count', 2)
                ->where('filters.min_total', '150')
                ->where('filters.max_total', '250')
            );

        $this->archive(['min_total' => '', 'max_total' => 150])
            ->assertInertia(fn (Assert $page) => $page
                ->where('stats.count', 2)
                ->where('filters.min_total', null)
            );
    }

    public function test_search_covers_the_four_fields_an_operator_actually_knows(): void
    {
        $this->order($this->north, ['number' => 'ORD-20260801-6001']);
        $this->order($this->north, ['number' => 'ORD-20260801-6002', 'customer_notes' => 'بدون بصل زيادة']);
        $this->order($this->north, ['number' => 'ORD-20260801-6003', 'customer_name' => 'سلمى الحلبي']);
        $this->order($this->north, ['number' => 'ORD-20260801-6004', 'customer_phone' => '0599887766']);

        $cases = [
            'ORD-20260801-6001' => 'ORD-20260801-6001',
            'بصل زيادة' => 'ORD-20260801-6002',
            'الحلبي' => 'ORD-20260801-6003',
            '0599887766' => 'ORD-20260801-6004',
        ];

        foreach ($cases as $term => $expected) {
            $this->archive(['search' => $term])
                ->assertInertia(function (Assert $page) use ($term, $expected) {
                    $this->assertSame([$expected], $this->numbers($page), "search «{$term}»");
                });
        }

        // The echoed value is trimmed — the Blade echoed it raw.
        $this->archive(['search' => '  الحلبي  '])
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.search', 'الحلبي')
                ->where('stats.count', 1)
            );
    }

    public function test_sorting_by_total_and_number_in_both_directions(): void
    {
        $this->order($this->north, ['number' => 'ORD-20260801-7001', 'total' => 300]);
        $this->order($this->north, ['number' => 'ORD-20260801-7002', 'total' => 100]);
        $this->order($this->north, ['number' => 'ORD-20260801-7003', 'total' => 200]);

        $this->archive(['sort' => 'total', 'dir' => 'asc'])
            ->assertInertia(fn (Assert $page) => $this->assertSame(
                ['ORD-20260801-7002', 'ORD-20260801-7003', 'ORD-20260801-7001'], $this->numbers($page)
            ));

        $this->archive(['sort' => 'number', 'dir' => 'desc'])
            ->assertInertia(fn (Assert $page) => $this->assertSame(
                ['ORD-20260801-7003', 'ORD-20260801-7002', 'ORD-20260801-7001'], $this->numbers($page)
            ));
    }

    // ── Prep timing (the TIMESTAMPDIFF portability corner) ───────────

    /** @return array<string, Order> */
    protected function timingFixtures(): array
    {
        $at = now()->subDays(2)->setTime(10, 0);

        return [
            // 15 actual vs 10 estimated → late.
            'late' => $this->order($this->north, [
                'number' => 'ORD-20260801-8001', 'created_at' => $at,
                'estimated_prep_minutes' => 10,
                'prep_started_at' => $at->copy(), 'ready_at' => $at->copy()->addMinutes(15),
            ]),
            // 15 actual vs 20 estimated → on time (early).
            'ontime' => $this->order($this->north, [
                'number' => 'ORD-20260801-8002', 'created_at' => $at,
                'estimated_prep_minutes' => 20,
                'prep_started_at' => $at->copy(), 'ready_at' => $at->copy()->addMinutes(15),
            ]),
            // ready_at BEFORE prep_started_at → inconsistent, excluded.
            'bogus' => $this->order($this->north, [
                'number' => 'ORD-20260801-8003', 'created_at' => $at,
                'estimated_prep_minutes' => 10,
                'prep_started_at' => $at->copy(), 'ready_at' => $at->copy()->subMinutes(10),
            ]),
            // Still cooking — no ready_at.
            'cooking' => $this->order($this->north, [
                'number' => 'ORD-20260801-8004', 'created_at' => $at,
                'estimated_prep_minutes' => 10,
                'prep_started_at' => now()->subMinutes(7),
            ]),
            // Never entered preparing.
            'none' => $this->order($this->north, [
                'number' => 'ORD-20260801-8005', 'created_at' => $at,
            ]),
        ];
    }

    public function test_delayed_only_selects_just_the_late_orders(): void
    {
        $this->timingFixtures();

        $this->archive(['delayed_only' => 1])
            ->assertInertia(function (Assert $page) {
                $this->assertSame(['ORD-20260801-8001'], $this->numbers($page));
                $page->where('stats.count', 1)
                    ->where('filters.delayed_only', true);
            });

        // Off by default — the hidden-0 companion is gone, so an absent key
        // must mean "everything".
        $this->archive()->assertInertia(fn (Assert $page) => $page
            ->where('stats.count', 5)
            ->where('filters.delayed_only', false)
        );

        $this->archive(['delayed_only' => 0])
            ->assertInertia(fn (Assert $page) => $page->where('stats.count', 5));
    }

    public function test_the_prep_timing_kpis_measure_only_consistent_orders(): void
    {
        $this->timingFixtures();

        $this->archive()->assertInertia(fn (Assert $page) => $page
            ->where('timing.show', true)
            ->where('timing.measured', 2)          // bogus + cooking + none excluded
            ->where('timing.onTime', 1)
            ->where('timing.late', 1)
            ->where('timing.onTimePct', fn ($v) => (float) $v === 50.0)
            ->where('timing.onTimePctLabel', '50%')
            ->where('timing.onTimeColor', 'warning')
            ->where('timing.avgActual', fn ($v) => (float) $v === 15.0)
            ->where('timing.avgActualLabel', '15.0 د')
            // (+5) and (-5) average to zero — signed, so no MySQL overflow.
            ->where('timing.avgDelayLabel', '+0.0 د')
            ->where('timing.avgDelayColor', 'success')
        );
    }

    public function test_the_timing_rail_hides_itself_when_nothing_was_measured(): void
    {
        $this->order($this->north);

        $this->archive()->assertInertia(fn (Assert $page) => $page
            ->where('timing.show', false)
            ->where('timing.measured', 0)
            ->where('timing.onTimePct', null)
        );
    }

    public function test_each_row_ships_its_prep_timing_cell_precomputed(): void
    {
        $this->timingFixtures();

        $this->archive(['sort' => 'number', 'dir' => 'asc'])
            ->assertInertia(function (Assert $page) {
                $rows = collect($page->toArray()['props']['orders']['data'])->keyBy('number');

                $late = $rows['ORD-20260801-8001']['timing'];
                $this->assertSame('measured', $late['mode']);
                $this->assertSame(15, $late['actualMinutes']);
                $this->assertSame('10د', $late['estLabel']);
                $this->assertSame(5, $late['delta']);
                $this->assertSame('+5د', $late['deltaLabel']);
                $this->assertSame('arx-var--warn', $late['deltaClass']);
                $this->assertSame('متأخر بـ 5 دقيقة', $late['deltaTitle']);

                $ontime = $rows['ORD-20260801-8002']['timing'];
                $this->assertSame(-5, $ontime['delta']);
                $this->assertSame('-5د', $ontime['deltaLabel']);
                $this->assertSame('arx-var--good', $ontime['deltaClass']);

                $this->assertSame('bogus', $rows['ORD-20260801-8003']['timing']['mode']);
                $this->assertSame('cooking', $rows['ORD-20260801-8004']['timing']['mode']);
                $this->assertSame(7, $rows['ORD-20260801-8004']['timing']['cookingMinutes']);
                $this->assertSame('none', $rows['ORD-20260801-8005']['timing']['mode']);
            });
    }

    // ── Row payload ──────────────────────────────────────────────────

    public function test_a_row_carries_the_compact_operational_contract(): void
    {
        $t = $this->table($this->north, 'B-9');
        $this->order($this->north, [
            'number' => 'ORD-20260801-9101',
            'table_id' => $t->id,
            'status' => 'completed',
            'customer_name' => 'أبو خالد',
            'customer_phone' => '0591112223',
            'total' => 200,
        ]);

        $this->archive()->assertInertia(fn (Assert $page) => $page
            ->where('orders.data.0.tableLabel', 'B-9')
            ->where('orders.data.0.channelLabel', 'طلب طاولة')
            ->where('orders.data.0.channelIcon', 'bi-grid-3x3-gap')
            ->where('orders.data.0.statusColor', 'success')
            ->where('orders.data.0.totalLabel', '200.00 ₪')
            ->where('orders.data.0.itemsCount', 0)
            ->where('orders.data.0.customerName', 'أبو خالد')
            ->missing('orders.data.0.sourceLabel')
            ->missing('orders.data.0.typeLabel')
            ->missing('orders.data.0.netLabel')
            ->missing('orders.data.0.commissionPctLabel')
            ->where('orders.data.0.urls.show', fn ($v) => str_contains($v, '/orders/'))
        );
    }

    public function test_platform_metadata_and_routes_are_absent_from_the_clean_application(): void
    {
        $order = $this->order($this->north, ['number' => 'ORD-20260801-9301']);

        $this->assertFalse(Schema::hasColumn('orders', 'platform_commission_pct'));
        $this->assertFalse(Schema::hasColumn('orders', 'external_reference'));
        $this->assertFalse(Schema::hasColumn('orders', 'delivery_receiver'));

        $this->actingAs($this->manager)
            ->get("/admin/orders/{$order->id}/source")
            ->assertNotFound();

        $this->actingAs($this->manager)
            ->get('/admin/reports/sales-by-platform')
            ->assertNotFound();
    }

    /**
     * A typo'd or hand-edited ?sort= falls back to newest-first and IGNORES
     * ?dir=, exactly as the pre-migration screen's `->latest()` did. Honouring
     * dir on an unrecognized key hands the manager the OLDEST orders in the
     * window — the opposite of what they asked for.
     */
    public function test_an_unrecognized_sort_falls_back_to_newest_first_and_ignores_dir(): void
    {
        $oldest = $this->order($this->north, ['number' => 'ORD-20260801-9401']);
        $middle = $this->order($this->north, ['number' => 'ORD-20260801-9402']);
        $newest = $this->order($this->north, ['number' => 'ORD-20260801-9403']);

        $oldest->forceFill(['created_at' => now()->subDays(3)])->saveQuietly();
        $middle->forceFill(['created_at' => now()->subDays(2)])->saveQuietly();
        $newest->forceFill(['created_at' => now()->subDay()])->saveQuietly();

        $expected = ['ORD-20260801-9403', 'ORD-20260801-9402', 'ORD-20260801-9401'];

        $this->archive(['sort' => 'date', 'dir' => 'asc'])
            ->assertInertia(fn (Assert $page) => $this->assertSame($expected, $this->numbers($page)));

        $this->archive(['sort' => '', 'dir' => 'asc'])
            ->assertInertia(fn (Assert $page) => $this->assertSame($expected, $this->numbers($page)));

        // A RECOGNIZED key still honours dir — the fallback must not swallow it.
        $this->archive(['sort' => 'created_at', 'dir' => 'asc'])
            ->assertInertia(fn (Assert $page) => $this->assertSame(
                array_reverse($expected), $this->numbers($page)
            ));
    }
}
