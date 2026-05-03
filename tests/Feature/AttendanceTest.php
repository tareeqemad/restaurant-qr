<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Branch;
use App\Models\User;
use App\Support\BranchContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Coverage:
 *   1. Self-service clock-in creates a row in the active branch.
 *   2. Clock-in is rejected when no branch is bound (super admin in
 *      "all branches" mode must pin a branch first).
 *   3. Only one open attendance per user across all branches.
 *   4. Clock-out closes the open row and stamps worked_minutes.
 *   5. Manager A can't see/edit branch B's attendance.
 *   6. State helpers (isOpen, durationLabel) behave correctly.
 */
class AttendanceTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branchA;
    protected Branch $branchB;
    protected User $waiter;
    protected User $managerA;
    protected User $managerB;

    protected function setUp(): void
    {
        parent::setUp();
        BranchContext::clear();

        $this->branchA = Branch::create(['code' => 'a', 'name' => 'Branch A', 'is_active' => true]);
        $this->branchB = Branch::create(['code' => 'b', 'name' => 'Branch B', 'is_active' => true]);

        $this->waiter   = $this->makeUser('waiter1', 'waiter',  $this->branchA);
        $this->managerA = $this->makeUser('mgr_a',   'manager', $this->branchA);
        $this->managerB = $this->makeUser('mgr_b',   'manager', $this->branchB);
    }

    protected function makeUser(string $username, string $role, Branch $branch): User
    {
        $u = User::create([
            'name'     => 'User ' . $username,
            'username' => $username,
            'role'     => $role,
            'status'   => 'active',
            'password' => Hash::make('test'),
        ]);
        $u->branches()->attach($branch->id, ['is_primary' => true, 'joined_at' => now()]);

        return $u;
    }

    // ─── Self-service ────────────────────────────────────────────────

    public function test_clock_in_creates_attendance_in_active_branch(): void
    {
        $this->actingAs($this->waiter);
        BranchContext::set($this->branchA->id);

        $this->post('/admin/attendance/clock-in')->assertRedirect();

        $this->assertDatabaseHas('attendances', [
            'user_id'      => $this->waiter->id,
            'branch_id'    => $this->branchA->id,
            'clock_out_at' => null,
            'source'       => 'self',
        ]);
    }

    public function test_clock_in_blocked_in_all_branches_mode(): void
    {
        // Real-world scenario where no branch context is bound: a Super Admin
        // who picked "كل الفروع" from the switcher. They cannot clock in
        // because attendance must record a specific branch.
        $super = User::create([
            'name' => 'Super', 'username' => 'sup',
            'role' => 'super_admin', 'status' => 'active',
            'password' => Hash::make('test'),
        ]);

        $this->actingAs($super);
        // Match what BranchSwitchController::switchAll writes to the session.
        session(['view_all_branches' => true]);

        $this->from('/admin')
            ->post('/admin/attendance/clock-in')
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertDatabaseCount('attendances', 0);
    }

    public function test_only_one_open_attendance_per_user(): void
    {
        $this->actingAs($this->waiter);
        BranchContext::set($this->branchA->id);

        $this->post('/admin/attendance/clock-in')->assertRedirect();
        $this->post('/admin/attendance/clock-in')
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame(1,
            Attendance::withoutGlobalScopes()
                ->where('user_id', $this->waiter->id)->count());
    }

    public function test_clock_out_closes_open_attendance_and_stamps_duration(): void
    {
        $this->actingAs($this->waiter);
        BranchContext::set($this->branchA->id);

        // Create an open shift one hour in the past so the diff is non-zero.
        $att = $this->buildAttendance($this->branchA->id, $this->waiter->id,
            inAt: now()->subHour());

        $this->post('/admin/attendance/clock-out')->assertRedirect();

        $att->refresh();
        $this->assertNotNull($att->clock_out_at);
        $this->assertGreaterThanOrEqual(58, $att->worked_minutes);
        $this->assertLessThanOrEqual(62,  $att->worked_minutes);
    }

    public function test_clock_out_no_op_when_nothing_open(): void
    {
        $this->actingAs($this->waiter);
        BranchContext::set($this->branchA->id);

        $this->post('/admin/attendance/clock-out')
            ->assertRedirect()
            ->assertSessionHas('error');
    }

    // ─── Manager admin & branch isolation ────────────────────────────

    public function test_manager_a_index_excludes_branch_b_records(): void
    {
        $this->buildAttendance($this->branchA->id, $this->waiter->id);
        $this->buildAttendance($this->branchB->id, $this->managerB->id);

        $this->actingAs($this->managerA);
        BranchContext::set($this->branchA->id);

        $this->assertSame(1, Attendance::count(),
            'Manager A leaked into branch B attendance.');
    }

    public function test_manager_b_cannot_edit_branch_a_attendance(): void
    {
        $att = $this->buildAttendance($this->branchA->id, $this->waiter->id);

        $this->actingAs($this->managerB);
        $this->put("/admin/attendance/{$att->id}", [
            'user_id'     => $this->waiter->id,
            'clock_in_at' => now()->toDateTimeString(),
        ])->assertForbidden();
    }

    public function test_manager_can_manually_add_attendance(): void
    {
        $this->actingAs($this->managerA);
        BranchContext::set($this->branchA->id);

        $this->post('/admin/attendance', [
            'user_id'     => $this->waiter->id,
            'clock_in_at' => now()->subHours(3)->format('Y-m-d H:i:s'),
            'clock_out_at'=> now()->subHour()->format('Y-m-d H:i:s'),
            'break_minutes' => 15,
            'notes'       => 'forgot to clock in',
        ])->assertRedirect();

        $this->assertDatabaseHas('attendances', [
            'user_id' => $this->waiter->id,
            'branch_id' => $this->branchA->id,
            'source'  => 'manager_added',
            'edited_by_user_id' => $this->managerA->id,
        ]);

        // worked_minutes ≈ 120 - 15 break = 105
        $att = Attendance::withoutGlobalScopes()
            ->where('user_id', $this->waiter->id)->first();
        $this->assertGreaterThanOrEqual(103, $att->worked_minutes);
        $this->assertLessThanOrEqual(107, $att->worked_minutes);
    }

    // ─── Model helpers ────────────────────────────────────────────────

    public function test_duration_label_handles_open_and_closed(): void
    {
        $open   = $this->buildAttendance($this->branchA->id, $this->waiter->id,
            inAt: now()->subMinutes(75));
        $closed = $this->buildAttendance($this->branchA->id, $this->waiter->id,
            inAt: now()->subHours(2),
            outAt: now()->subHour(),
            workedMinutes: 60);

        $this->assertTrue($open->isOpen());
        $this->assertFalse($closed->isOpen());
        $this->assertSame('1 س', $closed->durationLabel());
        $this->assertStringContainsString('س', $open->durationLabel());
    }

    // ─── Helpers ──────────────────────────────────────────────────────

    /**
     * Build an Attendance directly. branch_id is NOT in $fillable for
     * the same reason as Reservation/Review — we set it as a property.
     */
    protected function buildAttendance(
        int $branchId,
        int $userId,
        ?\DateTimeInterface $inAt = null,
        ?\DateTimeInterface $outAt = null,
        ?int $workedMinutes = null,
    ): Attendance {
        $att = new Attendance([
            'user_id'        => $userId,
            'clock_in_at'    => $inAt  ?? now(),
            'clock_out_at'   => $outAt,
            'worked_minutes' => $workedMinutes,
            'source'         => 'self',
        ]);
        $att->branch_id = $branchId;
        $att->save();

        return $att;
    }
}
