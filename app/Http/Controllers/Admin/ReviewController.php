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
    /**
     * Moderation board — Inertia/Vue since Wave 5. Its filters (period,
     * "low rating" bucket, searching the
     * hidden reason and the reservation reference) live here now, so there
     * is one query behind the screen instead of two that drifted.
     */
    public function index(Request $request)
    {
        $this->authorize('viewAny', Review::class);

        $status = (string) $request->get('status', '');
        $rating = (string) $request->get('rating', '');
        $period = (string) $request->get('period', '');
        $search = trim((string) $request->get('search', ''));

        $query = Review::with(['customer', 'reservation.table', 'branch', 'hiddenBy'])
            ->orderBy('created_at', 'desc');

        if (in_array($status, ['published', 'hidden'], true)) {
            $query->where('status', $status);
        }
        if ($rating !== '') {
            // 'low' is the moderator's real question: what needs an answer?
            $rating === 'low'
                ? $query->where('rating', '<=', 2)
                : $query->where('rating', (int) $rating);
        }
        if ($period === 'today') {
            $query->whereDate('created_at', today());
        } elseif ($period === '7d') {
            $query->where('created_at', '>=', now()->subDays(7));
        } elseif ($period === '30d') {
            $query->where('created_at', '>=', now()->subDays(30));
        }
        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->whereHas('customer', fn ($c) => $c
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%"))
                    ->orWhere('title', 'like', "%{$search}%")
                    ->orWhere('body', 'like', "%{$search}%")
                    ->orWhere('hidden_reason', 'like', "%{$search}%")
                    ->orWhereHas('reservation', fn ($r) => $r->where('reference', 'like', "%{$search}%"));
            });
        }

        $reviews = $query->paginate(20)->withQueryString();

        return \App\Support\AdminShell::render('Admin/Reviews/Index', [
            'reviews' => [
                'data' => $reviews->getCollection()->map(fn (Review $r) => [
                    'id' => $r->id,
                    'rating' => (int) $r->rating,
                    'title' => $r->title,
                    'body' => $r->body,
                    'status' => $r->status,
                    'customerName' => $r->customer?->name,
                    'customerPhone' => $r->customer?->phone,
                    'branchName' => $r->branch?->name,
                    'tableNumber' => $r->reservation?->table?->number,
                    'reference' => $r->reservation?->reference,
                    'createdAgo' => $r->created_at?->diffForHumans(),
                    'createdAt' => $r->created_at?->format('Y-m-d H:i'),
                    'hiddenReason' => $r->hidden_reason,
                    'hiddenByName' => $r->hiddenBy?->name,
                    'can' => [
                        'hide' => auth()->user()->can('hide', $r),
                        'unhide' => auth()->user()->can('unhide', $r),
                        'delete' => auth()->user()->can('delete', $r),
                    ],
                    'urls' => [
                        'hide' => route('admin.reviews.hide', $r),
                        'unhide' => route('admin.reviews.unhide', $r),
                        'destroy' => route('admin.reviews.destroy', $r),
                    ],
                ])->all(),
                'links' => $reviews->linkCollection()->toArray(),
                'total' => $reviews->total(),
            ],
            'stats' => [
                'total' => Review::count(),
                'avg' => round((float) Review::published()->avg('rating'), 1),
                'hidden' => Review::hidden()->count(),
                'low' => Review::published()->where('rating', '<=', 2)->count(),
                'lowToday' => Review::published()->where('rating', '<=', 2)->whereDate('created_at', today())->count(),
                'newToday' => Review::whereDate('created_at', today())->count(),
            ],
            'filters' => compact('status', 'rating', 'period', 'search'),
            'urls' => ['index' => route('admin.reviews.index')],
        ]);
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
