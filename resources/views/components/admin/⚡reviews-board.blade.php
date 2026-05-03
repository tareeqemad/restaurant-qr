<?php

use App\Models\ActivityLog;
use App\Models\Review;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Reviews moderation board — inline hide/unhide/delete with no page reload.
 *
 * The customer-portal review submission flow doesn't broadcast a Reverb event
 * (yet), so this component polls every 30 seconds. That's fast enough for
 * moderators to react to a fresh 1-star review without burning queries.
 * If real-time becomes important later, dispatching `ReviewCreated` from
 * PortalReviewController + adding `#[On('echo-private:waiters,.review.created')]`
 * here is the upgrade path.
 */
new class extends Component
{
    use WithPagination;

    protected $paginationTheme = 'bootstrap';

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'status', except: '')]
    public string $statusFilter = ''; // '', 'published', 'hidden'

    #[Url(as: 'rating', except: '')]
    public string $ratingFilter = ''; // '', '1'..'5'

    /** Inline form state: review_id => reason text */
    public array $hideReasons = [];

    public function updatingSearch(): void { $this->resetPage(); }
    public function updatingStatusFilter(): void { $this->resetPage(); }
    public function updatingRatingFilter(): void { $this->resetPage(); }

    public function clearFilters(): void
    {
        $this->reset(['search', 'statusFilter', 'ratingFilter']);
        $this->resetPage();
    }

    public function hide(int $reviewId): void
    {
        $review = Review::find($reviewId);
        if (! $review) return;
        $this->authorize('hide', $review);

        $reason = trim((string) ($this->hideReasons[$reviewId] ?? ''));
        $review->hide($reason !== '' ? $reason : null, auth()->user());

        unset($this->hideReasons[$reviewId]);

        ActivityLog::log('review.hidden',
            "إخفاء تقييم #{$review->id} (★{$review->rating})",
            $review
        );

        $this->dispatch('flash', type: 'success', message: 'تم إخفاء التقييم.');
        unset($this->reviews, $this->stats);
    }

    public function unhide(int $reviewId): void
    {
        $review = Review::find($reviewId);
        if (! $review) return;
        $this->authorize('unhide', $review);

        $review->unhide();

        ActivityLog::log('review.unhidden', "إعادة نشر تقييم #{$review->id}", $review);
        $this->dispatch('flash', type: 'success', message: 'تمّت إعادة نشر التقييم.');
        unset($this->reviews, $this->stats);
    }

    public function destroy(int $reviewId): void
    {
        $review = Review::find($reviewId);
        if (! $review) return;
        $this->authorize('delete', $review);

        $id = $review->id;
        $review->delete();

        ActivityLog::log('review.deleted', "حذف تقييم #{$id}");
        $this->dispatch('flash', type: 'success', message: 'تم حذف التقييم نهائياً.');
        unset($this->reviews, $this->stats);
    }

    #[Computed]
    public function reviews()
    {
        $query = Review::with(['customer', 'reservation', 'branch'])
            ->orderBy('created_at', 'desc');

        if ($this->statusFilter !== '') {
            $query->where('status', $this->statusFilter);
        }
        if ($this->ratingFilter !== '') {
            $query->where('rating', (int) $this->ratingFilter);
        }
        if ($this->search !== '') {
            $s = $this->search;
            $query->where(function ($q) use ($s) {
                $q->whereHas('customer', fn ($c) =>
                    $c->where('name', 'like', "%{$s}%")
                      ->orWhere('phone', 'like', "%{$s}%")
                )->orWhere('title', 'like', "%{$s}%")
                 ->orWhere('body',  'like', "%{$s}%");
            });
        }

        return $query->paginate(20);
    }

    #[Computed]
    public function stats(): array
    {
        return [
            'total'  => Review::count(),
            'avg'    => round((float) Review::published()->avg('rating'), 1),
            'hidden' => Review::hidden()->count(),
            'low'    => Review::published()->where('rating', '<=', 2)->count(),
            // "New today" — drives the live badge so the moderator notices
            // fresh reviews coming in without scanning the list.
            'new_today' => Review::whereDate('created_at', today())->count(),
        ];
    }
}
?>

<div wire:poll.visible.30s class="rb-wrap">
    <x-admin.stat-rail :stats="[
        ['label' => 'إجمالي التقييمات', 'value' => $this->stats['total'],  'icon' => 'bi-star',           'color' => 'primary'],
        ['label' => 'متوسط التقييم',     'value' => $this->stats['avg'].' ★', 'icon' => 'bi-star-fill',  'color' => 'accent'],
        ['label' => 'تقييمات منخفضة',   'value' => $this->stats['low'],    'icon' => 'bi-emoji-frown',     'color' => 'warning'],
        ['label' => 'مخفية',             'value' => $this->stats['hidden'], 'icon' => 'bi-eye-slash',       'color' => 'muted'],
        ['label' => 'جديدة اليوم',       'value' => $this->stats['new_today'], 'icon' => 'bi-sparkles',     'color' => 'success'],
    ]" />

    <x-admin.data-panel title="قائمة التقييمات" :count="$this->reviews->total()" icon="bi-star">
        <x-slot:actions>
            @if($search !== '' || $statusFilter !== '' || $ratingFilter !== '')
                <button wire:click="clearFilters" type="button" class="btn btn-light">
                    <i class="bi bi-x-circle"></i> مسح الفلاتر
                </button>
            @endif
        </x-slot:actions>

        <x-slot:filters>
            <div class="row g-2">
                <div class="col-md-6">
                    <input type="text" wire:model.live.debounce.500ms="search"
                           class="form-control" placeholder="🔍 الزبون / الهاتف / محتوى التقييم">
                </div>
                <div class="col-md-3">
                    <select wire:model.live="ratingFilter" class="form-select">
                        <option value="">كل التقييمات</option>
                        @for($i=5; $i>=1; $i--)
                            <option value="{{ $i }}">{{ $i }} ★</option>
                        @endfor
                    </select>
                </div>
                <div class="col-md-3">
                    <select wire:model.live="statusFilter" class="form-select">
                        <option value="">كل الحالات</option>
                        <option value="published">منشور</option>
                        <option value="hidden">مخفي</option>
                    </select>
                </div>
            </div>
        </x-slot:filters>

        <div class="reviews-stack">
            @forelse($this->reviews as $review)
                <article class="review {{ $review->isHidden() ? 'is-hidden' : '' }}" wire:key="rv-{{ $review->id }}">
                    <div class="review__head">
                        <div class="review__stars" aria-label="{{ $review->rating }} of 5">
                            @for($i=1;$i<=5;$i++)
                                <i class="bi bi-star{{ $i <= $review->rating ? '-fill' : '' }}"></i>
                            @endfor
                        </div>
                        @if(session('view_all_branches'))
                            <x-admin.branch-tag :branch="$review->branch" />
                        @endif
                        @if($review->isHidden())
                            <span class="badge bg-secondary">
                                <i class="bi bi-eye-slash"></i> مخفي
                            </span>
                        @endif
                        <div class="review__date">{{ $review->created_at->translatedFormat('d M Y') }}</div>
                    </div>

                    @if($review->title)
                        <h6 class="review__title">{{ $review->title }}</h6>
                    @endif
                    @if($review->body)
                        <p class="review__body">{{ $review->body }}</p>
                    @endif

                    <div class="review__foot">
                        <div class="review__customer">
                            <div class="review__avatar"
                                 style="background: hsl({{ ($review->customer_id * 47) % 360 }} 38% 48%);">
                                {{ mb_substr($review->customer->name ?? '?', 0, 1, 'UTF-8') }}
                            </div>
                            <div>
                                <div class="review__name">{{ $review->customer->name ?? 'زبون' }}</div>
                                <div class="review__meta">
                                    <span><i class="bi bi-telephone"></i><span dir="ltr">{{ $review->customer->phone ?? '—' }}</span></span>
                                    @if($review->reservation)
                                        <span class="review__sep">·</span>
                                        <span><i class="bi bi-calendar-event"></i> {{ $review->reservation->reference }}</span>
                                    @endif
                                </div>
                            </div>
                        </div>

                        <div class="review__actions">
                            @if($review->isPublished())
                                <div class="review__hide-form">
                                    <input type="text" wire:model="hideReasons.{{ $review->id }}"
                                           class="form-control form-control-sm"
                                           placeholder="سبب الإخفاء (اختياري)">
                                    <button wire:click="hide({{ $review->id }})" type="button"
                                            class="btn btn-sm btn-outline-warning">
                                        <i class="bi bi-eye-slash"></i> إخفاء
                                    </button>
                                </div>
                            @else
                                <div class="review__hidden-meta">
                                    @if($review->hiddenBy)
                                        <span>أخفاه {{ $review->hiddenBy->name }} — {{ $review->hidden_at?->diffForHumans() }}</span>
                                    @endif
                                    @if($review->hidden_reason)
                                        <span class="text-muted">— "{{ $review->hidden_reason }}"</span>
                                    @endif
                                </div>
                                <button wire:click="unhide({{ $review->id }})" type="button"
                                        class="btn btn-sm btn-outline-success">
                                    <i class="bi bi-eye"></i> إعادة نشر
                                </button>
                            @endif

                            @if(auth()->user()->isAdmin() || auth()->user()->isOwnerLevel())
                                <button wire:click="destroy({{ $review->id }})" type="button"
                                        wire:confirm="حذف نهائي للتقييم؟ يُفضَّل الإخفاء بدلاً من الحذف."
                                        class="btn btn-sm btn-outline-danger" title="حذف نهائي">
                                    <i class="bi bi-trash"></i>
                                </button>
                            @endif
                        </div>
                    </div>
                </article>
            @empty
                <x-admin.empty-state icon="bi-star"
                    title="لا توجد تقييمات بعد"
                    message="عندما يُكمل الزبائن زياراتهم ويكتبون تقييمات، ستظهر هنا." />
            @endforelse
        </div>

        @if($this->reviews->hasPages())
            <x-slot:footer>{{ $this->reviews->links() }}</x-slot:footer>
        @endif
    </x-admin.data-panel>
</div>

@once
<style>
    .reviews-stack { display: flex; flex-direction: column; gap: 12px; }

    .review {
        background: #fff;
        border: 1px solid #e5e7eb;
        border-radius: 12px;
        padding: 16px 18px;
        transition: border-color .15s, box-shadow .15s;
    }
    .review:hover { border-color: rgba(var(--primary-rgb),.2); box-shadow: 0 4px 12px rgba(0,0,0,.04); }
    .review.is-hidden { background: #fafafa; opacity: .85; }

    .review__head { display: flex; align-items: center; gap: 10px; margin-bottom: 8px; }
    .review__stars { display: inline-flex; gap: 2px; color: var(--gold, #F9A825); font-size: 1rem; }
    .review__stars .bi-star { color: #e5e7eb; }
    .review__date { margin-inline-start: auto; font-size: .78rem; color: #9ca3af; font-weight: 500; }

    .review__title { font-size: .98rem; font-weight: 800; color: #1f2937; margin: 0 0 6px; }
    .review__body { font-size: .92rem; color: #374151; line-height: 1.7; margin: 0 0 12px; white-space: pre-line; }

    .review__foot {
        display: flex; align-items: center; justify-content: space-between;
        gap: 12px; flex-wrap: wrap; padding-top: 10px;
        border-top: 1px solid #f3f4f6;
    }
    .review__customer { display: flex; align-items: center; gap: 10px; min-width: 0; }
    .review__avatar {
        width: 36px; height: 36px; border-radius: 9px;
        color: #fff; font-weight: 800; font-size: .98rem;
        display: inline-flex; align-items: center; justify-content: center; flex-shrink: 0;
    }
    .review__name { font-size: .88rem; font-weight: 700; color: #1f2937; }
    .review__meta {
        font-size: .76rem; color: #6b7280;
        display: inline-flex; align-items: center; gap: 6px; flex-wrap: wrap;
    }
    .review__meta i { font-size: .9em; opacity: .8; margin-inline-end: 4px; }
    .review__sep { color: #d1d5db; }

    .review__actions { display: inline-flex; align-items: center; gap: 6px; flex-wrap: wrap; }
    .review__hide-form { display: inline-flex; gap: 4px; align-items: center; }
    .review__hide-form input { width: 200px; }
    .review__hidden-meta { font-size: .78rem; color: #6b7280; margin-inline-end: 6px; }

    @media (max-width: 768px) {
        .review__hide-form input { width: 100%; }
        .review__actions { width: 100%; }
    }
</style>
@endonce
