<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ReservationStatus;
use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Reservation;
use App\Models\Table;
use App\Support\AdminShell;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Branch-scoped reservations admin.
 *
 * The BranchScope on Reservation already confines reads to the active
 * branch; the policy adds a per-row check on writes for defense in depth.
 */
class ReservationController extends Controller
{
    /**
     * The reservations board — Inertia/Vue since Wave 5.
     *
     * The host's real questions drive the stats: who is arriving in the
     * next 90 minutes, who is already late, and which of today's bookings
     * still has no table. Every transition posts to the per-action
     * endpoints below, so authorization and the state machine stay in one
     * place instead of being duplicated in a component.
     */
    public function index(Request $request)
    {
        $this->authorize('viewAny', Reservation::class);

        $status = (string) $request->get('status', '');
        $date = (string) $request->get('date', '');
        $table = (string) $request->get('table', '');
        $party = (string) $request->get('party', '');
        // The retired board bound search to ?q=, so links people bookmarked
        // (and the dashboard's deep links) still work alongside ?search=.
        $search = trim((string) ($request->get('q') ?? $request->get('search', '')));

        $query = Reservation::with(['customer', 'table', 'branch']);

        if ($status !== '') {
            $query->where('status', $status);
        }
        if ($table === 'unassigned') {
            $query->whereNull('table_id');
        } elseif ($table === 'assigned') {
            $query->whereNotNull('table_id');
        }
        if ($party === 'large') {
            $query->where('party_size', '>=', 6);
        } elseif ($party === 'small') {
            $query->where('party_size', '<=', 2);
        }
        if ($date !== '') {
            $query->whereDate('reserved_for', $date);
        }
        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('reference', 'like', "%{$search}%")
                    ->orWhere('customer_notes', 'like', "%{$search}%")
                    ->orWhere('internal_notes', 'like', "%{$search}%")
                    ->orWhereHas('customer', fn ($c) => $c
                        ->where('name', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%"));
            });
        }

        $reservations = $query->orderBy('reserved_for')->paginate(20)->withQueryString();
        $now = now();

        return AdminShell::render('Admin/Reservations/Index', [
            'reservations' => [
                'data' => $reservations->getCollection()->map(function (Reservation $r) use ($now) {
                    $status = $r->status instanceof ReservationStatus
                        ? $r->status
                        : ReservationStatus::tryFrom((string) $r->status);

                    return [
                        'id' => $r->id,
                        'reference' => $r->reference,
                        'status' => $status?->value ?? (string) $r->status,
                        'statusLabel' => $status?->label() ?? (string) $r->status,
                        'statusColor' => $status?->color() ?? 'secondary',
                        'customerName' => $r->customer?->name ?? $r->customer_name,
                        'customerPhone' => $r->customer?->phone ?? $r->customer_phone,
                        'partySize' => (int) $r->party_size,
                        'tableNumber' => $r->table?->number,
                        'branchName' => $r->branch?->name,
                        'reservedFor' => $r->reserved_for?->format('Y-m-d H:i'),
                        'reservedForHuman' => $r->reserved_for?->diffForHumans(),
                        'customerNotes' => $r->customer_notes,
                        'internalNotes' => $r->internal_notes,
                        'cancelledReason' => $r->cancelled_reason,
                        // A confirmed booking whose time has passed is the
                        // one the host must chase right now.
                        'isLate' => $status === ReservationStatus::Confirmed
                            && $r->reserved_for && $r->reserved_for->lt($now),
                        'arrivingSoon' => $status === ReservationStatus::Confirmed
                            && $r->reserved_for
                            && $r->reserved_for->between($now, $now->copy()->addMinutes(90)),
                        'can' => [
                            'confirm' => auth()->user()->can('confirm', $r),
                            'seat' => auth()->user()->can('seat', $r),
                            'complete' => auth()->user()->can('complete', $r),
                            'cancel' => auth()->user()->can('cancel', $r),
                            'noShow' => auth()->user()->can('noShow', $r),
                            'update' => auth()->user()->can('update', $r),
                        ],
                        'urls' => [
                            'edit' => route('admin.reservations.edit', $r),
                            'confirm' => route('admin.reservations.confirm', $r),
                            'seat' => route('admin.reservations.seat', $r),
                            'complete' => route('admin.reservations.complete', $r),
                            'cancel' => route('admin.reservations.cancel', $r),
                            'noShow' => route('admin.reservations.no-show', $r),
                        ],
                    ];
                })->all(),
                'links' => $reservations->linkCollection()->toArray(),
                'total' => $reservations->total(),
            ],
            'stats' => [
                'today' => Reservation::whereDate('reserved_for', today())->count(),
                'pending' => Reservation::where('status', ReservationStatus::Pending->value)->count(),
                'upcoming' => Reservation::upcoming()->count(),
                'arrivingSoon' => Reservation::where('status', ReservationStatus::Confirmed->value)
                    ->whereBetween('reserved_for', [$now, $now->copy()->addMinutes(90)])->count(),
                'late' => Reservation::where('status', ReservationStatus::Confirmed->value)
                    ->where('reserved_for', '<', $now)->count(),
                'seatedNow' => Reservation::where('status', ReservationStatus::Seated->value)->count(),
                'noTableToday' => Reservation::whereDate('reserved_for', today())
                    ->whereNull('table_id')
                    ->whereNotIn('status', [
                        ReservationStatus::Cancelled->value,
                        ReservationStatus::NoShow->value,
                        ReservationStatus::Completed->value,
                    ])->count(),
            ],
            'filters' => compact('status', 'date', 'table', 'party', 'search'),
            'statuses' => collect(ReservationStatus::cases())->map(fn ($s) => [
                'value' => $s->value, 'label' => $s->label(),
            ])->all(),
            'urls' => ['index' => route('admin.reservations.index')],
        ]);
    }

    public function edit(Reservation $reservation)
    {
        $this->authorize('view', $reservation);

        $reservation->load(['customer', 'table', 'confirmedBy', 'cancelledBy']);
        $user = auth()->user();

        return AdminShell::render('Admin/Reservations/Edit', [
            'reservation' => [
                'id' => $reservation->id,
                'reference' => $reservation->reference,
                'status' => $reservation->status->value,
                'statusLabel' => $reservation->status->label(),
                'statusColor' => $reservation->status->color(),
                'isFinal' => $reservation->status->isFinal(),
                'reservedFor' => $reservation->reserved_for->format('Y/m/d H:i'),
                'partySize' => $reservation->party_size,
                'tableId' => $reservation->table_id,
                'durationMinutes' => $reservation->duration_minutes,
                'customerNotes' => $reservation->customer_notes,
                'internalNotes' => $reservation->internal_notes,
                'confirmed' => $reservation->confirmed_at ? [
                    'at' => $reservation->confirmed_at->format('Y/m/d H:i'),
                    'by' => $reservation->confirmedBy?->name ?? 'تلقائي',
                ] : null,
                'cancelled' => $reservation->cancelled_at ? [
                    'at' => $reservation->cancelled_at->format('Y/m/d H:i'),
                    'by' => $reservation->cancelledBy?->name ?? '—',
                    'reason' => $reservation->cancelled_reason,
                ] : null,
                'customer' => [
                    'name' => $reservation->customer?->name ?? '—',
                    'phone' => $reservation->customer?->phone,
                    'email' => $reservation->customer?->email,
                ],
            ],
            'tables' => Table::orderBy('number')->get(['id', 'number', 'capacity', 'status'])
                ->map(fn (Table $table) => [
                    'id' => $table->id,
                    'number' => $table->number,
                    'capacity' => $table->capacity,
                    'status' => $table->status,
                ])->values(),
            'can' => [
                'update' => (bool) $user?->can('update', $reservation),
                'confirm' => (bool) $user?->can('confirm', $reservation),
                'seat' => (bool) $user?->can('seat', $reservation),
                'complete' => (bool) $user?->can('complete', $reservation),
                'cancel' => (bool) $user?->can('cancel', $reservation),
                'noShow' => (bool) $user?->can('noShow', $reservation),
            ],
            'urls' => [
                'index' => route('admin.reservations.index'),
                'update' => route('admin.reservations.update', $reservation),
                'confirm' => route('admin.reservations.confirm', $reservation),
                'seat' => route('admin.reservations.seat', $reservation),
                'complete' => route('admin.reservations.complete', $reservation),
                'cancel' => route('admin.reservations.cancel', $reservation),
                'noShow' => route('admin.reservations.no-show', $reservation),
            ],
        ]);
    }

    /**
     * Resolve where to send the user after a state-machine action. Prefer
     * the referer when it points at the admin index/edit (so the user
     * stays where they were); fall back to the reservation's own edit
     * page so the URL bar never shows an action URL — that's the
     * behaviour that previously triggered 405 on browser refresh / back.
     */
    protected function returnTo(Reservation $reservation): string
    {
        $referer = request()->headers->get('referer') ?? '';
        $admin = url('/admin/reservations');

        if (str_starts_with($referer, $admin)
            && ! str_contains($referer, '/seat')
            && ! str_contains($referer, '/confirm')
            && ! str_contains($referer, '/complete')
            && ! str_contains($referer, '/cancel')
            && ! str_contains($referer, '/no-show')) {
            return $referer;
        }

        return route('admin.reservations.edit', $reservation);
    }

    public function update(Request $request, Reservation $reservation)
    {
        $this->authorize('update', $reservation);

        $data = $request->validate([
            'table_id' => ['nullable', Rule::exists('tables', 'id')],
            'internal_notes' => ['nullable', 'string', 'max:500'],
            'duration_minutes' => ['required', 'integer', 'min:15', 'max:600'],
        ]);

        $reservation->update($data);

        ActivityLog::log('reservation.updated',
            "تعديل تفاصيل الحجز {$reservation->reference}",
            $reservation
        );

        return back()->with('success', 'تم تحديث الحجز.');
    }

    public function confirm(Reservation $reservation)
    {
        $this->authorize('confirm', $reservation);
        $reservation->transitionTo(ReservationStatus::Confirmed, [], auth()->user());

        ActivityLog::log('reservation.confirmed',
            "تأكيد الحجز {$reservation->reference}",
            $reservation
        );

        return redirect()->to($this->returnTo($reservation))
            ->with('success', "تم تأكيد الحجز {$reservation->reference}.");
    }

    public function seat(Request $request, Reservation $reservation)
    {
        $this->authorize('seat', $reservation);

        $data = $request->validate([
            'table_id' => ['nullable', Rule::exists('tables', 'id')],
        ]);

        // `nullable` validator omits the key entirely when the field isn't
        // posted (the inline seat button on the index has no table_id input).
        $tableId = $data['table_id'] ?? null;

        $reservation->transitionTo(
            ReservationStatus::Seated,
            $tableId ? ['table_id' => (int) $tableId] : [],
            auth()->user()
        );

        ActivityLog::log('reservation.seated',
            "جلوس الحجز {$reservation->reference}",
            $reservation
        );

        return redirect()->to($this->returnTo($reservation))
            ->with('success', "تم جلوس ضيوف الحجز {$reservation->reference}.");
    }

    public function complete(Reservation $reservation)
    {
        $this->authorize('complete', $reservation);
        $reservation->transitionTo(ReservationStatus::Completed, [], auth()->user());

        ActivityLog::log('reservation.completed',
            "اكتمال الحجز {$reservation->reference}",
            $reservation
        );

        return redirect()->to($this->returnTo($reservation))
            ->with('success', 'تم إغلاق الحجز.');
    }

    public function cancel(Request $request, Reservation $reservation)
    {
        $this->authorize('cancel', $reservation);

        $data = $request->validate([
            'cancelled_reason' => ['nullable', 'string', 'max:255'],
        ]);

        $reservation->transitionTo(
            ReservationStatus::Cancelled,
            ['cancelled_reason' => $data['cancelled_reason'] ?? 'ألغاه الفرع'],
            auth()->user()
        );

        ActivityLog::log('reservation.cancelled',
            "إلغاء الحجز {$reservation->reference}",
            $reservation
        );

        return redirect()->to($this->returnTo($reservation))
            ->with('success', "تم إلغاء الحجز {$reservation->reference}.");
    }

    public function noShow(Reservation $reservation)
    {
        $this->authorize('noShow', $reservation);
        $reservation->transitionTo(ReservationStatus::NoShow, [], auth()->user());

        ActivityLog::log('reservation.no_show',
            "تسجيل عدم حضور الحجز {$reservation->reference}",
            $reservation
        );

        return redirect()->to($this->returnTo($reservation))
            ->with('success', 'تم تسجيل عدم الحضور.');
    }
}
