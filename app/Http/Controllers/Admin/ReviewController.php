<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Review;
use Illuminate\Http\Request;

/**
 * Branch-scoped review moderation. Reads are filtered automatically by
 * BranchScope; per-row mutations re-check via the policy.
 */
class ReviewController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('viewAny', Review::class);

        $query = Review::with(['customer', 'reservation', 'branch'])
            ->orderBy('created_at', 'desc');

        if ($status = $request->get('status')) {
            $query->where('status', $status);
        }
        if ($rating = $request->get('rating')) {
            $query->where('rating', $rating);
        }
        if ($search = $request->get('search')) {
            $query->where(function ($q) use ($search) {
                $q->whereHas('customer', fn ($c) =>
                    $c->where('name', 'like', "%{$search}%")
                      ->orWhere('phone', 'like', "%{$search}%")
                )->orWhere('title', 'like', "%{$search}%")
                 ->orWhere('body',  'like', "%{$search}%");
            });
        }

        $reviews = $query->paginate(20)->withQueryString();

        $stats = [
            'total'   => Review::count(),
            'avg'     => round((float) Review::published()->avg('rating'), 1),
            'hidden'  => Review::hidden()->count(),
            'low'     => Review::published()->where('rating', '<=', 2)->count(),
        ];

        return view('admin.reviews.index', compact('reviews', 'stats'));
    }

    public function hide(Request $request, Review $review)
    {
        $this->authorize('hide', $review);

        $data = $request->validate([
            'hidden_reason' => ['nullable', 'string', 'max:255'],
        ]);

        $review->hide($data['hidden_reason'] ?? null, auth()->user());

        ActivityLog::log('review.hidden',
            "إخفاء تقييم #{$review->id} (★{$review->rating})",
            $review
        );

        return back()->with('success', 'تم إخفاء التقييم.');
    }

    public function unhide(Review $review)
    {
        $this->authorize('unhide', $review);

        $review->unhide();

        ActivityLog::log('review.unhidden',
            "إعادة نشر تقييم #{$review->id}",
            $review
        );

        return back()->with('success', 'تمّت إعادة نشر التقييم.');
    }

    public function destroy(Review $review)
    {
        $this->authorize('delete', $review);

        $id = $review->id;
        $review->delete();

        ActivityLog::log('review.deleted', "حذف تقييم #{$id}");

        return back()->with('success', 'تم حذف التقييم نهائياً.');
    }
}
