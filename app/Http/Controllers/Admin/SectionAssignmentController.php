<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\Lookup;
use App\Models\SectionAssignment;
use App\Models\Table;
use App\Models\User;
use App\Support\AdminShell;
use App\Support\BranchContext;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Section roster — "who covers what, today" — on Inertia/Vue (Wave 1).
 *
 * Verbatim port of the ⚡section-assignments Volt component: toggling
 * writes immediately (a save button on a screen someone edits standing up
 * mid-service is a way to lose the roster), carry-forward surfaces only
 * when the manager looks at an empty "today", and "all branches" mode has
 * no floor to roster. Every mutating action returns the fresh roster map
 * so the client never has to guess.
 */
class SectionAssignmentController extends Controller
{
    public function show(Request $request)
    {
        $this->authorizeRoster();

        $date = $this->serviceDate($request);
        $roster = $this->roster($date);

        return AdminShell::render('Admin/SectionAssignments/Index', [
            'date' => $date,
            'branchLocked' => $this->branchLocked(),
            'sections' => $this->sections(),
            'waiters' => $this->waiters(),
            'roster' => (object) $roster,
            'carried' => $this->carried($date, $roster),
            'urls' => [
                'self' => route('admin.section-assignments.index'),
                'toggle' => route('admin.section-assignments.toggle'),
                'copyPrevious' => route('admin.section-assignments.copy'),
                'clearDay' => route('admin.section-assignments.clear'),
                'manageZones' => route('admin.lookups.index', ['group' => 'zones']),
            ],
        ]);
    }

    public function toggle(Request $request)
    {
        $this->authorizeRoster();

        if ($this->branchLocked()) {
            return response()->json(['ok' => false, 'message' => 'اختر فرعاً محدداً قبل توزيع الأقسام.'], 422);
        }

        $data = $request->validate([
            'zone_lookup_id' => ['required', 'integer', 'exists:lookups,id'],
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'date' => ['required', 'date_format:Y-m-d'],
        ]);

        $this->assertRosterTargets((int) $data['zone_lookup_id'], (int) $data['user_id']);

        $existing = SectionAssignment::query()
            ->where('zone_lookup_id', $data['zone_lookup_id'])
            ->where('user_id', $data['user_id'])
            ->forDate($data['date'])
            ->first();

        if ($existing) {
            $existing->delete();
        } else {
            try {
                SectionAssignment::create([
                    'branch_id' => BranchContext::current(),
                    'zone_lookup_id' => $data['zone_lookup_id'],
                    'user_id' => $data['user_id'],
                    'service_date' => $data['date'],
                    'created_by_user_id' => auth()->id(),
                ]);
            } catch (UniqueConstraintViolationException) {
                // Two fast taps must settle on one assignment, not a 500.
            }
        }

        return response()->json(['ok' => true, 'roster' => (object) $this->roster($data['date'])]);
    }

    /** Lift yesterday's roster onto the chosen date — most floors repeat. */
    public function copyPrevious(Request $request)
    {
        $this->authorizeRoster();

        $data = $request->validate(['date' => ['required', 'date_format:Y-m-d']]);

        if ($this->branchLocked()) {
            return response()->json(['ok' => false, 'message' => 'اختر فرعاً محدداً قبل توزيع الأقسام.'], 422);
        }

        $previous = SectionAssignment::query()
            ->forRosterableWaiters()
            ->forDate(Carbon::parse($data['date'])->subDay()->toDateString())
            ->get();

        if ($previous->isEmpty()) {
            return response()->json(['ok' => false, 'message' => 'لا يوجد توزيع بالأمس لنسخه.'], 422);
        }

        foreach ($previous as $row) {
            // NOT firstOrCreate: the date cast stores "Y-m-d 00:00:00", so a
            // plain where('service_date', 'Y-m-d') misses the existing row and
            // the insert slams into the unique constraint. whereDate (via
            // forDate) compares portably; the catch absorbs a same-second race.
            $exists = SectionAssignment::query()
                ->forDate($data['date'])
                ->where('zone_lookup_id', $row->zone_lookup_id)
                ->where('user_id', $row->user_id)
                ->exists();

            if (! $exists) {
                try {
                    SectionAssignment::create([
                        'branch_id' => BranchContext::current(),
                        'zone_lookup_id' => $row->zone_lookup_id,
                        'user_id' => $row->user_id,
                        'service_date' => $data['date'],
                        'created_by_user_id' => auth()->id(),
                    ]);
                } catch (UniqueConstraintViolationException) {
                    // Already there — exactly the outcome we wanted.
                }
            }
        }

        return response()->json([
            'ok' => true,
            'message' => 'تم نسخ توزيع الأمس.',
            'roster' => (object) $this->roster($data['date']),
        ]);
    }

    public function clearDay(Request $request)
    {
        $this->authorizeRoster();

        if ($this->branchLocked()) {
            return response()->json(['ok' => false, 'message' => 'اختر فرعاً محدداً قبل مسح التوزيع.'], 422);
        }

        $data = $request->validate(['date' => ['required', 'date_format:Y-m-d']]);

        SectionAssignment::query()->forDate($data['date'])->delete();

        // An empty day isn't "nobody rostered" anymore — carry-forward will
        // quietly resurrect the last saved plan. Say so instead of letting
        // the manager believe the boards went back to the whole floor.
        $roster = $this->roster($data['date']);
        $carried = $this->carried($data['date'], $roster);

        return response()->json([
            'ok' => true,
            'message' => $carried
                ? 'انمسح توزيع اليوم — آخر توزيع محفوظ سيبقى مطبّقاً تلقائياً حتى توزّع من جديد.'
                : 'انمسح توزيع هذا اليوم.',
            'roster' => (object) $roster,
            'carried' => $carried,
        ]);
    }

    protected function authorizeRoster(): void
    {
        abort_unless(auth()->user()?->hasPermission('tables.assign_sections'), 403);
    }

    protected function serviceDate(Request $request): string
    {
        $date = (string) $request->query('date', '');

        try {
            return $date === '' ? now()->toDateString() : Carbon::createFromFormat('Y-m-d', $date)->toDateString();
        } catch (\Throwable) {
            return now()->toDateString();
        }
    }

    /** Assignments are per-branch; "all branches" mode has no floor to roster. */
    protected function branchLocked(): bool
    {
        return BranchContext::current() === null;
    }

    /** Only the sections that actually have tables on THIS branch's floor. */
    protected function sections(): array
    {
        $zoneIds = Table::query()
            ->whereNotNull('zone_lookup_id')
            ->distinct()
            ->pluck('zone_lookup_id');

        if ($zoneIds->isEmpty()) {
            return [];
        }

        $counts = Table::query()
            ->whereIn('zone_lookup_id', $zoneIds)
            ->selectRaw('zone_lookup_id, COUNT(*) as cnt')
            ->groupBy('zone_lookup_id')
            ->pluck('cnt', 'zone_lookup_id');

        return Lookup::query()
            ->whereIn('id', $zoneIds)
            ->orderBy('display_order')->orderBy('id')
            ->get()
            ->map(fn ($z) => [
                'id' => $z->id,
                'label' => $z->label,
                'color' => $z->color,
                'tablesCount' => (int) ($counts[$z->id] ?? 0),
            ])->values()->all();
    }

    /** Active waiters for this branch. Clocked-in ones sort first (stable). */
    protected function waiters(): array
    {
        $branchId = BranchContext::current();

        $q = User::query()
            ->where('status', 'active')
            ->where('role', UserRole::Waiter->value);

        if ($branchId !== null) {
            $q->whereHas('branches', fn ($b) => $b->where('branches.id', $branchId));
        }

        $users = $q->orderBy('name')->get(['id', 'name', 'role']);

        // Attendance is the time clock (no branch column) — a hint so the
        // manager rosters people who actually turned up.
        $onShift = Attendance::query()
            ->whereNull('clock_out_at')
            ->whereIn('user_id', $users->pluck('id'))
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return $users->map(fn ($u) => [
            'id' => $u->id,
            'name' => $u->name,
            'role' => $u->role,
            'roleLabel' => UserRole::tryFrom($u->role)?->label() ?? $u->role,
            'onShift' => in_array((int) $u->id, $onShift, true),
        ])->sortByDesc('onShift')->values()->all();
    }

    /** Never let a stale or forged id cross the active branch boundary. */
    protected function assertRosterTargets(int $zoneId, int $userId): void
    {
        $zoneExistsOnFloor = collect($this->sections())
            ->contains(fn (array $section) => (int) $section['id'] === $zoneId);

        if (! $zoneExistsOnFloor) {
            throw ValidationException::withMessages([
                'zone_lookup_id' => 'هذا القسم غير موجود على طاولات الفرع الحالي.',
            ]);
        }

        $userCanCoverFloor = collect($this->waiters())
            ->contains(fn (array $waiter) => (int) $waiter['id'] === $userId);

        if (! $userCanCoverFloor) {
            throw ValidationException::withMessages([
                'user_id' => 'يمكن توزيع موظف نشط بدور جرسون في الفرع الحالي فقط.',
            ]);
        }
    }

    /** zone_lookup_id => [user_id, …] for the date (branch-scoped). */
    protected function roster(string $date): array
    {
        return SectionAssignment::query()
            ->forRosterableWaiters()
            ->forDate($date)
            ->get(['zone_lookup_id', 'user_id'])
            ->groupBy('zone_lookup_id')
            ->map(fn ($rows) => $rows->pluck('user_id')->map(fn ($id) => (int) $id)->all())
            ->all();
    }

    /**
     * The date whose roster silently covers today via carry-forward — shown
     * ONLY when the manager looks at an empty "today", the moment they need
     * to know an old plan is live on every waiter's board.
     */
    protected function carried(string $date, array $roster): ?array
    {
        if ($this->branchLocked() || $date !== now()->toDateString() || $roster !== []) {
            return null;
        }

        $effective = SectionAssignment::effectiveDate();
        if ($effective === null || $effective === now()->toDateString()) {
            return null;
        }

        return [
            'date' => $effective,
            'label' => Carbon::parse($effective)->translatedFormat('l j F'),
        ];
    }
}
