<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ReservationStatus;
use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Reservation;
use App\Models\Table;
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
    public function index(Request $request)
    {
        $this->authorize('viewAny', Reservation::class);

        $query = Reservation::with(['customer', 'table', 'branch'])
            ->orderBy('reserved_for', 'desc');

        if ($status = $request->get('status')) {
            $query->withStatus($status);
        }

        if ($date = $request->get('date')) {
            $query->forDate($date);
        }

        if ($search = $request->get('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('reference', 'like', "%{$search}%")
                  ->orWhereHas('customer', fn ($c) =>
                      $c->where('name', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%")
                  );
            });
        }

        $reservations = $query->paginate(20)->withQueryString();

        $stats = [
            'today'     => Reservation::whereDate('reserved_for', today())->count(),
            'pending'   => Reservation::withStatus(ReservationStatus::Pending)->count(),
            'confirmed' => Reservation::withStatus(ReservationStatus::Confirmed)
                ->whereDate('reserved_for', today())->count(),
            'upcoming'  => Reservation::upcoming()->count(),
        ];

        return view('admin.reservations.index', compact('reservations', 'stats'));
    }

    public function edit(Reservation $reservation)
    {
        $this->authorize('view', $reservation);

        return view('admin.reservations.edit', [
            'reservation' => $reservation->load(['customer', 'table']),
            'tables'      => Table::orderBy('number')->get(),
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
        $admin   = url('/admin/reservations');

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
            'table_id'         => ['nullable', Rule::exists('tables', 'id')],
            'internal_notes'   => ['nullable', 'string', 'max:500'],
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
