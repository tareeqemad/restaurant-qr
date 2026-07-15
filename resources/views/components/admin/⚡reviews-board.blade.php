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
    public string $ratingFilter = ''; // '', '1'..'5', 'low'

    #[Url(as: 'period', except: '')]
    public string $periodFilter = ''; // '', today, 7d, 30d

    /** Inline form state: review_id => reason text */
    public array $hideReasons = [];

    public function updatingSearch(): void { $this->resetPage(); }
    public function updatingStatusFilter(): void { $this->resetPage(); }
    public function updatingRatingFilter(): void { $this->resetPage(); }
    public function updatingPeriodFilter(): void { $this->resetPage(); }

    public function clearFilters(): void
    {
        $this->reset(['search', 'statusFilter', 'ratingFilter', 'periodFilter']);
        $this->resetPage();
    }

    public function showLow(): void
    {
        $this->ratingFilter = 'low';
        $this->statusFilter = 'published';
        $this->resetPage();
    }

    public function showHidden(): void
    {
        $this->statusFilter = 'hidden';
        $this->resetPage();
    }

    public function showToday(): void
    {
        $this->periodFilter = 'today';
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
        unset($this->reviews, $this->stats, $this->filteredStats);
    }

    public function unhide(int $reviewId): void
    {
        $review = Review::find($reviewId);
        if (! $review) return;
        $this->authorize('unhide', $review);

        $review->unhide();

        ActivityLog::log('review.unhidden', "إعادة نشر تقييم #{$review->id}", $review);
        $this->dispatch('flash', type: 'success', message: 'تمّت إعادة نشر التقييم.');
        unset($this->reviews, $this->stats, $this->filteredStats);
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
        unset($this->reviews, $this->stats, $this->filteredStats);
    }

    #[Computed]
    public function reviews()
    {
        $query = Review::with(['customer', 'reservation.table', 'branch', 'hiddenBy'])
            ->orderBy('created_at', 'desc');

        if ($this->statusFilter !== '') {
            $query->where('status', $this->statusFilter);
        }
        if ($this->ratingFilter !== '') {
            if ($this->ratingFilter === 'low') {
                $query->where('rating', '<=', 2);
            } else {
                $query->where('rating', (int) $this->ratingFilter);
            }
        }
        if ($this->periodFilter === 'today') {
            $query->whereDate('created_at', today());
        } elseif ($this->periodFilter === '7d') {
            $query->where('created_at', '>=', now()->subDays(7));
        } elseif ($this->periodFilter === '30d') {
            $query->where('created_at', '>=', now()->subDays(30));
        }
        if ($this->search !== '') {
            $s = $this->search;
            $query->where(function ($q) use ($s) {
                $q->whereHas('customer', fn ($c) =>
                    $c->where('name', 'like', "%{$s}%")
                      ->orWhere('phone', 'like', "%{$s}%")
                      ->orWhere('email', 'like', "%{$s}%")
                )->orWhere('title', 'like', "%{$s}%")
                 ->orWhere('body',  'like', "%{$s}%")
                 ->orWhere('hidden_reason', 'like', "%{$s}%")
                 ->orWhereHas('reservation', fn ($r) =>
                     $r->where('reference', 'like', "%{$s}%")
                 );
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
            'five'   => Review::published()->where('rating', 5)->count(),
            'low_today' => Review::published()
                ->where('rating', '<=', 2)
                ->whereDate('created_at', today())
                ->count(),
            // "New today" — drives the live badge so the moderator notices
            // fresh reviews coming in without scanning the list.
            'new_today' => Review::whereDate('created_at', today())->count(),
        ];
    }

    #[Computed]
    public function filteredStats(): array
    {
        $items = $this->reviews->getCollection();
        $count = $this->reviews->total();

        return [
            'count' => $count,
            'avg_page' => round((float) $items->where('status', 'published')->avg('rating'), 1),
            'low_page' => $items->where('status', 'published')->where('rating', '<=', 2)->count(),
            'hidden_page' => $items->where('status', 'hidden')->count(),
        ];
    }
}
?>

<div wire:poll.visible.30s class="rb-wrap">
    <x-admin.stat-rail :stats="[
        ['label' => 'إجمالي التقييمات', 'value' => $this->stats['total'],  'icon' => 'bi-star',           'color' => 'primary'],
        ['label' => 'متوسط التقييم',     'value' => $this->stats['avg'].' ★', 'icon' => 'bi-star-fill',  'color' => 'accent'],
        ['label' => 'تقييمات منخفضة',   'value' => $this->stats['low'],    'icon' => 'bi-emoji-frown',     'color' => 'warning'],
        ['label' => 'منخفضة اليوم',      'value' => $this->stats['low_today'], 'icon' => 'bi-exclamation-triangle-fill', 'color' => 'danger'],
        ['label' => '٥ نجوم منشورة',     'value' => $this->stats['five'],   'icon' => 'bi-stars',          'color' => 'success'],
        ['label' => 'مخفية',             'value' => $this->stats['hidden'], 'icon' => 'bi-eye-slash',       'color' => 'muted'],
        ['label' => 'جديدة اليوم',       'value' => $this->stats['new_today'], 'icon' => 'bi-sparkles',     'color' => 'success'],
    ]" />

    <div class="review-command mb-3">
        <div class="review-command__copy">
            <strong>متابعة السمعة</strong>
            <span>ابدأ بالتقييمات المنخفضة والجديدة، ثم قرر: إبقاء، إخفاء، أو متابعة العميل من صفحة الحجز.</span>
        </div>
        <div class="review-command__actions">
            <button type="button" class="btn btn-sm btn-outline-warning" wire:click="showLow">
                <i class="bi bi-emoji-frown"></i> منخفضة
            </button>
            <button type="button" class="btn btn-sm btn-light" wire:click="showHidden">
                <i class="bi bi-eye-slash"></i> مخفية
            </button>
            <button type="button" class="btn btn-sm btn-primary" wire:click="showToday">
                <i class="bi bi-sparkles"></i> اليوم
            </button>
        </div>
    </div>

    <x-admin.data-panel title="قائمة التقييمات" :count="$this->reviews->total()" icon="bi-star">
        <x-slot:actions>
            @if($search !== '' || $statusFilter !== '' || $ratingFilter !== '' || $periodFilter !== '')
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
                           class="form-control" placeholder="الزبون / الهاتف / محتوى التقييم / سبب الإخفاء">
                </div>
                <div class="col-md-2">
                    <label class="form-label small text-muted mb-1">التقييم</label>
                    <select wire:model.live="ratingFilter" class="form-select">
                        <option value="">كل التقييمات</option>
                        <option value="low">منخفضة ١-٢ ★</option>
                        @for($i=5; $i>=1; $i--)
                            <option value="{{ $i }}">{{ $i }} ★</option>
                        @endfor
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small text-muted mb-1">الحالة</label>
                    <select wire:model.live="statusFilter" class="form-select">
                        <option value="">كل الحالات</option>
                        <option value="published">منشور</option>
                        <option value="hidden">مخفي</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small text-muted mb-1">الفترة</label>
                    <select wire:model.live="periodFilter" class="form-select">
                        <option value="">كل الفترات</option>
                        <option value="today">اليوم</option>
                        <option value="7d">آخر ٧ أيام</option>
                        <option value="30d">آخر ٣٠ يوم</option>
                    </select>
                </div>
                <div class="col-12 text-center mt-2">
                    <button type="button" class="btn btn-primary px-5" wire:click="$refresh">
                        <i class="bi bi-funnel"></i> استعلام
                    </button>
                </div>
            </div>
        </x-slot:filters>

        <div class="review-summary mb-3">
            <div>
                <span>نتائج الفلتر</span>
                <strong>{{ number_format($this->filteredStats['count']) }}</strong>
            </div>
            <div>
                <span>متوسط الصفحة</span>
                <strong class="text-primary">{{ $this->filteredStats['avg_page'] ?: '—' }} ★</strong>
            </div>
            <div>
                <span>منخفضة في الصفحة</span>
                <strong class="text-warning">{{ number_format($this->filteredStats['low_page']) }}</strong>
            </div>
            <div>
                <span>مخفية في الصفحة</span>
                <strong class="text-muted">{{ number_format($this->filteredStats['hidden_page']) }}</strong>
            </div>
        </div>

        <div class="reviews-stack">
            @forelse($this->reviews as $review)
                <article class="review {{ $review->isHidden() ? 'is-hidden' : '' }} {{ $review->rating <= 2 && $review->isPublished() ? 'needs-attention' : '' }}" wire:key="rv-{{ $review->id }}">
                    <div class="review__head">
                        <div class="review__stars" aria-label="{{ $review->rating }} of 5">
                            @for($i=1;$i<=5;$i++)
                                <i class="bi bi-star{{ $i <= $review->rating ? '-fill' : '' }}"></i>
                            @endfor
                        </div>
                        @if($review->rating <= 2 && $review->isPublished())
                            <span class="badge bg-warning-transparent text-warning">
                                <i class="bi bi-exclamation-triangle"></i> تحتاج متابعة
                            </span>
                        @endif
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
                                        @if($review->reservation?->table)
                                            <span class="review__sep">·</span>
                                            <span><i class="bi bi-grid-3x3-gap"></i> طاولة {{ $review->reservation->table->number }}</span>
                                        @endif
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

{{-- Styles moved to public/assets/dashtic/css/reviews-board.css, loaded in <head> by the wrapper (@push('styles')) — avoids a flash of unstyled content. --}}
