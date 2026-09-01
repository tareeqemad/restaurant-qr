<?php

namespace App\Http\Controllers\Admin;

use App\Exports\AttendanceXlsx;
use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Attendance;
use App\Models\User;
use App\Support\AdminShell;
use App\Support\BranchContext;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Attendance is a time source, not a payroll journal. Staff record their own
 * presence; managers correct exceptional rows with a reason. Payroll remains
 * a separate reviewed monthly decision.
 */
class AttendanceController extends Controller
{
    public function clockIn(Request $request)
    {
        $branchId = BranchContext::current();

        if (! $branchId) {
            return back()->with('error', 'اختر فرعاً قبل بدء الدوام.');
        }

        $result = DB::transaction(function () use ($request) {
            $user = User::query()->whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            $open = BranchContext::unscoped(fn () => Attendance::query()
                ->with('branch')
                ->open()
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->latest('clock_in_at')
                ->first());

            if ($open && $open->clock_in_at->gt(now()->subHours(Attendance::STALE_AFTER_HOURS))) {
                return ['conflict' => $open];
            }

            if ($open) {
                $open->markNeedsReview(
                    'أُغلق دون احتساب ساعات لأنه بقي مفتوحاً أكثر من 24 ساعة. راجع وقت الانصراف الفعلي.'
                );
            }

            $attendance = Attendance::create([
                'user_id' => $user->id,
                'clock_in_at' => now(),
                'source' => Attendance::SOURCE_SELF,
            ]);

            return ['attendance' => $attendance, 'quarantined' => $open];
        }, 3);

        if ($open = $result['conflict'] ?? null) {
            return back()->with('error', sprintf(
                'دوامك مفتوح في %s منذ %s. أنهِه أولاً.',
                $open->branch?->localizedName() ?? 'فرع آخر',
                $open->clock_in_at->format('H:i'),
            ));
        }

        if ($quarantined = $result['quarantined'] ?? null) {
            ActivityLog::log(
                'attendance.review_required',
                "تحويل حضور منسي للموظف #{$quarantined->user_id} إلى المراجعة",
                $quarantined,
            );
        }

        $attendance = $result['attendance'];
        ActivityLog::log('attendance.clock_in', "بدء دوام {$request->user()->name}", $attendance);

        $message = isset($result['quarantined'])
            ? 'بدأ دوامك الآن. السجل القديم أُرسل للمدير للمراجعة ولم تُحتسب له ساعات تلقائياً.'
            : 'بدأ دوامك الآن. يوم موفق!';

        return back()->with($quarantined ? 'warning' : 'success', $message);
    }

    public function clockOut(Request $request)
    {
        $result = DB::transaction(function () use ($request) {
            $user = User::query()->whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            $open = BranchContext::unscoped(fn () => Attendance::query()
                ->open()
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->latest('clock_in_at')
                ->first());

            if (! $open) {
                return null;
            }

            if ($open->clock_in_at->lte(now()->subHours(Attendance::STALE_AFTER_HOURS))) {
                $open->markNeedsReview(
                    'سجّل الموظف الانصراف بعد بقاء السجل مفتوحاً أكثر من 24 ساعة؛ لم تُحتسب ساعات قبل مراجعة المدير.'
                );

                return ['attendance' => $open, 'review' => true];
            }

            BranchContext::forBranch($open->branch_id, fn () => $open->clockOut());

            return ['attendance' => $open, 'review' => false];
        }, 3);

        if (! $result) {
            return back()->with('error', 'لا يوجد دوام مفتوح لإنهائه.');
        }

        $attendance = $result['attendance'];
        ActivityLog::log(
            $result['review'] ? 'attendance.review_required' : 'attendance.clock_out',
            $result['review']
                ? "إنهاء دوام {$request->user()->name} وإرساله للمراجعة"
                : "إنهاء دوام {$request->user()->name} — {$attendance->durationLabel()}",
            $attendance,
        );

        return back()->with(
            $result['review'] ? 'warning' : 'success',
            $result['review']
                ? 'أُنهي الدوام وأُرسل للمدير للمراجعة؛ لم تُحتسب ساعات غير مؤكدة.'
                : "انتهى دوامك. صافي العمل {$attendance->durationLabel()}.",
        );
    }

    public function index(Request $request)
    {
        $this->authorize('viewAny', Attendance::class);

        $filters = $this->filters($request);
        $query = Attendance::with(['user', 'branch', 'editedBy'])
            ->orderByDesc('clock_in_at');

        $this->applyFilters($query, $filters);
        $attendances = $query->paginate(24)->withQueryString();
        $user = $request->user();
        $showBranch = (bool) session('view_all_branches');

        $attendances->through(fn (Attendance $attendance) => $this->attendancePayload(
            $attendance,
            $user,
            $showBranch,
        ));

        $todayClosed = Attendance::forDate(today())
            ->closed()
            ->where('source', '!=', Attendance::SOURCE_REVIEW);

        $staff = User::query()
            ->where('status', 'active')
            ->when(BranchContext::current(), fn (Builder $q, int $branchId) => $q
                ->whereHas('branches', fn (Builder $b) => $b->where('branches.id', $branchId)))
            ->orderBy('name')
            ->get(['id', 'name', 'username'])
            ->map(fn (User $member) => [
                'id' => $member->id,
                'name' => $member->name,
                'username' => $member->username,
            ])
            ->values();

        return AdminShell::render('Admin/Attendance/Index', [
            'attendances' => $attendances,
            'stats' => [
                'openNow' => Attendance::open()->count(),
                'needsReview' => Attendance::reviewRequired()->count(),
                'presentToday' => Attendance::forDate(today())->distinct('user_id')->count('user_id'),
                'todayTotal' => $this->minutesLabel((int) (clone $todayClosed)->sum('worked_minutes')),
            ],
            'filters' => [
                'search' => $filters['search'],
                'date' => $filters['date'],
                'from' => $filters['from'],
                'to' => $filters['to'],
                'userId' => $filters['user_id'],
                'status' => $filters['status'],
            ],
            'staff' => $staff,
            'defaults' => [
                'today' => today()->toDateString(),
                'now' => now()->format('Y-m-d\TH:i'),
            ],
            'can' => [
                'create' => (bool) BranchContext::current() && (bool) $user?->can('create', Attendance::class),
            ],
            'urls' => [
                'index' => route('admin.attendance.index'),
                'store' => route('admin.attendance.store'),
                'export' => route('admin.attendance.export.xlsx'),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $this->authorize('create', Attendance::class);
        $data = $this->validateData($request);
        $reason = $data['correction_reason'];

        $attendance = DB::transaction(function () use ($data, $reason) {
            User::query()->whereKey($data['user_id'])->lockForUpdate()->firstOrFail();
            $startsAt = Carbon::parse($data['clock_in_at']);
            $endsAt = filled($data['clock_out_at'] ?? null) ? Carbon::parse($data['clock_out_at']) : null;
            $this->ensureNoOverlap((int) $data['user_id'], $startsAt, $endsAt);

            $attendance = Attendance::create([
                'user_id' => $data['user_id'],
                'clock_in_at' => $startsAt,
                'clock_out_at' => $endsAt,
                'break_minutes' => $data['break_minutes'] ?? 0,
                'worked_minutes' => $this->workedMinutes($startsAt, $endsAt, (int) ($data['break_minutes'] ?? 0)),
                'notes' => $this->appendAuditNote($data['notes'] ?? null, $reason, 'إضافة يدوية'),
                'source' => Attendance::SOURCE_MANAGER,
                'edited_by_user_id' => auth()->id(),
            ]);

            ActivityLog::log(
                'attendance.created',
                "إضافة حضور يدوي للموظف #{$attendance->user_id}",
                $attendance,
                ['reason' => $reason, 'after' => $this->auditSnapshot($attendance)],
            );

            return $attendance;
        }, 3);

        return back()->with('success', "تمت إضافة سجل {$attendance->user?->name} ومُنع أي تداخل بالساعات.");
    }

    public function update(Request $request, Attendance $attendance)
    {
        $this->authorize('update', $attendance);
        $data = $this->validateData($request, branchId: $attendance->branch_id);

        if ((int) $data['user_id'] !== (int) $attendance->user_id) {
            throw ValidationException::withMessages(['user_id' => 'لا يمكن نقل سجل الدوام إلى موظف آخر.']);
        }

        $reason = $data['correction_reason'];

        DB::transaction(function () use ($attendance, $data, $reason) {
            User::query()->whereKey($attendance->user_id)->lockForUpdate()->firstOrFail();
            $locked = Attendance::query()->whereKey($attendance->id)->lockForUpdate()->firstOrFail();
            $before = $this->auditSnapshot($locked);
            $startsAt = Carbon::parse($data['clock_in_at']);
            $endsAt = filled($data['clock_out_at'] ?? null) ? Carbon::parse($data['clock_out_at']) : null;

            $this->ensureNoOverlap($locked->user_id, $startsAt, $endsAt, $locked->id);

            $locked->update([
                'clock_in_at' => $startsAt,
                'clock_out_at' => $endsAt,
                'break_minutes' => $data['break_minutes'] ?? 0,
                'worked_minutes' => $this->workedMinutes($startsAt, $endsAt, (int) ($data['break_minutes'] ?? 0)),
                'notes' => $this->appendAuditNote($data['notes'] ?? null, $reason, 'تصحيح'),
                'source' => Attendance::SOURCE_MANAGER,
                'edited_by_user_id' => auth()->id(),
            ]);

            ActivityLog::log(
                'attendance.updated',
                "تصحيح حضور {$locked->user?->name}",
                $locked,
                ['reason' => $reason, 'before' => $before, 'after' => $this->auditSnapshot($locked)],
            );
        }, 3);

        return back()->with('success', 'تم حفظ التصحيح وتوثيق السبب والقيم السابقة.');
    }

    public function export(Request $request)
    {
        $this->authorize('viewAny', Attendance::class);

        return (new AttendanceXlsx)->download([
            'search' => $request->get('search'),
            'date' => $request->get('date'),
            'from' => $request->get('from'),
            'to' => $request->get('to'),
            'status' => $request->get('status'),
            'user_id' => $request->get('user_id'),
        ]);
    }

    public function destroy(Request $request, Attendance $attendance)
    {
        $this->authorize('delete', $attendance);
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:250'],
        ]);

        DB::transaction(function () use ($attendance, $data) {
            User::query()->whereKey($attendance->user_id)->lockForUpdate()->firstOrFail();
            $locked = Attendance::query()->whereKey($attendance->id)->lockForUpdate()->firstOrFail();

            ActivityLog::log(
                'attendance.excluded',
                "استبعاد سجل حضور {$locked->user?->name}",
                $locked,
                ['reason' => $data['reason'], 'record' => $this->auditSnapshot($locked)],
            );

            $locked->delete();
        }, 3);

        return back()->with('success', 'تم استبعاد السجل مع الاحتفاظ بسبب الاستبعاد في سجل النشاطات.');
    }

    /** @return array{search:string,date:string,from:string,to:string,user_id:string,status:string} */
    protected function filters(Request $request): array
    {
        $explicit = $request->hasAny(['search', 'date', 'from', 'to', 'user_id', 'status']);

        return [
            'search' => trim((string) $request->get('search', '')),
            'date' => (string) $request->get('date', $explicit ? '' : today()->toDateString()),
            'from' => (string) $request->get('from', ''),
            'to' => (string) $request->get('to', ''),
            'user_id' => (string) $request->get('user_id', ''),
            'status' => in_array($request->get('status'), ['open', 'closed', 'review'], true)
                ? (string) $request->get('status')
                : '',
        ];
    }

    protected function applyFilters(Builder $query, array $filters): void
    {
        if ($filters['search'] !== '') {
            $search = $filters['search'];
            $query->whereHas('user', fn (Builder $q) => $q
                ->where('name', 'like', "%{$search}%")
                ->orWhere('username', 'like', "%{$search}%"));
        }

        if ($filters['date'] !== '') {
            $query->forDate($filters['date']);
        } else {
            if ($filters['from'] !== '') {
                $query->whereDate('clock_in_at', '>=', $filters['from']);
            }
            if ($filters['to'] !== '') {
                $query->whereDate('clock_in_at', '<=', $filters['to']);
            }
        }

        if ($filters['user_id'] !== '') {
            $query->forUser((int) $filters['user_id']);
        }

        match ($filters['status']) {
            'open' => $query->open(),
            'closed' => $query->closed()->where('source', '!=', Attendance::SOURCE_REVIEW),
            'review' => $query->reviewRequired(),
            default => null,
        };
    }

    protected function attendancePayload(Attendance $attendance, ?User $viewer, bool $showBranch): array
    {
        $status = $attendance->needsReview()
            ? ['key' => 'review', 'label' => 'يحتاج مراجعة', 'tone' => 'warning']
            : ($attendance->isOpen()
                ? ['key' => 'open', 'label' => 'على رأس العمل', 'tone' => 'success']
                : ['key' => 'closed', 'label' => 'مكتمل', 'tone' => 'muted']);

        return [
            'id' => $attendance->id,
            'employee' => [
                'id' => $attendance->user_id,
                'name' => $attendance->user?->name ?? 'موظف محذوف',
                'username' => $attendance->user?->username,
            ],
            'branch' => $showBranch && $attendance->branch ? [
                'name' => $attendance->branch->localizedName(),
                'hue' => ($attendance->branch->id * 47) % 360,
            ] : null,
            'date' => $attendance->clock_in_at->format('Y/m/d'),
            'clockIn' => $attendance->clock_in_at->format('H:i'),
            'clockOut' => $attendance->clock_out_at?->format('H:i'),
            'clockInValue' => $attendance->clock_in_at->format('Y-m-d\TH:i'),
            'clockOutValue' => $attendance->clock_out_at?->format('Y-m-d\TH:i'),
            'open' => $attendance->isOpen(),
            'needsReview' => $attendance->needsReview(),
            'longOpen' => $attendance->isOpen()
                && $attendance->clock_in_at->lte(now()->subHours(12)),
            'duration' => $attendance->needsReview() ? 'غير محتسب' : $attendance->durationLabel(),
            'breakMinutes' => (int) $attendance->break_minutes,
            'notes' => $attendance->notes,
            'status' => $status,
            'source' => match ($attendance->source) {
                Attendance::SOURCE_SELF => 'ذاتي',
                Attendance::SOURCE_MANAGER => 'إداري',
                Attendance::SOURCE_REVIEW => 'آلي للمراجعة',
                default => 'غير محدد',
            },
            'editedBy' => $attendance->editedBy?->name,
            'can' => [
                'update' => (bool) $viewer?->can('update', $attendance),
                'delete' => (bool) $viewer?->can('delete', $attendance),
            ],
            'urls' => [
                'update' => route('admin.attendance.update', $attendance),
                'destroy' => route('admin.attendance.destroy', $attendance),
            ],
        ];
    }

    protected function validateData(Request $request, ?int $branchId = null): array
    {
        $data = $request->validate([
            'user_id' => ['required', Rule::exists('users', 'id')->where('status', 'active')],
            'clock_in_at' => ['required', 'date'],
            'clock_out_at' => ['nullable', 'date', 'after:clock_in_at'],
            'break_minutes' => ['nullable', 'integer', 'min:0', 'max:480'],
            'notes' => ['nullable', 'string', 'max:500'],
            'correction_reason' => ['required', 'string', 'min:3', 'max:250'],
        ]);

        $branchId ??= BranchContext::current();
        $belongsToBranch = $branchId && User::query()
            ->whereKey($data['user_id'])
            ->whereHas('branches', fn (Builder $query) => $query->where('branches.id', $branchId))
            ->exists();

        if (! $belongsToBranch) {
            throw ValidationException::withMessages([
                'user_id' => 'اختر موظفاً نشطاً تابعاً للفرع الحالي.',
            ]);
        }

        $startsAt = Carbon::parse($data['clock_in_at']);
        $endsAt = filled($data['clock_out_at'] ?? null) ? Carbon::parse($data['clock_out_at']) : null;

        if ($startsAt->gt(now()->addMinutes(5))) {
            throw ValidationException::withMessages([
                'clock_in_at' => 'وقت الحضور لا يمكن أن يكون في المستقبل.',
            ]);
        }

        if ($endsAt) {
            $duration = max(0, (int) floor($startsAt->diffInSeconds($endsAt) / 60));
            if ((int) ($data['break_minutes'] ?? 0) > $duration) {
                throw ValidationException::withMessages([
                    'break_minutes' => 'الاستراحة لا يمكن أن تتجاوز مدة الدوام.',
                ]);
            }
        }

        return $data;
    }

    protected function ensureNoOverlap(
        int $userId,
        Carbon $startsAt,
        ?Carbon $endsAt,
        ?int $ignoreId = null,
    ): void {
        $conflict = BranchContext::unscoped(fn () => Attendance::query()
            ->overlapping($userId, $startsAt, $endsAt, $ignoreId)
            ->lockForUpdate()
            ->first());

        if ($conflict) {
            $range = $conflict->clock_in_at->format('Y/m/d H:i').' — '
                .($conflict->clock_out_at?->format('Y/m/d H:i') ?? 'ما زال مفتوحاً');

            throw ValidationException::withMessages([
                'clock_in_at' => "يتداخل هذا الوقت مع سجل موجود: {$range}.",
            ]);
        }
    }

    protected function workedMinutes(Carbon $startsAt, ?Carbon $endsAt, int $breakMinutes): ?int
    {
        if (! $endsAt) {
            return null;
        }

        return max(0, (int) round($startsAt->diffInSeconds($endsAt) / 60) - $breakMinutes);
    }

    protected function appendAuditNote(?string $notes, string $reason, string $kind): string
    {
        $stamp = now()->format('Y/m/d H:i');
        $line = "[{$kind} {$stamp}] {$reason}";

        return collect([trim((string) $notes), $line])->filter()->implode("\n");
    }

    protected function auditSnapshot(Attendance $attendance): array
    {
        return Arr::only($attendance->getAttributes(), [
            'branch_id', 'user_id', 'clock_in_at', 'clock_out_at',
            'break_minutes', 'worked_minutes', 'source', 'notes',
        ]);
    }

    protected function minutesLabel(int $minutes): string
    {
        $hours = intdiv($minutes, 60);
        $remaining = $minutes % 60;

        if ($hours === 0) {
            return "{$remaining} د";
        }
        if ($remaining === 0) {
            return "{$hours} س";
        }

        return "{$hours} س {$remaining} د";
    }
}
