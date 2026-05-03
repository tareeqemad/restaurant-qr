<?php

namespace Tests\Feature;

use App\Enums\ReservationStatus;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Reservation;
use App\Models\User;
use App\Support\BranchContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * End-to-end coverage of the customer portal + admin reservation lifecycle:
 *
 *   - Customer registration / login / logout
 *   - Reservation creation from the portal
 *   - Customer cancelling their own reservation, refusing to cancel others'
 *   - Admin staff confirming/seating/completing within their branch
 *   - Cross-branch isolation: branch B's manager cannot see/touch branch A's
 *     reservations
 *   - State machine integrity (illegal transitions throw)
 */
class CustomerReservationTest extends TestCase
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
            'name'     => 'Test Diner',
            'phone'    => '0599000000',
            'email'    => 'diner@test.com',
            'password' => 'password123',  // hashed via cast
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
     * Build a Reservation directly — `branch_id` is intentionally not in
     * the model's $fillable (security: stops mass-assignment from a
     * spoofed payload from moving a row to another branch). Tests bypass
     * mass-assignment by setting it as a property.
     */
    protected function buildReservation(
        int $branchId,
        int $customerId,
        ReservationStatus $status,
        ?\DateTimeInterface $when = null,
    ): Reservation {
        $r = new Reservation([
            'customer_id'  => $customerId,
            'reserved_for' => $when ?? now()->addDay(),
            'party_size'   => 2,
            'status'       => $status->value,
        ]);
        $r->branch_id = $branchId;
        $r->save();

        return $r;
    }

    // ─── Auth ────────────────────────────────────────────────────────

    public function test_customer_can_register(): void
    {
        $this->post('/portal/register', [
            'name'                  => 'New Diner',
            'phone'                 => '0598111111',
            'password'              => 'password123',
            'password_confirmation' => 'password123',
        ])->assertRedirect('/portal');

        $this->assertDatabaseHas('customers', [
            'phone' => '0598111111',
            'name'  => 'New Diner',
        ]);
        $this->assertAuthenticatedAs(Customer::where('phone', '0598111111')->first(), 'customer');
    }

    public function test_customer_can_login_with_phone_or_email(): void
    {
        // Phone
        $this->post('/portal/login', [
            'identifier' => '0599000000',
            'password'   => 'password123',
        ])->assertRedirect();
        $this->assertAuthenticatedAs($this->customer, 'customer');
        $this->post('/portal/logout');

        // Email
        $this->post('/portal/login', [
            'identifier' => 'diner@test.com',
            'password'   => 'password123',
        ])->assertRedirect();
        $this->assertAuthenticatedAs($this->customer, 'customer');
    }

    public function test_blocked_customer_cannot_login(): void
    {
        $this->customer->update(['status' => 'blocked']);

        $this->from('/portal/login')->post('/portal/login', [
            'identifier' => '0599000000',
            'password'   => 'password123',
        ])->assertRedirect('/portal/login')
          ->assertSessionHasErrors('identifier');

        $this->assertGuest('customer');
    }

    // ─── Portal reservation creation ─────────────────────────────────

    public function test_customer_can_create_reservation(): void
    {
        $this->actingAs($this->customer, 'customer');

        $this->post('/portal/reservations', [
            'branch_id'      => $this->branchA->id,
            'reserved_for'   => now()->addDays(2)->format('Y-m-d H:i:s'),
            'party_size'     => 4,
            'customer_notes' => 'Window seat please',
        ])->assertRedirect('/portal/reservations');

        $this->assertDatabaseHas('reservations', [
            'customer_id' => $this->customer->id,
            'branch_id'   => $this->branchA->id,
            'party_size'  => 4,
        ]);

        $reservation = Reservation::withoutGlobalScopes()
            ->where('customer_id', $this->customer->id)->first();
        $this->assertNotNull($reservation);
        $this->assertStringStartsWith('RV-', $reservation->reference);
    }

    public function test_customer_cannot_book_in_the_past(): void
    {
        $this->actingAs($this->customer, 'customer');

        $this->from('/portal/reservations/new')
            ->post('/portal/reservations', [
                'branch_id'    => $this->branchA->id,
                'reserved_for' => now()->subDay()->format('Y-m-d H:i:s'),
                'party_size'   => 2,
            ])
            ->assertSessionHasErrors('reserved_for');
    }

    public function test_customer_can_cancel_own_reservation(): void
    {
        $r = $this->buildReservation($this->branchA->id, $this->customer->id, ReservationStatus::Confirmed);

        $this->actingAs($this->customer, 'customer')
            ->post("/portal/reservations/{$r->id}/cancel")
            ->assertRedirect();

        $this->assertSame(
            ReservationStatus::Cancelled,
            $r->fresh()->status
        );
    }

    public function test_customer_cannot_cancel_someone_elses_reservation(): void
    {
        $other = Customer::create([
            'name' => 'Other', 'phone' => '0598222222',
            'password' => 'password123', 'status' => 'active',
        ]);
        $r = $this->buildReservation($this->branchA->id, $other->id, ReservationStatus::Confirmed);

        $this->actingAs($this->customer, 'customer')
            ->post("/portal/reservations/{$r->id}/cancel")
            ->assertForbidden();
    }

    // ─── Admin staff lifecycle ───────────────────────────────────────

    public function test_branch_a_manager_can_confirm_branch_a_reservation(): void
    {
        $r = $this->buildReservation($this->branchA->id, $this->customer->id, ReservationStatus::Pending);

        $this->actingAs($this->managerA)
            ->post("/admin/reservations/{$r->id}/confirm")
            ->assertRedirect();

        $r->refresh();
        $this->assertSame(ReservationStatus::Confirmed, $r->status);
        $this->assertSame($this->managerA->id, $r->confirmed_by_user_id);
    }

    public function test_branch_b_manager_cannot_touch_branch_a_reservation(): void
    {
        $r = $this->buildReservation($this->branchA->id, $this->customer->id, ReservationStatus::Pending);

        $this->actingAs($this->managerB)
            ->post("/admin/reservations/{$r->id}/confirm")
            ->assertForbidden();
    }

    // ─── State machine ───────────────────────────────────────────────

    public function test_state_machine_rejects_illegal_transitions(): void
    {
        $r = $this->buildReservation($this->branchA->id, $this->customer->id, ReservationStatus::Pending);

        $this->expectException(\DomainException::class);
        // pending → completed is not allowed
        $r->transitionTo(ReservationStatus::Completed);
    }

    /**
     * Regression: the inline "seat" button on the reservations index
     * submits without a `table_id` field. The controller used to crash
     * with "Undefined array key 'table_id'" because the nullable
     * validator omits the key entirely when missing from the payload.
     */
    public function test_seat_works_without_table_id_in_payload(): void
    {
        $r = $this->buildReservation($this->branchA->id, $this->customer->id, ReservationStatus::Confirmed);

        $this->actingAs($this->managerA)
            ->post("/admin/reservations/{$r->id}/seat")
            ->assertRedirect();

        $this->assertSame(ReservationStatus::Seated, $r->fresh()->status);
        $this->assertNull($r->fresh()->table_id);
    }

    public function test_state_machine_stamps_actor_and_timestamp_on_confirm(): void
    {
        $r = $this->buildReservation($this->branchA->id, $this->customer->id, ReservationStatus::Pending);

        $r->transitionTo(ReservationStatus::Confirmed, [], $this->managerA);

        $this->assertSame(ReservationStatus::Confirmed, $r->fresh()->status);
        $this->assertNotNull($r->fresh()->confirmed_at);
        $this->assertSame($this->managerA->id, $r->fresh()->confirmed_by_user_id);
    }
}
