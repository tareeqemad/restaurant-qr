<?php

namespace Tests\Feature;

use App\Enums\ReservationStatus;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Reservation;
use App\Models\Review;
use App\Models\User;
use App\Support\BranchContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Reviews end-to-end:
 *
 *   1. Eligibility — customer can only review their own COMPLETED reservation.
 *   2. Uniqueness — DB unique on reservation_id, second submission 403/Aborts.
 *   3. Ownership   — cannot delete another customer's review.
 *   4. Moderation  — manager hides/unhides reviews of their own branch only.
 *   5. Cross-branch isolation on listings + per-row ops.
 */
class CustomerReviewTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branchA;
    protected Branch $branchB;
    protected Customer $customer;
    protected User $managerA;
    protected User $managerB;

    protected function setUp(): void
    {
        parent::setUp();

        // BranchContext is a static façade — clear any leak from a prior
        // test in the same process so this suite starts unscoped.
        BranchContext::clear();

        $this->branchA = Branch::create(['code' => 'a', 'name' => 'Branch A', 'is_active' => true]);
        $this->branchB = Branch::create(['code' => 'b', 'name' => 'Branch B', 'is_active' => true]);

        $this->customer = Customer::create([
            'name'     => 'Reviewer',
            'phone'    => '0599111111',
            'password' => 'password123',
            'status'   => 'active',
        ]);

        $this->managerA = $this->makeManager('mgr_a', $this->branchA);
        $this->managerB = $this->makeManager('mgr_b', $this->branchB);
    }

    protected function makeManager(string $username, Branch $branch): User
    {
        $u = User::create([
            'name'     => 'Mgr ' . $username,
            'username' => $username,
            'role'     => 'manager',
            'status'   => 'active',
            'password' => Hash::make('test'),
        ]);
        $u->branches()->attach($branch->id, ['is_primary' => true, 'joined_at' => now()]);
        return $u;
    }

    /**
     * Build a Reservation directly. branch_id is set as a property
     * (not via mass-assignment) because the model intentionally keeps
     * it out of $fillable — see CustomerReservationTest::buildReservation
     * for the full rationale.
     */
    protected function makeReservation(int $branchId, int $customerId, ReservationStatus $status): Reservation
    {
        $r = new Reservation([
            'customer_id'  => $customerId,
            'reserved_for' => now()->subDay(),
            'party_size'   => 2,
            'status'       => $status->value,
        ]);
        $r->branch_id = $branchId;
        $r->save();

        return $r;
    }

    /** Same property-assignment trick for Review fixtures. */
    protected function makeReview(int $branchId, int $customerId, int $reservationId, array $extra = []): Review
    {
        $r = new Review([
            'customer_id'    => $customerId,
            'reservation_id' => $reservationId,
            'rating'         => $extra['rating'] ?? 4,
            'title'          => $extra['title'] ?? null,
            'body'           => $extra['body']  ?? null,
            'status'         => $extra['status'] ?? 'published',
        ]);
        $r->branch_id = $branchId;
        $r->save();

        return $r;
    }

    // ─── Eligibility ─────────────────────────────────────────────────

    public function test_customer_can_review_completed_reservation(): void
    {
        $res = $this->makeReservation($this->branchA->id, $this->customer->id, ReservationStatus::Completed);

        $this->actingAs($this->customer, 'customer')
            ->post("/portal/reviews/{$res->id}", [
                'rating' => 5,
                'title'  => 'تجربة رائعة',
                'body'   => 'الطعام والخدمة كانا ممتازين.',
            ])
            ->assertRedirect('/portal/reviews');

        $this->assertDatabaseHas('reviews', [
            'reservation_id' => $res->id,
            'customer_id'    => $this->customer->id,
            'branch_id'      => $this->branchA->id,
            'rating'         => 5,
            'status'         => 'published',
        ]);
    }

    public function test_customer_cannot_review_pending_reservation(): void
    {
        $res = $this->makeReservation($this->branchA->id, $this->customer->id, ReservationStatus::Pending);

        $this->actingAs($this->customer, 'customer')
            ->post("/portal/reviews/{$res->id}", ['rating' => 4])
            ->assertForbidden();
    }

    public function test_customer_cannot_review_someone_elses_reservation(): void
    {
        $other = Customer::create([
            'name' => 'Other', 'phone' => '0598222222',
            'password' => 'password123', 'status' => 'active',
        ]);
        $res = $this->makeReservation($this->branchA->id, $other->id, ReservationStatus::Completed);

        $this->actingAs($this->customer, 'customer')
            ->post("/portal/reviews/{$res->id}", ['rating' => 1])
            ->assertForbidden();
    }

    // ─── Uniqueness ──────────────────────────────────────────────────

    public function test_customer_cannot_double_review_same_reservation(): void
    {
        $res = $this->makeReservation($this->branchA->id, $this->customer->id, ReservationStatus::Completed);

        $this->actingAs($this->customer, 'customer');
        $this->post("/portal/reviews/{$res->id}", ['rating' => 5])->assertRedirect();
        $this->post("/portal/reviews/{$res->id}", ['rating' => 1])->assertForbidden();

        $this->assertSame(1,
            Review::withoutGlobalScopes()->where('reservation_id', $res->id)->count()
        );
    }

    public function test_rating_must_be_between_1_and_5(): void
    {
        $res = $this->makeReservation($this->branchA->id, $this->customer->id, ReservationStatus::Completed);

        $this->actingAs($this->customer, 'customer')
            ->from("/portal/reviews/{$res->id}/new")
            ->post("/portal/reviews/{$res->id}", ['rating' => 9])
            ->assertSessionHasErrors('rating');
    }

    // ─── Ownership ───────────────────────────────────────────────────

    public function test_customer_can_delete_own_review(): void
    {
        $res = $this->makeReservation($this->branchA->id, $this->customer->id, ReservationStatus::Completed);
        $review = $this->makeReview($this->branchA->id, $this->customer->id, $res->id);

        $this->actingAs($this->customer, 'customer')
            ->delete("/portal/reviews/{$review->id}")
            ->assertRedirect('/portal/reviews');

        $this->assertSoftDeleted('reviews', ['id' => $review->id]);
    }

    public function test_customer_cannot_delete_other_customers_review(): void
    {
        $other = Customer::create([
            'name' => 'O', 'phone' => '0598222222',
            'password' => 'password123', 'status' => 'active',
        ]);
        $res = $this->makeReservation($this->branchA->id, $other->id, ReservationStatus::Completed);
        $review = $this->makeReview($this->branchA->id, $other->id, $res->id);

        $this->actingAs($this->customer, 'customer')
            ->delete("/portal/reviews/{$review->id}")
            ->assertForbidden();
    }

    // ─── Moderation + cross-branch ───────────────────────────────────

    public function test_branch_manager_can_hide_review_in_their_branch(): void
    {
        $res = $this->makeReservation($this->branchA->id, $this->customer->id, ReservationStatus::Completed);
        $review = $this->makeReview($this->branchA->id, $this->customer->id, $res->id, [
            'rating' => 1, 'body' => 'spam',
        ]);

        $this->actingAs($this->managerA)
            ->post("/admin/reviews/{$review->id}/hide", ['hidden_reason' => 'spam'])
            ->assertRedirect();

        $review->refresh();
        $this->assertTrue($review->isHidden());
        $this->assertSame('spam', $review->hidden_reason);
        $this->assertSame($this->managerA->id, $review->hidden_by_user_id);
    }

    public function test_branch_b_manager_cannot_hide_branch_a_review(): void
    {
        $res = $this->makeReservation($this->branchA->id, $this->customer->id, ReservationStatus::Completed);
        $review = $this->makeReview($this->branchA->id, $this->customer->id, $res->id, ['rating' => 5]);

        $this->actingAs($this->managerB)
            ->post("/admin/reviews/{$review->id}/hide")
            ->assertForbidden();
    }

    public function test_admin_index_excludes_other_branches(): void
    {
        $resA = $this->makeReservation($this->branchA->id, $this->customer->id, ReservationStatus::Completed);
        $this->makeReview($this->branchA->id, $this->customer->id, $resA->id, ['rating' => 5]);

        // Reservation + review in branch B
        $other = Customer::create([
            'name' => 'B', 'phone' => '0598333333',
            'password' => 'password123', 'status' => 'active',
        ]);
        $resB = $this->makeReservation($this->branchB->id, $other->id, ReservationStatus::Completed);
        $this->makeReview($this->branchB->id, $other->id, $resB->id, ['rating' => 1]);

        $this->actingAs($this->managerA);
        \App\Support\BranchContext::set($this->branchA->id);

        $this->assertSame(1, Review::count(),
            'Manager A saw branch B\'s reviews despite BranchScope.');
    }
}
