<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Branch;
use App\Models\Role;
use App\Models\User;
use App\Support\BranchContext;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
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

        foreach (['manager', 'waiter'] as $roleName) {
            Role::firstOrCreate(['name' => $roleName], [
                'label' => $roleName,
                'is_system' => true,
            ]);
        }
        $this->seed(PermissionSeeder::class);

        $this->waiter = $this->makeUser('waiter1', 'waiter', $this->branchA);
        $this->managerA = $this->makeUser('mgr_a', 'manager', $this->branchA);
        $this->managerB = $this->makeUser('mgr_b', 'manager', $this->branchB);
    }

    protected function makeUser(string $username, string $role, Branch $branch): User
    {
        $u = User::create([
            'name' => 'User '.$username,
            'username' => $username,
            'role' => $role,
            'status' => 'active',
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
            'user_id' => $this->waiter->id,
            'branch_id' => $this->branchA->id,
            'clock_out_at' => null,
            'source' => 'self',
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
        $this->assertLessThanOrEqual(62, $att->worked_minutes);
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

    public function test_manager_attendance_screen_uses_the_vue_workspace(): void
    {
        $attendance = $this->buildAttendance(
            $this->branchA->id,
            $this->waiter->id,
            inAt: now()->subHour(),
        );

        $this->actingAs($this->managerA);
        BranchContext::set($this->branchA->id);

        $this->get(route('admin.attendance.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Attendance/Index')
                ->where('attendances.data.0.id', $attendance->id)
                ->where('attendances.data.0.employee.name', $this->waiter->name)
                ->where('attendances.data.0.open', true)
                ->where('attendances.data.0.can.update', true)
                ->where('attendances.data.0.can.delete', false)
                ->where('stats.openNow', 1)
                ->where('can.create', true)
                ->has('urls.export')
            );
    }

    public function test_manager_b_cannot_edit_branch_a_attendance(): void
    {
        $att = $this->buildAttendance($this->branchA->id, $this->waiter->id);

        $this->actingAs($this->managerB);
        $this->put("/admin/attendance/{$att->id}", [
            'user_id' => $this->waiter->id,
            'clock_in_at' => now()->toDateTimeString(),
        ])->assertForbidden();
    }

    public function test_manager_can_manually_add_attendance(): void
    {
        $this->actingAs($this->managerA);
        BranchContext::set($this->branchA->id);

        $this->post('/admin/attendance', [
            'user_id' => $this->waiter->id,
            'clock_in_at' => now()->subHours(3)->format('Y-m-d H:i:s'),
            'clock_out_at' => now()->subHour()->format('Y-m-d H:i:s'),
            'break_minutes' => 15,
            'notes' => 'forgot to clock in',
            'correction_reason' => 'نسي الموظف تسجيل الحضور',
        ])->assertRedirect();

        $this->assertDatabaseHas('attendances', [
            'user_id' => $this->waiter->id,
            'branch_id' => $this->branchA->id,
            'source' => 'manager_added',
            'edited_by_user_id' => $this->managerA->id,
        ]);

        // worked_minutes ≈ 120 - 15 break = 105
        $att = Attendance::withoutGlobalScopes()
            ->where('user_id', $this->waiter->id)->first();
        $this->assertGreaterThanOrEqual(103, $att->worked_minutes);
        $this->assertLessThanOrEqual(107, $att->worked_minutes);
    }

    public function test_manager_cannot_create_attendance_for_another_branch_employee(): void
    {
        $this->actingAs($this->managerA);
        BranchContext::set($this->branchA->id);

        $this->post('/admin/attendance', [
            'user_id' => $this->managerB->id,
            'clock_in_at' => now()->subHour()->format('Y-m-d H:i:s'),
            'clock_out_at' => now()->format('Y-m-d H:i:s'),
            'break_minutes' => 0,
            'correction_reason' => 'تصحيح إداري',
        ])->assertSessionHasErrors('user_id');

        $this->assertDatabaseMissing('attendances', [
            'user_id' => $this->managerB->id,
            'branch_id' => $this->branchA->id,
        ]);
    }

    // ─── Model helpers ────────────────────────────────────────────────

    public function test_duration_label_handles_open_and_closed(): void
    {
        $open = $this->buildAttendance($this->branchA->id, $this->waiter->id,
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

    public function test_manager_cannot_create_overlapping_attendance(): void
    {
        $day = now()->startOfDay();
        $this->buildAttendance(
            $this->branchA->id,
            $this->waiter->id,
            inAt: $day->copy()->addHours(8),
            outAt: $day->copy()->addHours(12),
            workedMinutes: 240,
        );

        $this->actingAs($this->managerA);
        BranchContext::set($this->branchA->id);

        $this->post('/admin/attendance', [
            'user_id' => $this->waiter->id,
            'clock_in_at' => $day->copy()->addHours(11)->format('Y-m-d H:i:s'),
            'clock_out_at' => $day->copy()->addHours(13)->format('Y-m-d H:i:s'),
            'break_minutes' => 0,
            'correction_reason' => 'إضافة منسية',
        ])->assertSessionHasErrors('clock_in_at');

        $this->assertSame(1, Attendance::withoutGlobalScopes()
            ->where('user_id', $this->waiter->id)->count());
    }

    public function test_stale_command_sends_forgotten_checkout_to_review_without_guessing_hours(): void
    {
        $attendance = $this->buildAttendance(
            $this->branchA->id,
            $this->waiter->id,
            inAt: now()->subHours(30),
        );

        $this->artisan('attendance:close-stale')->assertSuccessful();

        $attendance->refresh();
        $this->assertTrue($attendance->needsReview());
        $this->assertSame(0, $attendance->worked_minutes);
        $this->assertTrue($attendance->clock_out_at->equalTo($attendance->clock_in_at));
        $this->assertStringContainsString('مراجعة', (string) $attendance->notes);
    }

    public function test_new_clock_in_quarantines_a_stale_open_record_then_starts_a_clean_shift(): void
    {
        $stale = $this->buildAttendance(
            $this->branchA->id,
            $this->waiter->id,
            inAt: now()->subHours(30),
        );

        $this->actingAs($this->waiter);
        BranchContext::set($this->branchA->id);

        $this->post('/admin/attendance/clock-in')
            ->assertRedirect()
            ->assertSessionHas('warning');

        $stale->refresh();
        $this->assertTrue($stale->needsReview());
        $this->assertSame(0, $stale->worked_minutes);
        $this->assertSame(1, Attendance::withoutGlobalScopes()
            ->where('user_id', $this->waiter->id)
            ->whereNull('clock_out_at')
            ->count());
    }

    public function test_manager_correction_approves_a_review_record_with_real_hours_and_audit(): void
    {
        $attendance = $this->buildAttendance(
            $this->branchA->id,
            $this->waiter->id,
            inAt: now()->subHours(30),
        );
        $attendance->markNeedsReview('نسي تسجيل الانصراف');

        $this->actingAs($this->managerA);
        BranchContext::set($this->branchA->id);

        $clockIn = now()->subHours(8)->startOfMinute();
        $clockOut = now()->subHour()->startOfMinute();
        $this->put("/admin/attendance/{$attendance->id}", [
            'user_id' => $this->waiter->id,
            'clock_in_at' => $clockIn->format('Y-m-d H:i:s'),
            'clock_out_at' => $clockOut->format('Y-m-d H:i:s'),
            'break_minutes' => 30,
            'correction_reason' => 'تأكيد وقت الانصراف مع الموظف',
        ])->assertRedirect();

        $attendance->refresh();
        $this->assertSame(Attendance::SOURCE_MANAGER, $attendance->source);
        $this->assertSame(390, $attendance->worked_minutes);
        $this->assertDatabaseHas('activity_logs', [
            'event' => 'attendance.updated',
            'subject_id' => $attendance->id,
            'causer_id' => $this->managerA->id,
        ]);
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
            'user_id' => $userId,
            'clock_in_at' => $inAt ?? now(),
            'clock_out_at' => $outAt,
            'worked_minutes' => $workedMinutes,
            'source' => 'self',
        ]);
        $att->branch_id = $branchId;
        $att->save();

        return $att;
    }
}
