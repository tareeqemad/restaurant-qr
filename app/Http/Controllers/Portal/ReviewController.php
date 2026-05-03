<?php

namespace App\Http\Controllers\Portal;

use App\Enums\ReservationStatus;
use App\Http\Controllers\Controller;
use App\Models\Reservation;
use App\Models\Review;
use App\Support\BranchContext;
use Illuminate\Http\Request;

/**
 * Customer-facing reviews.
 *
 * Eligibility rule (the credibility lever):
 *   A customer can write a review only against one of their own
 *   `completed` reservations that doesn't already have a review.
 *
 * Customers may delete their own review (they own their words). Branch
 * staff can hide but never edit/delete.
 *
 * Cross-branch reads use BranchContext::unscoped — the customer's data
 * spans every branch they've visited.
 */
class ReviewController extends Controller
{
    public function index(Request $request)
    {
        $customer = $request->user('customer');

        [$mine, $eligible] = BranchContext::unscoped(function () use ($customer) {
            $mine = Review::with(['branch', 'reservation'])
                ->where('customer_id', $customer->id)
                ->latest()
                ->get();

            $eligible = Reservation::with('branch')
                ->where('customer_id', $customer->id)
                ->where('status', ReservationStatus::Completed->value)
                ->whereDoesntHave('reviewRelation', fn ($q) => $q->where('customer_id', $customer->id))
                ->latest('reserved_for')
                ->get();

            return [$mine, $eligible];
        });

        return view('portal.reviews.index', compact('mine', 'eligible'));
    }

    public function create(Request $request, Reservation $reservation)
    {
        $customer = $request->user('customer');
        $this->ensureEligible($customer->id, $reservation);

        return view('portal.reviews.create', compact('reservation'));
    }

    public function store(Request $request, Reservation $reservation)
    {
        $customer = $request->user('customer');
        $this->ensureEligible($customer->id, $reservation);

        $data = $request->validate([
            'rating' => ['required', 'integer', 'between:1,5'],
            'title'  => ['nullable', 'string', 'max:120'],
            'body'   => ['nullable', 'string', 'max:2000'],
        ]);

        $review = BranchContext::forBranch($reservation->branch_id, fn () =>
            Review::create([
                'branch_id'      => $reservation->branch_id,
                'customer_id'    => $customer->id,
                'reservation_id' => $reservation->id,
                'rating'         => $data['rating'],
                'title'          => $data['title'] ?? null,
                'body'           => $data['body'] ?? null,
                'status'         => 'published',
            ])
        );

        // Notify branch admins/managers — low ratings (≤2) auto-escalate to
        // warning severity inside the notification class.
        app(\App\Services\NotifyService::class)->newReview($review->load('customer'));

        return redirect()->route('portal.reviews.index')
            ->with('success', 'شكراً لك على تقييمك!');
    }

    public function destroy(Request $request, Review $review)
    {
        $customer = $request->user('customer');

        // Customers can delete only their own review. Branch staff use the
        // admin moderation flow (hide), not delete — different controller.
        abort_unless($review->customer_id === $customer->id, 403);

        BranchContext::forBranch($review->branch_id, fn () => $review->delete());

        return redirect()->route('portal.reviews.index')
            ->with('success', 'تم حذف تقييمك.');
    }

    /**
     * Throws 403 if the reservation is not the customer's own, not
     * Completed, or already has a review. Centralised so create/store
     * share the same gate.
     */
    protected function ensureEligible(int $customerId, Reservation $reservation): void
    {
        abort_unless($reservation->customer_id === $customerId, 403,
            'هذا الحجز ليس حجزك.');

        abort_unless($reservation->status === ReservationStatus::Completed, 403,
            'يمكن التقييم فقط بعد اكتمال الزيارة.');

        $exists = BranchContext::unscoped(fn () =>
            Review::where('reservation_id', $reservation->id)->exists()
        );
        abort_if($exists, 403, 'سبق وقمت بتقييم هذه الزيارة.');
    }
}
