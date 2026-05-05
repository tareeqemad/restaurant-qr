<?php

use App\Enums\ReservationStatus;
use App\Models\ActivityLog;
use App\Models\Reservation;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Reservations admin board — list view with inline state-machine actions.
 *
 * The original design called for a calendar view with drag-drop (FullCalendar).
 * That library was retired during the cleanup pass, so we ship the list view
 * here and treat the calendar as a future enhancement: when it's wanted, we
 * can re-add the lib and dual-render (list AND calendar) without changing
 * this component's data layer.
 *
 * Polling at 20s — fast enough that a portal-side new reservation appears
 * before a manager refreshes manually, slow enough to skip a real-time event
 * round-trip. The upgrade path is dispatching `ReservationCreated` from
 * PortalReviewController + adding `#[On(...)]` here.
 */
new class extends Component
{
    use WithPagination;

    protected $paginationTheme = 'bootstrap';

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'date', except: '')]
    public string $date = '';

    #[Url(as: 'status', except: '')]
    public string $statusFilter = '';

    #[Url(as: 'table', except: '')]
    public string $tableFilter = '';

    #[Url(as: 'party', except: '')]
    public string $partyFilter = '';

    public function updatingSearch(): void { $this->resetPage(); }
    public function updatingDate(): void { $this->resetPage(); }
    public function updatingStatusFilter(): void { $this->resetPage(); }
    public function updatingTableFilter(): void { $this->resetPage(); }
    public function updatingPartyFilter(): void { $this->resetPage(); }

    public function clearFilters(): void
    {
        $this->reset(['search', 'date', 'statusFilter', 'tableFilter', 'partyFilter']);
        $this->resetPage();
    }

    public function showToday(): void
    {
        $this->date = today()->toDateString();
        $this->resetPage();
    }

    public function showTomorrow(): void
    {
        $this->date = today()->addDay()->toDateString();
        $this->resetPage();
    }

    public function showPending(): void
    {
        $this->statusFilter = ReservationStatus::Pending->value;
        $this->resetPage();
    }

    /**
     * State-machine action dispatcher. Maps a status name to the matching
     * transitionTo + audit log + flash. Centralizes the per-action duplication
     * the controller currently spreads across confirm/seat/complete/etc.
     */
    /**
     * Renamed from `transition()` to avoid clashing with the parent
     * `Livewire\Component::transition()` lifecycle hook (different signature).
     */
    public function runTransition(int $reservationId, string $next, ?string $reason = null): void
    {
        $reservation = Reservation::find($reservationId);
        if (! $reservation) return;

        $target = ReservationStatus::tryFrom($next);
        if (! $target) {
            $this->dispatch('flash', type: 'error', message: 'حالة غير معروفة.');
            return;
        }

        // Per-action authorization — same gate names the controller uses.
        $ability = match ($target) {
            ReservationStatus::Confirmed => 'confirm',
            ReservationStatus::Seated    => 'seat',
            ReservationStatus::Completed => 'complete',
            ReservationStatus::Cancelled => 'cancel',
            ReservationStatus::NoShow    => 'noShow',
            default                      => 'update',
        };
        $this->authorize($ability, $reservation);

        try {
            $reservation->transitionTo(
                $target,
                $reason ? ['cancelled_reason' => $reason] : [],
                auth()->user()
            );
        } catch (\DomainException $e) {
            $this->dispatch('flash', type: 'error', message: 'انتقال غير مسموح: '.$e->getMessage());
            return;
        }

        ActivityLog::log("reservation.{$target->value}",
            "{$target->label()} الحجز {$reservation->reference}",
            $reservation
        );

        $this->dispatch('flash', type: 'success',
            message: "تم {$target->label()} للحجز {$reservation->reference}.");
        unset($this->reservations, $this->stats);
    }

    #[Computed]
    public function reservations()
    {
        $query = Reservation::with(['customer', 'table', 'branch']);

        if ($this->statusFilter !== '') {
            $query->where('status', $this->statusFilter);
        }
        if ($this->tableFilter === 'unassigned') {
            $query->whereNull('table_id');
        } elseif ($this->tableFilter === 'assigned') {
            $query->whereNotNull('table_id');
        }
        if ($this->partyFilter === 'large') {
            $query->where('party_size', '>=', 6);
        } elseif ($this->partyFilter === 'small') {
            $query->where('party_size', '<=', 2);
        }
        if ($this->date !== '') {
            $query->whereDate('reserved_for', $this->date);
        }
        if ($this->search !== '') {
            $s = $this->search;
            $query->where(function ($q) use ($s) {
                $q->where('reference', 'like', "%{$s}%")
                  ->orWhere('customer_notes', 'like', "%{$s}%")
                  ->orWhere('internal_notes', 'like', "%{$s}%")
                  ->orWhereHas('customer', fn ($c) =>
                      $c->where('name', 'like', "%{$s}%")
                        ->orWhere('phone', 'like', "%{$s}%")
                        ->orWhere('email', 'like', "%{$s}%")
                  );
            });
        }

        return $query->orderBy('reserved_for')->paginate(20);
    }

    #[Computed]
    public function stats(): array
    {
        $now = now();
        return [
            'today'     => Reservation::whereDate('reserved_for', today())->count(),
            'pending'   => Reservation::where('status', ReservationStatus::Pending->value)->count(),
            'confirmed' => Reservation::where('status', ReservationStatus::Confirmed->value)
                ->whereDate('reserved_for', today())->count(),
            'upcoming'  => Reservation::upcoming()->count(),
            'new_today' => Reservation::whereDate('created_at', today())->count(),
            'arriving_soon' => Reservation::where('status', ReservationStatus::Confirmed->value)
                ->whereBetween('reserved_for', [$now, $now->copy()->addMinutes(90)])
                ->count(),
            'late' => Reservation::where('status', ReservationStatus::Confirmed->value)
                ->where('reserved_for', '<', $now)
                ->count(),
            'seated_now' => Reservation::where('status', ReservationStatus::Seated->value)->count(),
            'no_table_today' => Reservation::whereDate('reserved_for', today())
                ->whereNull('table_id')
                ->whereNotIn('status', [
                    ReservationStatus::Cancelled->value,
                    ReservationStatus::NoShow->value,
                    ReservationStatus::Completed->value,
                ])
                ->count(),
        ];
    }

    #[Computed]
    public function filteredStats(): array
    {
        $page = $this->reservations;
        $items = $page->getCollection();

        return [
            'count' => $page->total(),
            'guests' => $items->sum('party_size'),
            'needs_table' => $items->filter(fn ($r) => is_null($r->table_id) && ! $r->status->isFinal())->count(),
            'actionable' => $items->filter(fn ($r) => ! $r->status->isFinal())->count(),
        ];
    }
}
?>

<div wire:poll.visible.30s class="rsv-wrap">
    <x-admin.stat-rail :stats="[
        ['label' => 'حجوزات اليوم',     'value' => $this->stats['today'],     'icon' => 'bi-calendar-day',       'color' => 'primary'],
        ['label' => 'بانتظار التأكيد',   'value' => $this->stats['pending'],   'icon' => 'bi-hourglass-split',    'color' => 'warning'],
        ['label' => 'مؤكَّدة لليوم',     'value' => $this->stats['confirmed'], 'icon' => 'bi-check-circle-fill',  'color' => 'success'],
        ['label' => 'خلال ٩٠ دقيقة',      'value' => $this->stats['arriving_soon'], 'icon' => 'bi-alarm-fill',      'color' => 'accent'],
        ['label' => 'متأخرة الآن',        'value' => $this->stats['late'],      'icon' => 'bi-exclamation-triangle-fill', 'color' => 'danger'],
        ['label' => 'جالسين الآن',        'value' => $this->stats['seated_now'], 'icon' => 'bi-people-fill',       'color' => 'info'],
        ['label' => 'بدون طاولة اليوم',   'value' => $this->stats['no_table_today'], 'icon' => 'bi-grid-3x3-gap',  'color' => 'muted'],
        ['label' => 'جديدة اليوم',        'value' => $this->stats['new_today'], 'icon' => 'bi-sparkles',           'color' => 'success'],
    ]" />

    <div class="rsv-command mb-3">
        <div class="rsv-command__copy">
            <strong>تشغيل الحجوزات</strong>
            <span>تابع القادم، ثبّت الطاولات، وسجّل الوصول أو عدم الحضور من نفس الشاشة.</span>
        </div>
        <div class="rsv-command__actions">
            <button type="button" class="btn btn-sm btn-primary" wire:click="showToday">
                <i class="bi bi-calendar-day"></i> اليوم
            </button>
            <button type="button" class="btn btn-sm btn-light" wire:click="showTomorrow">
                <i class="bi bi-calendar2-plus"></i> غداً
            </button>
            <button type="button" class="btn btn-sm btn-outline-warning" wire:click="showPending">
                <i class="bi bi-hourglass-split"></i> المعلّقة
            </button>
        </div>
    </div>

    <x-admin.data-panel title="قائمة الحجوزات" :count="$this->reservations->total()" icon="bi-calendar-event">
        <x-slot:actions>
            @if($search !== '' || $date !== '' || $statusFilter !== '' || $tableFilter !== '' || $partyFilter !== '')
                <button wire:click="clearFilters" type="button" class="btn btn-light">
                    <i class="bi bi-x-circle"></i> مسح الفلاتر
                </button>
            @endif
        </x-slot:actions>

        <x-slot:filters>
            <div class="row g-2 align-items-end">
                <div class="col-md-4">
                    <label class="form-label small text-muted mb-1">بحث</label>
                    <input type="text" wire:model.live.debounce.500ms="search"
                           class="form-control" placeholder="رقم الحجز / اسم الزبون / الهاتف / ملاحظات">
                </div>
                <div class="col-md-2">
                    <label class="form-label small text-muted mb-1">التاريخ</label>
                    <input type="date" wire:model.live="date" class="form-control">
                </div>
                <div class="col-md-2">
                    <label class="form-label small text-muted mb-1">الحالة</label>
                    <select wire:model.live="statusFilter" class="form-select">
                        <option value="">كل الحالات</option>
                        @foreach(ReservationStatus::cases() as $s)
                            <option value="{{ $s->value }}">{{ $s->label() }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small text-muted mb-1">الطاولة</label>
                    <select wire:model.live="tableFilter" class="form-select">
                        <option value="">الكل</option>
                        <option value="unassigned">بدون طاولة</option>
                        <option value="assigned">محددة</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small text-muted mb-1">حجم المجموعة</label>
                    <select wire:model.live="partyFilter" class="form-select">
                        <option value="">الكل</option>
                        <option value="small">١-٢ ضيف</option>
                        <option value="large">٦+ ضيوف</option>
                    </select>
                </div>
            </div>
        </x-slot:filters>

        @php $showBranchCol = (bool) session('view_all_branches'); @endphp

        <div class="rsv-summary mb-3">
            <div>
                <span>نتائج الفلتر</span>
                <strong>{{ number_format($this->filteredStats['count']) }}</strong>
            </div>
            <div>
                <span>ضيوف في الصفحة</span>
                <strong class="text-primary">{{ number_format($this->filteredStats['guests']) }}</strong>
            </div>
            <div>
                <span>تحتاج طاولة</span>
                <strong class="text-warning">{{ number_format($this->filteredStats['needs_table']) }}</strong>
            </div>
            <div>
                <span>قابلة للإجراء</span>
                <strong class="text-success">{{ number_format($this->filteredStats['actionable']) }}</strong>
            </div>
        </div>

        <div class="table-responsive">
            <table class="table align-middle rsv-table">
                <thead class="bg-light">
                    <tr>
                        <th>الرقم</th>
                        @if($showBranchCol)<th>الفرع</th>@endif
                        <th>الزبون</th>
                        <th>الموعد</th>
                        <th>المدة</th>
                        <th>الضيوف</th>
                        <th>الطاولة</th>
                        <th>ملاحظات</th>
                        <th>الحالة</th>
                        <th>إجراءات</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($this->reservations as $r)
                        @php
                            $isLate = $r->status === ReservationStatus::Confirmed && $r->reserved_for->isPast();
                            $isSoon = $r->status === ReservationStatus::Confirmed && $r->reserved_for->between(now(), now()->addMinutes(90));
                            $endsAt = $r->reserved_for->copy()->addMinutes($r->duration_minutes);
                        @endphp
                        <tr wire:key="rsv-{{ $r->id }}" class="{{ $isLate ? 'rsv-row--late' : ($isSoon ? 'rsv-row--soon' : '') }}">
                            <td>
                                <code class="rsv-code">{{ $r->reference }}</code>
                                @if($r->created_at->isToday())
                                    <span class="badge bg-success-transparent d-block mt-1">جديد</span>
                                @endif
                            </td>
                            @if($showBranchCol)
                                <td><x-admin.branch-tag :branch="$r->branch" /></td>
                            @endif
                            <td>
                                <div class="fw-bold">{{ $r->customer->name ?? '—' }}</div>
                                <small class="text-muted" dir="ltr">{{ $r->customer->phone ?? '' }}</small>
                                @if($r->customer?->isBlocked())
                                    <span class="badge bg-danger-transparent d-block mt-1">عميل محظور</span>
                                @endif
                            </td>
                            <td>
                                <div>{{ $r->reserved_for->format('Y/m/d') }}</div>
                                <small class="{{ $isLate ? 'text-danger fw-bold' : 'text-muted' }}">
                                    {{ $r->reserved_for->format('H:i') }} · {{ $r->reserved_for->diffForHumans() }}
                                </small>
                            </td>
                            <td>
                                <span>{{ $r->duration_minutes }} د</span>
                                <small class="text-muted d-block">حتى {{ $endsAt->format('H:i') }}</small>
                            </td>
                            <td>{{ $r->party_size }}</td>
                            <td>
                                @if($r->table)
                                    <span class="badge bg-info-transparent">طاولة {{ $r->table->number }}</span>
                                @else
                                    <a href="{{ route('admin.reservations.edit', $r) }}" class="badge bg-warning-transparent text-warning text-decoration-none">
                                        حدد طاولة
                                    </a>
                                @endif
                            </td>
                            <td class="rsv-notes">
                                @if($r->customer_notes)
                                    <small class="d-block" title="{{ $r->customer_notes }}"><i class="bi bi-chat-left-text"></i> {{ \Illuminate\Support\Str::limit($r->customer_notes, 42) }}</small>
                                @endif
                                @if($r->internal_notes)
                                    <small class="d-block text-muted" title="{{ $r->internal_notes }}"><i class="bi bi-sticky"></i> {{ \Illuminate\Support\Str::limit($r->internal_notes, 42) }}</small>
                                @endif
                                @if(! $r->customer_notes && ! $r->internal_notes)
                                    <span class="text-muted">—</span>
                                @endif
                            </td>
                            <td><span class="badge bg-{{ $r->status->color() }}">{{ $r->status->label() }}</span></td>
                            <td class="rsv-actions">
                                <a href="{{ route('admin.reservations.edit', $r) }}"
                                   class="btn btn-sm btn-light" title="تفاصيل">
                                    <i class="bi bi-pencil"></i>
                                </a>

                                @if($r->status === ReservationStatus::Pending)
                                    <button wire:click="runTransition({{ $r->id }}, 'confirmed')" type="button"
                                            class="btn btn-sm btn-outline-success" title="تأكيد">
                                        <i class="bi bi-check-circle"></i>
                                    </button>
                                @endif

                                @if($r->status === ReservationStatus::Confirmed)
                                    <button wire:click="runTransition({{ $r->id }}, 'seated')" type="button"
                                            class="btn btn-sm btn-outline-info" title="تسجيل الجلوس">
                                        <i class="bi bi-people"></i>
                                    </button>
                                    <button wire:click="runTransition({{ $r->id }}, 'no_show')" type="button"
                                            wire:confirm="تسجيل عدم الحضور؟"
                                            class="btn btn-sm btn-outline-dark" title="لم يحضر">
                                        <i class="bi bi-person-x"></i>
                                    </button>
                                @endif

                                @if($r->status === ReservationStatus::Seated)
                                    <button wire:click="runTransition({{ $r->id }}, 'completed')" type="button"
                                            class="btn btn-sm btn-outline-secondary" title="إنهاء">
                                        <i class="bi bi-check2-all"></i>
                                    </button>
                                @endif

                                @if(! $r->status->isFinal())
                                    <button wire:click="runTransition({{ $r->id }}, 'cancelled')" type="button"
                                            wire:confirm="إلغاء هذا الحجز؟"
                                            class="btn btn-sm btn-outline-danger" title="إلغاء">
                                        <i class="bi bi-x-circle"></i>
                                    </button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="{{ $showBranchCol ? 10 : 9 }}">
                            <x-admin.empty-state icon="bi-calendar2-event"
                                title="لا توجد حجوزات"
                                message="لم يتمّ تسجيل أي حجوزات بعد لهذا الفرع." />
                        </td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if($this->reservations->hasPages())
            <x-slot:footer>{{ $this->reservations->links() }}</x-slot:footer>
        @endif
    </x-admin.data-panel>

    <style>
        .rsv-command {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            background: #fff;
            padding: 12px 14px;
        }
        .rsv-command__copy strong {
            display: block;
            color: #1f2937;
            font-size: .95rem;
        }
        .rsv-command__copy span {
            color: #6b7280;
            font-size: .82rem;
        }
        .rsv-command__actions {
            display: inline-flex;
            gap: 6px;
            flex-wrap: wrap;
        }
        .rsv-summary {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 8px;
        }
        .rsv-summary > div {
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            background: #fff;
            padding: 10px 12px;
        }
        .rsv-summary span {
            display: block;
            color: #6b7280;
            font-size: .76rem;
            margin-bottom: 3px;
        }
        .rsv-summary strong,
        .rsv-code {
            font-family: ui-monospace, monospace;
            font-weight: 800;
        }
        .rsv-code {
            background: #f3f4f6;
            border-radius: 5px;
            color: #1f2937;
            font-size: .8rem;
            padding: 3px 8px;
        }
        .rsv-row--late td {
            background: rgba(239, 68, 68, .045);
        }
        .rsv-row--soon td {
            background: rgba(245, 158, 11, .045);
        }
        .rsv-notes {
            min-width: 180px;
            max-width: 260px;
        }
        .rsv-actions {
            white-space: nowrap;
        }
        .rsv-actions .btn {
            margin-inline-start: 2px;
        }
        @media (max-width: 767.98px) {
            .rsv-command {
                align-items: stretch;
                flex-direction: column;
            }
            .rsv-summary {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }
    </style>
</div>
