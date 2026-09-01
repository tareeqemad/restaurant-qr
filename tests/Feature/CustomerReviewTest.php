<?php

namespace Tests\Feature;

use App\Enums\ReservationStatus;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Reservation;
use App\Models\Review;
use App\Models\Role;
use App\Models\User;
use App\Support\BranchContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Review moderation after retiring the customer portal account.
 * Historic reviews remain branch-scoped and manageable by staff, while the
 * removed authenticated customer write endpoints must never come back.
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
        BranchContext::clear();

        $this->branchA = Branch::create(['code' => 'a', 'name' => 'Branch A', 'is_active' => true]);
        $this->branchB = Branch::create(['code' => 'b', 'name' => 'Branch B', 'is_active' => true]);
        $this->customer = Customer::create([
            'name' => 'Reviewer',
            'phone' => '0599111111',
            'status' => 'active',
        ]);
        Role::firstOrCreate(['name' => 'manager'], [
            'label' => 'manager',
            'is_system' => true,
        ]);
        $this->seed(\Database\Seeders\PermissionSeeder::class);
        $this->managerA = $this->makeManager('mgr_a', $this->branchA);
        $this->managerB = $this->makeManager('mgr_b', $this->branchB);
    }

    protected function makeManager(string $username, Branch $branch): User
    {
        $user = User::create([
            'name' => 'Mgr '.$username,
            'username' => $username,
            'role' => 'manager',
            'status' => 'active',
            'password' => Hash::make('test'),
        ]);
        $user->branches()->attach($branch->id, ['is_primary' => true, 'joined_at' => now()]);

        return $user;
    }

    protected function makeReservation(int $branchId, int $customerId, ReservationStatus $status): Reservation
    {
        $reservation = new Reservation([
            'customer_id' => $customerId,
            'reserved_for' => now()->subDay(),
            'party_size' => 2,
            'status' => $status->value,
        ]);
        $reservation->branch_id = $branchId;
        $reservation->save();

        return $reservation;
    }

    protected function makeReview(int $branchId, int $customerId, int $reservationId, array $extra = []): Review
    {
        $review = new Review([
            'customer_id' => $customerId,
            'reservation_id' => $reservationId,
            'rating' => $extra['rating'] ?? 4,
            'title' => $extra['title'] ?? null,
            'body' => $extra['body'] ?? null,
            'status' => $extra['status'] ?? 'published',
        ]);
        $review->branch_id = $branchId;
        $review->save();

        return $review;
    }

    public function test_retired_customer_portal_review_writes_stay_closed(): void
    {
        $this->post('/portal/reviews/1', ['rating' => 5])->assertNotFound();
        $this->delete('/portal/reviews/1')->assertNotFound();

        $this->assertDatabaseCount('reviews', 0);
    }

    public function test_branch_manager_can_hide_review_in_their_branch(): void
    {
        $reservation = $this->makeReservation($this->branchA->id, $this->customer->id, ReservationStatus::Completed);
        $review = $this->makeReview($this->branchA->id, $this->customer->id, $reservation->id, [
            'rating' => 1,
            'body' => 'spam',
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
        $reservation = $this->makeReservation($this->branchA->id, $this->customer->id, ReservationStatus::Completed);
        $review = $this->makeReview($this->branchA->id, $this->customer->id, $reservation->id, ['rating' => 5]);

        $this->actingAs($this->managerB)
            ->post("/admin/reviews/{$review->id}/hide")
            ->assertForbidden();
    }

    public function test_admin_index_excludes_other_branches(): void
    {
        $reservationA = $this->makeReservation($this->branchA->id, $this->customer->id, ReservationStatus::Completed);
        $this->makeReview($this->branchA->id, $this->customer->id, $reservationA->id, ['rating' => 5]);

        $other = Customer::create(['name' => 'B', 'phone' => '0598333333', 'status' => 'active']);
        $reservationB = $this->makeReservation($this->branchB->id, $other->id, ReservationStatus::Completed);
        $this->makeReview($this->branchB->id, $other->id, $reservationB->id, ['rating' => 1]);

        $this->actingAs($this->managerA);
        BranchContext::set($this->branchA->id);

        $this->assertSame(1, Review::count(), 'Manager A saw branch B reviews despite BranchScope.');
    }
}
