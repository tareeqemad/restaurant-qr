<?php

namespace App\Http\Controllers\Portal;

use App\Enums\ReservationStatus;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Reservation;
use App\Support\BranchContext;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Customer-facing reservations.
 *
 * The customer is GLOBAL — this controller deliberately bypasses the
 * BranchScope (via BranchContext::unscoped) when reading the customer's
 * own reservations, because the customer dines at multiple branches.
 *
 * Writes use BranchContext::forBranch($branchId, …) to make sure the new
 * row's branch_id is bound correctly during creation regardless of any
 * stale staff-side context.
 */
class ReservationController extends Controller
{
    public function index(Request $request)
    {
        $customer = $request->user('customer');

        [$upcoming, $history] = BranchContext::unscoped(function () use ($customer) {
            $upcoming = Reservation::with(['branch', 'table'])
                ->where('customer_id', $customer->id)
                ->upcoming()
                ->get();

            $history = Reservation::with(['branch', 'table'])
                ->where('customer_id', $customer->id)
                ->where(function ($q) {
                    $q->where('reserved_for', '<', now())
                      ->orWhereIn('status', [
                          ReservationStatus::Cancelled->value,
                          ReservationStatus::Completed->value,
                          ReservationStatus::NoShow->value,
                      ]);
                })
                ->latest('reserved_for')
                ->limit(20)
                ->get();

            return [$upcoming, $history];
        });

        return view('portal.reservations.index', compact('upcoming', 'history'));
    }

    public function create()
    {
        $branches = Branch::where('is_active', true)
            ->orderBy('display_order')
            ->orderBy('name')
            ->get();

        return view('portal.reservations.create', compact('branches'));
    }

    public function store(Request $request)
    {
        $customer = $request->user('customer');

        $data = $request->validate([
            'branch_id'      => ['required', Rule::exists('branches', 'id')->where('is_active', true)],
            'reserved_for'   => ['required', 'date', 'after:now'],
            'party_size'     => ['required', 'integer', 'min:1', 'max:30'],
            'customer_notes' => ['nullable', 'string', 'max:500'],
        ]);

        $branchId = (int) $data['branch_id'];

        $reservation = BranchContext::forBranch($branchId, function () use ($customer, $data, $branchId) {
            return Reservation::create([
                'branch_id'        => $branchId,
                'customer_id'      => $customer->id,
                'party_size'       => $data['party_size'],
                'reserved_for'     => $data['reserved_for'],
                'duration_minutes' => 90,
                'customer_notes'   => $data['customer_notes'] ?? null,
                'status'           => $this->resolveInitialStatus($branchId, $data),
            ]);
        });

        // Notify branch staff there's a new reservation to triage. Done outside
        // the BranchContext block so the loaded relations on $reservation
        // resolve cleanly for the notification body.
        app(\App\Services\NotifyService::class)->newReservation($reservation->load('customer'));

        return redirect()->route('portal.reservations.index')
            ->with('success',
                $reservation->status === ReservationStatus::Confirmed
                    ? "تم تأكيد حجزك (رقم {$reservation->reference})."
                    : "تم استلام طلب الحجز (رقم {$reservation->reference}). سنُؤكّده قريباً."
            );
    }

    public function cancel(Request $request, Reservation $reservation)
    {
        $customer = $request->user('customer');
        abort_unless($reservation->customer_id === $customer->id, 403);

        if (! $reservation->status->isCancellableByCustomer()) {
            return back()->with('error', 'لا يمكن إلغاء هذا الحجز في حالته الحالية.');
        }

        BranchContext::forBranch($reservation->branch_id, function () use ($reservation) {
            $reservation->transitionTo(ReservationStatus::Cancelled, [
                'cancelled_reason' => 'ألغاه الزبون',
            ]);
        });

        return back()->with('success', 'تم إلغاء الحجز.');
    }

    /**
     * Auto-confirmation policy: if the branch has no reservation overlapping
     * the requested slot beyond a soft cap, confirm immediately. Otherwise
     * leave it pending for branch staff to triage.
     *
     * Soft cap = 80% of branch table count — branches without a tables
     * inventory get the more conservative "always pending" outcome.
     */
    protected function resolveInitialStatus(int $branchId, array $data): string
    {
        $totalTables = BranchContext::forBranch($branchId,
            fn () => \App\Models\Table::where('branch_id', $branchId)->count()
        );
        if ($totalTables === 0) {
            return ReservationStatus::Pending->value;
        }

        $when     = \Carbon\Carbon::parse($data['reserved_for']);
        $duration = 90;

        $overlap = BranchContext::forBranch($branchId, function () use ($branchId, $when, $duration) {
            return Reservation::where('branch_id', $branchId)
                ->whereIn('status', [
                    ReservationStatus::Pending->value,
                    ReservationStatus::Confirmed->value,
                    ReservationStatus::Seated->value,
                ])
                ->where('reserved_for', '<', $when->copy()->addMinutes($duration))
                ->whereRaw('DATE_ADD(reserved_for, INTERVAL duration_minutes MINUTE) > ?', [$when])
                ->count();
        });

        $cap = (int) floor($totalTables * 0.80);
        return $overlap >= $cap
            ? ReservationStatus::Pending->value
            : ReservationStatus::Confirmed->value;
    }
}
