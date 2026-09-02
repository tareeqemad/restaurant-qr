<?php

namespace Tests\Feature;

use App\Enums\ReservationStatus;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Reservation;
use App\Models\Review;
use App\Models\Role;
use App\Models\Table;
use App\Models\User;
use App\Support\BranchContext;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Wave 5 (MIGRATION-PILOT.md §13): the three admin boards on Inertia/Vue —
 * reservations, reviews, branches.
 *
 * These tests pin the Vue payloads and re-assert that the controller
 * endpoints still enforce their gates.
 */
class AdminBoardsVueTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::create(['code' => 'ab', 'name' => 'AB', 'is_active' => true]);
        BranchContext::set($this->branch->id);

        Role::firstOrCreate(['name' => 'super_admin'], ['label' => 'Super', 'is_system' => true]);
        $this->seed(PermissionSeeder::class);

        $this->admin = User::create([
            'name' => 'Boss', 'username' => 'ab_boss', 'password' => bcrypt('x'),
            'status' => 'active', 'primary_branch_id' => $this->branch->id, 'role' => 'super_admin',
        ]);
        $this->admin->branches()->attach($this->branch->id);
    }

    protected function tearDown(): void
    {
        BranchContext::clear();
        parent::tearDown();
    }

    // ── Reservations ─────────────────────────────────────────────────

    protected function makeReservation(array $attrs = []): Reservation
    {
        $customer = Customer::create([
            'name' => 'ضيف', 'phone' => '0599'.random_int(100000, 999999),
            'password' => bcrypt('x'), 'status' => 'active',
        ]);

        return Reservation::create(array_merge([
            'branch_id' => $this->branch->id,
            'customer_id' => $customer->id,
            'reference' => 'RSV-'.uniqid(),
            'party_size' => 4,
            'reserved_for' => now()->addHour(),
            'status' => ReservationStatus::Pending->value,
        ], $attrs));
    }

    public function test_the_reservations_board_flags_late_and_arriving_soon(): void
    {
        // Confirmed but its time has passed → late.
        $this->makeReservation([
            'status' => ReservationStatus::Confirmed->value,
            'reserved_for' => now()->subMinutes(20),
        ]);
        // Confirmed inside the 90-minute window → arriving soon.
        $this->makeReservation([
            'status' => ReservationStatus::Confirmed->value,
            'reserved_for' => now()->addMinutes(30),
        ]);

        $this->actingAs($this->admin)
            ->get(route('admin.reservations.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Reservations/Index')
                ->has('reservations.data', 2)
                ->where('stats.late', 1)
                ->where('stats.arrivingSoon', 1)
                ->has('statuses'));
    }

    public function test_the_unassigned_table_filter_narrows_the_list(): void
    {
        $table = Table::create([
            'branch_id' => $this->branch->id, 'number' => '5',
            'capacity' => 4, 'status' => 'available', 'active' => true,
        ]);
        $this->makeReservation(['table_id' => $table->id]);
        $this->makeReservation();   // no table

        $this->actingAs($this->admin)
            ->get(route('admin.reservations.index', ['table' => 'unassigned']))
            ->assertInertia(fn (Assert $page) => $page
                ->has('reservations.data', 1, fn (Assert $r) => $r
                    ->where('tableNumber', null)
                    ->etc()));
    }

    public function test_confirming_a_reservation_moves_it_through_the_state_machine(): void
    {
        $reservation = $this->makeReservation();

        $this->actingAs($this->admin)
            ->post(route('admin.reservations.confirm', $reservation))
            ->assertRedirect();

        $this->assertSame(ReservationStatus::Confirmed->value, $reservation->fresh()->status->value ?? $reservation->fresh()->status);
    }

    public function test_cancelling_stores_the_reason(): void
    {
        $reservation = $this->makeReservation(['status' => ReservationStatus::Confirmed->value]);

        $this->actingAs($this->admin)
            ->post(route('admin.reservations.cancel', $reservation), [
                'cancelled_reason' => 'الزبون أجّل',
            ])
            ->assertRedirect();

        $this->assertSame('الزبون أجّل', $reservation->fresh()->cancelled_reason);
    }

    // ── Reviews ──────────────────────────────────────────────────────

    /** A review always belongs to a visit — it is post-stay feedback. */
    protected function makeReview(array $attrs = []): Review
    {
        $reservation = $this->makeReservation(['status' => ReservationStatus::Completed->value]);

        return Review::create(array_merge([
            'branch_id' => $this->branch->id,
            'customer_id' => $reservation->customer_id,
            'reservation_id' => $reservation->id,
            'rating' => 5,
            'title' => 'ممتاز',
            'body' => 'الأكل كان رائع',
            'status' => 'published',
        ], $attrs));
    }

    public function test_the_reviews_board_ships_moderation_state(): void
    {
        $this->makeReview();
        $this->makeReview(['rating' => 1, 'title' => 'سيئ', 'body' => 'تأخير']);

        $this->actingAs($this->admin)
            ->get(route('admin.reviews.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Reviews/Index')
                ->has('reviews.data', 2)
                ->where('stats.low', 1)
                ->has('reviews.data.0.can.hide'));
    }

    public function test_the_low_rating_filter_is_the_moderators_real_question(): void
    {
        $this->makeReview();
        $this->makeReview(['rating' => 2]);

        $this->actingAs($this->admin)
            ->get(route('admin.reviews.index', ['rating' => 'low']))
            ->assertInertia(fn (Assert $page) => $page
                ->has('reviews.data', 1, fn (Assert $r) => $r->where('rating', 2)->etc()));
    }

    public function test_hiding_a_review_records_the_reason_and_unhiding_restores_it(): void
    {
        $review = $this->makeReview(['rating' => 1]);

        $this->actingAs($this->admin)
            ->post(route('admin.reviews.hide', $review), ['hidden_reason' => 'لغة غير لائقة'])
            ->assertRedirect();

        $review->refresh();
        $this->assertSame('hidden', $review->status);
        $this->assertSame('لغة غير لائقة', $review->hidden_reason);

        $this->actingAs($this->admin)
            ->post(route('admin.reviews.unhide', $review))
            ->assertRedirect();

        $this->assertSame('published', $review->fresh()->status);
    }

    public function test_the_hidden_reason_is_searchable(): void
    {
        $review = $this->makeReview();
        $review->hide('شتائم', $this->admin);

        $this->actingAs($this->admin)
            ->get(route('admin.reviews.index', ['search' => 'شتائم']))
            ->assertInertia(fn (Assert $page) => $page
                ->has('reviews.data', 1, fn (Assert $r) => $r
                    ->where('hiddenReason', 'شتائم')
                    ->etc()));
    }

    // ── Branches ─────────────────────────────────────────────────────

    public function test_the_branch_create_and_edit_pages_share_the_vue_form_contract(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.branches.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Branches/Form')
                ->where('branch.id', null)
                ->where('branch.isActive', true)
                ->where('branch.customerTaxDisplay', 'inherit')
                ->where('urls.submit', route('admin.branches.store')));

        $other = Branch::create([
            'code' => 'north',
            'name' => 'فرع الشمال',
            'city' => 'غزة',
            'settings' => ['customer_tax_display' => 'inclusive'],
            'is_active' => false,
        ]);

        $this->actingAs($this->admin)
            ->get(route('admin.branches.edit', $other))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Branches/Form')
                ->where('branch.id', $other->id)
                ->where('branch.name', 'فرع الشمال')
                ->where('branch.city', 'غزة')
                ->where('branch.isActive', false)
                ->where('branch.customerTaxDisplay', 'inclusive')
                ->where('urls.submit', route('admin.branches.update', $other)));
    }

    public function test_the_user_create_page_is_a_focused_vue_onboarding_form(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.users.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Users/Form')
                ->where('user.id', null)
                ->where('user.status', 'active')
                ->has('roles', 9)
                ->has('branches', 1, fn (Assert $branch) => $branch
                    ->where('id', $this->branch->id)
                    ->where('accessible', true)
                    ->where('assigned', false)
                    ->etc())
                ->where('canManagePermissions', true)
                ->where('urls.submit', route('admin.users.store')));
    }

    public function test_the_user_edit_page_preserves_branch_and_primary_assignment_in_vue(): void
    {
        $waiter = User::create([
            'name' => 'أحمد الجرسون',
            'username' => 'ahmad_waiter',
            'password' => bcrypt('x'),
            'role' => 'waiter',
            'status' => 'active',
        ]);
        $waiter->branches()->attach($this->branch->id, [
            'is_primary' => true,
            'joined_at' => now(),
        ]);

        $this->actingAs($this->admin)
            ->get(route('admin.users.edit', $waiter))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Users/Form')
                ->where('user.id', $waiter->id)
                ->where('user.role', 'waiter')
                ->where('user.branchIds', [$this->branch->id])
                ->where('user.primaryBranchId', $this->branch->id)
                ->where('urls.submit', route('admin.users.update', $waiter)));
    }

    public function test_the_branches_board_names_the_current_branch_and_blocks_unsafe_deletes(): void
    {
        // The admin is attached to $this->branch, so it has staff on it.
        $empty = Branch::create(['code' => 'em', 'name' => 'فرع فاضي', 'is_active' => true]);

        $this->actingAs($this->admin)
            ->get(route('admin.branches.index'))
            ->assertOk()
            ->assertInertia(function (Assert $page) use ($empty) {
                $page->component('Admin/Branches/Index')->has('branches.data', 2);

                $rows = collect($page->toArray()['props']['branches']['data']);
                $current = $rows->firstWhere('id', $this->branch->id);
                $other = $rows->firstWhere('id', $empty->id);

                // The branch the operator stands in is named as such…
                $this->assertTrue($current['isCurrent']);
                // …and one with staff assigned announces that delete is barred.
                $this->assertTrue($current['blocksDelete']);
                $this->assertFalse($other['isCurrent']);
                $this->assertFalse($other['blocksDelete']);
            });
    }

    public function test_branches_workspace_uses_existing_icons_and_adaptive_cards(): void
    {
        $index = file_get_contents(resource_path('js/Pages/Admin/Branches/Index.vue'));
        $form = file_get_contents(resource_path('js/Pages/Admin/Branches/Form.vue'));
        $icons = file_get_contents(public_path('assets/dashtic/icon-fonts/bootstrap-icons/icons/font/bootstrap-icons.css'));

        $this->assertStringContainsString('repeat(auto-fit', $index);
        $this->assertStringContainsString('bi-diagram-3-fill', $index);
        $this->assertStringContainsString('branch.rolesCount', $index);
        $this->assertStringNotContainsString('repeat(auto-fill', $index);

        preg_match_all('/bi-[a-z0-9-]+/', $index.$form, $matches);
        foreach (array_unique($matches[0]) as $icon) {
            $this->assertStringContainsString(
                ".{$icon}::before",
                $icons,
                "The branches UI references an icon unavailable in the bundled Bootstrap Icons font: {$icon}"
            );
        }
    }

    public function test_toggling_a_branch_flips_its_active_flag(): void
    {
        $branch = Branch::create(['code' => 'tg', 'name' => 'فرع', 'is_active' => true]);

        $this->actingAs($this->admin)
            ->patch(route('admin.branches.toggle-status', $branch))
            ->assertRedirect();

        $this->assertFalse((bool) $branch->fresh()->is_active);
    }

    public function test_a_branch_with_staff_cannot_be_deleted(): void
    {
        $this->actingAs($this->admin)
            ->delete(route('admin.branches.destroy', $this->branch))
            ->assertSessionHas('error');

        $this->assertNotNull(Branch::find($this->branch->id));
    }
}
