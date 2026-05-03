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

    public function updatingSearch(): void { $this->resetPage(); }
    public function updatingDate(): void { $this->resetPage(); }
    public function updatingStatusFilter(): void { $this->resetPage(); }

    public function clearFilters(): void
    {
        $this->reset(['search', 'date', 'statusFilter']);
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
        $query = Reservation::with(['customer', 'table', 'branch'])
            ->orderBy('reserved_for', 'desc');

        if ($this->statusFilter !== '') {
            $query->where('status', $this->statusFilter);
        }
        if ($this->date !== '') {
            $query->whereDate('reserved_for', $this->date);
        }
        if ($this->search !== '') {
            $s = $this->search;
            $query->where(function ($q) use ($s) {
                $q->where('reference', 'like', "%{$s}%")
                  ->orWhereHas('customer', fn ($c) =>
                      $c->where('name', 'like', "%{$s}%")
                        ->orWhere('phone', 'like', "%{$s}%")
                  );
            });
        }

        return $query->paginate(20);
    }

    #[Computed]
    public function stats(): array
    {
        return [
            'today'     => Reservation::whereDate('reserved_for', today())->count(),
            'pending'   => Reservation::where('status', ReservationStatus::Pending->value)->count(),
            'confirmed' => Reservation::where('status', ReservationStatus::Confirmed->value)
                ->whereDate('reserved_for', today())->count(),
            'upcoming'  => Reservation::upcoming()->count(),
            'new_today' => Reservation::whereDate('created_at', today())->count(),
        ];
    }
}
?>

<div wire:poll.visible.30s class="rsv-wrap">
    <x-admin.stat-rail :stats="[
        ['label' => 'حجوزات اليوم',     'value' => $this->stats['today'],     'icon' => 'bi-calendar-day',       'color' => 'primary'],
        ['label' => 'بانتظار التأكيد',   'value' => $this->stats['pending'],   'icon' => 'bi-hourglass-split',    'color' => 'warning'],
        ['label' => 'مؤكَّدة لليوم',     'value' => $this->stats['confirmed'], 'icon' => 'bi-check-circle-fill',  'color' => 'success'],
        ['label' => 'إجمالي القادم',     'value' => $this->stats['upcoming'],  'icon' => 'bi-calendar-week',      'color' => 'accent'],
        ['label' => 'جديدة اليوم',       'value' => $this->stats['new_today'], 'icon' => 'bi-sparkles',           'color' => 'success'],
    ]" />

    <x-admin.data-panel title="قائمة الحجوزات" :count="$this->reservations->total()" icon="bi-calendar-event">
        <x-slot:actions>
            @if($search !== '' || $date !== '' || $statusFilter !== '')
                <button wire:click="clearFilters" type="button" class="btn btn-light">
                    <i class="bi bi-x-circle"></i> مسح الفلاتر
                </button>
            @endif
        </x-slot:actions>

        <x-slot:filters>
            <div class="row g-2">
                <div class="col-md-5">
                    <input type="text" wire:model.live.debounce.500ms="search"
                           class="form-control" placeholder="🔍 رقم الحجز / اسم الزبون / الهاتف">
                </div>
                <div class="col-md-3">
                    <input type="date" wire:model.live="date" class="form-control">
                </div>
                <div class="col-md-4">
                    <select wire:model.live="statusFilter" class="form-select">
                        <option value="">كل الحالات</option>
                        @foreach(ReservationStatus::cases() as $s)
                            <option value="{{ $s->value }}">{{ $s->label() }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
        </x-slot:filters>

        @php $showBranchCol = (bool) session('view_all_branches'); @endphp

        <div class="table-responsive">
            <table class="table align-middle">
                <thead class="bg-light">
                    <tr>
                        <th>الرقم</th>
                        @if($showBranchCol)<th>الفرع</th>@endif
                        <th>الزبون</th>
                        <th>الموعد</th>
                        <th>الضيوف</th>
                        <th>الطاولة</th>
                        <th>الحالة</th>
                        <th>إجراءات</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($this->reservations as $r)
                        <tr wire:key="rsv-{{ $r->id }}">
                            <td><code>{{ $r->reference }}</code></td>
                            @if($showBranchCol)
                                <td><x-admin.branch-tag :branch="$r->branch" /></td>
                            @endif
                            <td>
                                <div class="fw-bold">{{ $r->customer->name ?? '—' }}</div>
                                <small class="text-muted" dir="ltr">{{ $r->customer->phone ?? '' }}</small>
                            </td>
                            <td>
                                <div>{{ $r->reserved_for->format('Y/m/d') }}</div>
                                <small class="text-muted">{{ $r->reserved_for->format('H:i') }}</small>
                            </td>
                            <td>{{ $r->party_size }}</td>
                            <td>{{ $r->table?->number ?? '—' }}</td>
                            <td><span class="badge bg-{{ $r->status->color() }}">{{ $r->status->label() }}</span></td>
                            <td>
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
                        <tr><td colspan="{{ $showBranchCol ? 8 : 7 }}">
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
</div>
