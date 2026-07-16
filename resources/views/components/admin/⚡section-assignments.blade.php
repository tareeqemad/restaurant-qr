<?php

use App\Models\Attendance;
use App\Models\Lookup;
use App\Models\SectionAssignment;
use App\Models\Table;
use App\Models\User;
use App\Support\BranchContext;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Section roster — "who covers what, today".
 *
 * The floor board's whole "طاولاتي" idea rests on this: without a roster,
 * ownership is whoever approved an order first, which is luck rather than a
 * plan. A manager sets this once at the start of service; every waiter's board
 * then opens on their own section.
 *
 * Toggling writes immediately — a save button on a screen someone edits while
 * standing up mid-service is a way to lose the roster.
 */
new class extends Component
{
    #[Url(as: 'date', except: '')]
    public string $date = '';

    public function mount(): void
    {
        abort_unless(
            auth()->user()?->hasAnyRole(['super_admin', 'admin', 'manager']),
            403
        );

        if ($this->date === '') {
            $this->date = now()->toDateString();
        }
    }

    /** Assignments are per-branch; "all branches" mode has no floor to roster. */
    public function branchLocked(): bool
    {
        return BranchContext::current() === null;
    }

    /** Only the sections that actually have tables on THIS branch's floor. */
    #[Computed]
    public function sections()
    {
        $zoneIds = Table::query()
            ->whereNotNull('zone_lookup_id')
            ->distinct()
            ->pluck('zone_lookup_id');

        if ($zoneIds->isEmpty()) {
            return collect();
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
            ->map(function ($z) use ($counts) {
                $z->tables_count = (int) ($counts[$z->id] ?? 0);
                return $z;
            });
    }

    /** Waiters attached to this branch. Clocked-in ones sort first. */
    #[Computed]
    public function waiters()
    {
        $branchId = BranchContext::current();

        $q = User::query()
            ->where('status', 'active')
            ->whereIn('role', ['waiter', 'manager']);

        if ($branchId !== null) {
            $q->whereHas('branches', fn ($b) => $b->where('branches.id', $branchId));
        }

        $users = $q->orderBy('name')->get(['id', 'name', 'role']);

        // Attendance is the time clock (no branch column) — used purely as a
        // hint so the manager rosters people who actually turned up.
        $onShift = Attendance::query()
            ->whereNull('clock_out_at')
            ->whereIn('user_id', $users->pluck('id'))
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return $users->map(function ($u) use ($onShift) {
            $u->on_shift = in_array((int) $u->id, $onShift, true);
            return $u;
        })->sortByDesc('on_shift')->values();
    }

    /** zone_lookup_id => [user_id, ...] for the chosen date. */
    #[Computed]
    public function roster(): array
    {
        return SectionAssignment::query()
            ->forDate($this->date)
            ->get(['zone_lookup_id', 'user_id'])
            ->groupBy('zone_lookup_id')
            ->map(fn ($rows) => $rows->pluck('user_id')->map(fn ($id) => (int) $id)->all())
            ->all();
    }

    public function toggle(int $zoneId, int $userId): void
    {
        abort_unless(auth()->user()?->hasAnyRole(['super_admin', 'admin', 'manager']), 403);

        if ($this->branchLocked()) {
            $this->dispatch('toast', type: 'warning', message: 'اختر فرعاً محدداً قبل توزيع الأقسام.');

            return;
        }

        $existing = SectionAssignment::query()
            ->where('zone_lookup_id', $zoneId)
            ->where('user_id', $userId)
            ->forDate($this->date)
            ->first();

        if ($existing) {
            $existing->delete();
        } else {
            SectionAssignment::create([
                'branch_id' => BranchContext::current(),
                'zone_lookup_id' => $zoneId,
                'user_id' => $userId,
                'service_date' => $this->date,
                'created_by_user_id' => auth()->id(),
            ]);
        }

        unset($this->roster);
    }

    /** Lift yesterday's roster onto today — most floors repeat their layout. */
    public function copyPrevious(): void
    {
        abort_unless(auth()->user()?->hasAnyRole(['super_admin', 'admin', 'manager']), 403);

        if ($this->branchLocked()) {
            return;
        }

        $previous = SectionAssignment::query()
            ->forDate(\Carbon\Carbon::parse($this->date)->subDay()->toDateString())
            ->get();

        if ($previous->isEmpty()) {
            $this->dispatch('toast', type: 'warning', message: 'لا يوجد توزيع بالأمس لنسخه.');

            return;
        }

        foreach ($previous as $row) {
            SectionAssignment::firstOrCreate([
                'branch_id' => BranchContext::current(),
                'zone_lookup_id' => $row->zone_lookup_id,
                'user_id' => $row->user_id,
                'service_date' => $this->date,
            ], ['created_by_user_id' => auth()->id()]);
        }

        unset($this->roster);
        $this->dispatch('toast', type: 'success', message: 'تم نسخ توزيع الأمس.');
    }

    public function clearDay(): void
    {
        abort_unless(auth()->user()?->hasAnyRole(['super_admin', 'admin', 'manager']), 403);

        SectionAssignment::query()->forDate($this->date)->delete();
        unset($this->roster);
    }
}
?>

<div class="sa-wrap">
    @php
        $roster = $this->roster;
        $sections = $this->sections;
        $waiters = $this->waiters;
        $assignedCount = collect($roster)->flatten()->count();
    @endphp

    <div class="sa-head">
        <div class="sa-head-copy">
            <h3>توزيع الأقسام</h3>
            <p>مين مسؤول عن أي قسم اليوم. لوحة كل جرسون بتفتح على قسمه تلقائياً.</p>
        </div>
        <div class="sa-head-tools">
            <input type="date" wire:model.live="date" class="sa-date" aria-label="تاريخ الخدمة">
            <button type="button" wire:click="copyPrevious" class="sa-btn"><i class="bi bi-clipboard-check"></i> انسخ توزيع الأمس</button>
            @if($assignedCount > 0)
                <button type="button" wire:click="clearDay" class="sa-btn sa-btn--danger"
                        wire:confirm="مسح توزيع هذا اليوم بالكامل؟"><i class="bi bi-x-circle"></i> مسح اليوم</button>
            @endif
        </div>
    </div>

    @if($this->branchLocked())
        <div class="sa-note sa-note--warn">
            <i class="bi bi-exclamation-triangle-fill"></i>
            أنت في وضع «كل الفروع». اختر فرعاً محدداً من الأعلى — التوزيع خاص بصالة فرع واحد.
        </div>
    @elseif($sections->isEmpty())
        <div class="sa-note">
            <i class="bi bi-info-circle"></i>
            ما في أقسام على طاولات هذا الفرع بعد. عرّف الأقسام ثم اربط كل طاولة بقسمها.
            <a href="{{ route('admin.lookups.index', ['group' => 'zones']) }}">إدارة الأقسام</a>
        </div>
    @elseif($waiters->isEmpty())
        <div class="sa-note">
            <i class="bi bi-info-circle"></i>
            ما في جرسونية مرتبطين بهذا الفرع.
        </div>
    @else
        <div class="sa-grid">
            @foreach($sections as $z)
                @php $assigned = $roster[$z->id] ?? []; @endphp
                <section class="sa-section" wire:key="sec-{{ $z->id }}" style="--sec-color: {{ $z->color }};">
                    <header class="sa-section-head">
                        <span class="sa-section-dot"></span>
                        <strong>{{ $z->label }}</strong>
                        <span class="sa-section-tables">{{ $z->tables_count }} طاولة</span>
                        @if(count($assigned) === 0)
                            <span class="sa-unassigned"><i class="bi bi-exclamation-triangle"></i> بلا جرسون</span>
                        @endif
                    </header>
                    <div class="sa-chips">
                        @foreach($waiters as $w)
                            @php $isOn = in_array((int) $w->id, $assigned, true); @endphp
                            <button type="button" wire:click="toggle({{ $z->id }}, {{ $w->id }})"
                                wire:key="chip-{{ $z->id }}-{{ $w->id }}"
                                class="sa-chip {{ $isOn ? 'is-on' : '' }}"
                                aria-pressed="{{ $isOn ? 'true' : 'false' }}">
                                <i class="bi {{ $isOn ? 'bi-check-circle-fill' : 'bi-circle' }}"></i>
                                <span>{{ $w->name }}</span>
                                @if($w->on_shift)
                                    <span class="sa-onshift" title="مسجّل حضور الآن"></span>
                                @endif
                            </button>
                        @endforeach
                    </div>
                </section>
            @endforeach
        </div>

        <div class="sa-legend">
            <span><span class="sa-onshift"></span> مسجّل حضور الآن</span>
            <span>لو ما وزّعت أي قسم، لوحة الجرسون بترجع للسلوك القديم (الطاولات اللي بيعتمد طلبها).</span>
        </div>
    @endif
</div>
