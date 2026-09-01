<?php

namespace Tests\Feature;

use App\Enums\ReservationStatus;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Reservation;
use App\Models\Role;
use App\Models\User;
use App\Support\BranchContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Reservation coverage after retiring the customer portal account.
 * Customers are identified by phone at the cashier/QR flow; staff continue
 * to own reservation lifecycle changes inside the branch-scoped admin board.
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
            'name' => 'Test Diner',
            'phone' => '0599000000',
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

    protected function buildReservation(
        int $branchId,
        int $customerId,
        ReservationStatus $status,
        ?\DateTimeInterface $when = null,
    ): Reservation {
        $reservation = new Reservation([
            'customer_id' => $customerId,
            'reserved_for' => $when ?? now()->addDay(),
            'party_size' => 2,
            'status' => $status->value,
        ]);
        $reservation->branch_id = $branchId;
        $reservation->save();

        return $reservation;
    }

    public function test_retired_customer_portal_auth_and_reservation_writes_stay_closed(): void
    {
        $this->post('/portal/register')->assertNotFound();
        $this->post('/portal/login')->assertNotFound();
        $this->post('/portal/reservations')->assertNotFound();
        $this->post('/portal/reservations/1/cancel')->assertNotFound();

        $this->assertSame(1, Customer::count());
        $this->assertDatabaseCount('reservations', 0);
    }

    public function test_branch_a_manager_can_confirm_branch_a_reservation(): void
    {
        $reservation = $this->buildReservation($this->branchA->id, $this->customer->id, ReservationStatus::Pending);

        $this->actingAs($this->managerA)
            ->post("/admin/reservations/{$reservation->id}/confirm")
            ->assertRedirect();

        $reservation->refresh();
        $this->assertSame(ReservationStatus::Confirmed, $reservation->status);
        $this->assertSame($this->managerA->id, $reservation->confirmed_by_user_id);
    }

    public function test_branch_b_manager_cannot_touch_branch_a_reservation(): void
    {
        $reservation = $this->buildReservation($this->branchA->id, $this->customer->id, ReservationStatus::Pending);

        $this->actingAs($this->managerB)
            ->post("/admin/reservations/{$reservation->id}/confirm")
            ->assertForbidden();
    }

    public function test_state_machine_rejects_illegal_transitions(): void
    {
        $reservation = $this->buildReservation($this->branchA->id, $this->customer->id, ReservationStatus::Pending);

        $this->expectException(\DomainException::class);
        $reservation->transitionTo(ReservationStatus::Completed);
    }

    public function test_seat_works_without_table_id_in_payload(): void
    {
        $reservation = $this->buildReservation($this->branchA->id, $this->customer->id, ReservationStatus::Confirmed);

        $this->actingAs($this->managerA)
            ->post("/admin/reservations/{$reservation->id}/seat")
            ->assertRedirect();

        $this->assertSame(ReservationStatus::Seated, $reservation->fresh()->status);
        $this->assertNull($reservation->fresh()->table_id);
    }

    public function test_state_machine_stamps_actor_and_timestamp_on_confirm(): void
    {
        $reservation = $this->buildReservation($this->branchA->id, $this->customer->id, ReservationStatus::Pending);

        $reservation->transitionTo(ReservationStatus::Confirmed, [], $this->managerA);

        $this->assertSame(ReservationStatus::Confirmed, $reservation->fresh()->status);
        $this->assertNotNull($reservation->fresh()->confirmed_at);
        $this->assertSame($this->managerA->id, $reservation->fresh()->confirmed_by_user_id);
    }
}
