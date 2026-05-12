<?php

namespace App\Http\Controllers\Admin;

use App\Exports\AttendanceXlsx;
use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Attendance;
use App\Models\User;
use App\Support\BranchContext;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Two distinct surfaces in one controller:
 *
 *   1. SELF-SERVICE — clockIn / clockOut. Any authenticated staff member
 *      can hit these from the topbar widget. Authorization is implicit:
 *      the row they touch is always their own (`auth()->user()->id`).
 *
 *   2. MANAGER — index / store / update / destroy. Gated by
 *      `AttendancePolicy`; BranchScope already filters reads to the
 *      active branch.
 */
class AttendanceController extends Controller
{
    // ─── Self-service ─────────────────────────────────────────────

    public function clockIn(Request $request)
    {
        $user     = $request->user();
        $branchId = BranchContext::current();

        if (! $branchId) {
            return back()->with('error',
                'اختر فرعاً قبل تسجيل الحضور — لا يمكن التسجيل في وضع «كل الفروع».');
        }

        // One open shift at a time across the whole company. Without this
        // a user could double-click the button and create two open rows.
        if ($open = $user->openAttendance()) {
            return back()->with('error',
                "لديك حضور مفتوح في «{$open->branch->name}» منذ "
                . $open->clock_in_at->format('H:i')
                . ". سجّل الانصراف أولاً.");
        }

        // BranchContext is set, the trait fills branch_id automatically.
        $attendance = Attendance::create([
            'user_id'     => $user->id,
            'clock_in_at' => now(),
            'source'      => 'self',
        ]);

        ActivityLog::log('attendance.clock_in',
            "تسجيل حضور {$user->name}",
            $attendance
        );

        // Late detection: configurable threshold time (HH:MM, server timezone).
        // Stored under the `attendance_late_after` Setting key — defaults to
        // 09:15. If clock-in is later than today's threshold, fire an alert
        // so managers can spot patterns (per-day notification is fine; the
        // inbox already groups by type).
        $threshold = \App\Models\Setting::get('attendance_late_after', '09:15');
        try {
            [$h, $m] = array_pad(explode(':', $threshold), 2, 0);
            $cutoff  = now()->copy()->setTime((int) $h, (int) $m, 0);
            if ($attendance->clock_in_at->gt($cutoff)) {
                $minutesLate = (int) ceil($cutoff->diffInSeconds($attendance->clock_in_at) / 60);
                app(\App\Services\NotifyService::class)
                    ->attendanceLate($attendance->load('user'), $minutesLate);
            }
        } catch (\Throwable $e) {
            report($e); // bad threshold setting shouldn't break clock-in
        }

        return back()->with('success', 'تم تسجيل الحضور — يومٌ موفّق!');
    }

    public function clockOut(Request $request)
    {
        $user = $request->user();
        $open = $user->openAttendance();

        if (! $open) {
            return back()->with('error', 'لا يوجد حضور مفتوح لتسجيل انصرافه.');
        }

        // Pin to the row's branch so BranchScope and any cascading
        // observers see the correct context, regardless of where the
        // staff member is currently switched.
        BranchContext::forBranch($open->branch_id, fn () => $open->clockOut());

        ActivityLog::log('attendance.clock_out',
            "تسجيل انصراف {$user->name} — العمل: {$open->durationLabel()}",
            $open
        );

        return back()->with('success',
            "تم تسجيل الانصراف. مدة العمل: {$open->durationLabel()}.");
    }

    // ─── Manager admin ────────────────────────────────────────────

    public function index(Request $request)
    {
        $this->authorize('viewAny', Attendance::class);

        $query = Attendance::with(['user', 'branch', 'editedBy'])
            ->orderBy('clock_in_at', 'desc');

        // Single-day filter takes precedence over from/to range — matches
        // the index UX where managers either pick "today" or a span.
        if ($date = $request->get('date')) {
            $query->forDate($date);
        } else {
            if ($from = $request->get('from')) {
                $query->whereDate('clock_in_at', '>=', $from);
            }
            if ($to = $request->get('to')) {
                $query->whereDate('clock_in_at', '<=', $to);
            }
        }
        if (($status = $request->get('status')) && in_array($status, ['open', 'closed'], true)) {
            $status === 'open' ? $query->open() : $query->closed();
        }
        if ($userId = $request->get('user_id')) {
            $query->forUser((int) $userId);
        }

        $attendances = $query->paginate(25)->withQueryString();

        $stats = [
            'open_now'    => Attendance::open()->count(),
            'today_count' => Attendance::forDate(today())->count(),
            'today_avg'   => (int) (Attendance::forDate(today())->closed()->avg('worked_minutes') ?? 0),
            'today_total' => (int) (Attendance::forDate(today())->closed()->sum('worked_minutes') ?? 0),
        ];

        // Staff filter — limited to the visible branch's members so the
        // dropdown can't be used to enumerate sibling-branch users.
        $staffQuery = User::query()->orderBy('name');
        if ($branchId = BranchContext::current()) {
            $staffQuery->whereHas('branches', fn ($b) => $b->where('branches.id', $branchId));
        }

        return view('admin.attendance.index', [
            'attendances' => $attendances,
            'stats'       => $stats,
            'staff'       => $staffQuery->get(['id', 'name', 'username']),
        ]);
    }

    public function store(Request $request)
    {
        $this->authorize('create', Attendance::class);

        $data = $this->validateData($request, isCreate: true);

        $attendance = Attendance::create([
            'user_id'           => $data['user_id'],
            'clock_in_at'       => $data['clock_in_at'],
            'clock_out_at'      => $data['clock_out_at'] ?? null,
            'break_minutes'     => $data['break_minutes'] ?? 0,
            'notes'             => $data['notes'] ?? null,
            'source'            => 'manager_added',
            'edited_by_user_id' => auth()->id(),
        ]);

        // Stamp worked_minutes if the manager closed it on creation.
        if ($attendance->clock_out_at) {
            $attendance->update([
                'worked_minutes' => max(0,
                    (int) round($attendance->clock_in_at->diffInSeconds($attendance->clock_out_at) / 60)
                    - $attendance->break_minutes),
            ]);
        }

        ActivityLog::log('attendance.created',
            "إضافة حضور يدوي للموظف #{$attendance->user_id}",
            $attendance
        );

        return back()->with('success', 'تم تسجيل الحضور.');
    }

    public function update(Request $request, Attendance $attendance)
    {
        $this->authorize('update', $attendance);

        $data = $this->validateData($request, isCreate: false);
        $data['edited_by_user_id'] = auth()->id();

        // Re-stamp worked_minutes whenever times or break change.
        $data['worked_minutes'] = isset($data['clock_out_at']) && $data['clock_out_at']
            ? max(0,
                (int) round(
                    \Carbon\Carbon::parse($data['clock_in_at'])
                        ->diffInSeconds(\Carbon\Carbon::parse($data['clock_out_at'])) / 60
                ) - (int) ($data['break_minutes'] ?? 0))
            : null;

        $attendance->update($data);

        ActivityLog::log('attendance.updated',
            "تعديل حضور {$attendance->user->name}",
            $attendance
        );

        return back()->with('success', 'تم تحديث السجل.');
    }

    /**
     * Stream a multi-sheet xlsx of the filtered attendance set.
     * Same filters as index() — date / from-to / status / user_id —
     * so what the manager sees is exactly what they get in the file.
     */
    public function export(Request $request)
    {
        $this->authorize('viewAny', Attendance::class);

        return (new AttendanceXlsx())->download([
            'date'    => $request->get('date'),
            'from'    => $request->get('from'),
            'to'      => $request->get('to'),
            'status'  => $request->get('status'),
            'user_id' => $request->get('user_id'),
        ]);
    }

    public function destroy(Attendance $attendance)
    {
        $this->authorize('delete', $attendance);

        $name = $attendance->user->name ?? 'موظف';
        $attendance->delete();

        ActivityLog::log('attendance.deleted', "حذف سجل حضور {$name}");

        return back()->with('success', 'تم حذف السجل.');
    }

    protected function validateData(Request $request, bool $isCreate): array
    {
        return $request->validate([
            'user_id'        => ['required', Rule::exists('users', 'id')],
            'clock_in_at'    => ['required', 'date'],
            'clock_out_at'   => ['nullable', 'date', 'after:clock_in_at'],
            'break_minutes'  => ['nullable', 'integer', 'min:0', 'max:480'],
            'notes'          => ['nullable', 'string', 'max:500'],
        ]);
    }
}
