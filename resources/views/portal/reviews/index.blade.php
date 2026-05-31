@extends('portal.layout')
@section('title', __('portal.reviews.title'))

@section('content')
<header class="pf-hero">
    <div class="pf-hero__lead">
        <h1 class="pf-hero__title">{{ __('portal.reviews.title') }}</h1>
        <p class="pf-hero__subtitle">
            {{ trans_choice('portal.reviews.previous_count', $mine->count(), ['count' => $mine->count()]) }}
            @if($eligible->isNotEmpty())
                · <strong style="color: var(--gold);">{{ trans_choice('portal.reviews.awaiting_count', $eligible->count(), ['count' => $eligible->count()]) }}</strong>
            @endif
        </p>
    </div>
</header>

@if($eligible->isNotEmpty())
    <div class="pf-section-head">
        <h2>{{ __('portal.reviews.awaiting_visits') }}</h2>
    </div>
    @foreach($eligible as $r)
        <div class="pf-res">
            <div class="pf-res__date" style="background: rgba(249,168,37,.10);">
                <div class="pf-res__day" style="color: #92400e;">{{ $r->reserved_for->format('d') }}</div>
                <div class="pf-res__month" style="color: #b45309;">{{ $r->reserved_for->translatedFormat('M') }}</div>
            </div>
            <div class="pf-res__body">
                <div class="pf-res__head">
                    <span class="pf-res__branch">{{ $r->branch->name }}</span>
                    <span class="pf-pill pf-pill--warning">{{ __('portal.reviews.awaiting_badge') }}</span>
                </div>
                <div class="pf-res__meta">
                    <span><i class="bi bi-clock"></i> {{ $r->reserved_for->format('Y/m/d H:i') }}</span>
                    <span><i class="bi bi-people"></i> {{ $r->party_size }}</span>
                    <span class="pf-res__ref">{{ $r->reference }}</span>
                </div>
                <a href="{{ route('portal.reviews.create', $r) }}"
                   class="pf-btn mt-2" style="padding: 7px 14px; font-size:.85rem;">
                    <i class="bi bi-star"></i> {{ __('portal.reviews.write_review') }}
                </a>
            </div>
        </div>
    @endforeach
@endif

<div class="pf-section-head">
    <h2>{{ __('portal.reviews.previous_reviews') }}</h2>
</div>

@forelse($mine as $review)
    <div class="pf-card">
        <div class="d-flex align-items-center gap-2 mb-2">
            <div class="pf-stars" data-rating="{{ $review->rating }}">
                @for($i = 1; $i <= 5; $i++)
                    <i class="bi bi-star{{ $i <= $review->rating ? '-fill' : '' }}"></i>
                @endfor
            </div>
            <span class="pf-res__branch">{{ $review->branch->name }}</span>
            @if($review->isHidden())
                <span class="pf-pill pf-pill--secondary">{{ __('portal.reviews.temporarily_hidden') }}</span>
            @endif
        </div>

        @if($review->title)
            <div class="fw-bold mb-1">{{ $review->title }}</div>
        @endif
        @if($review->body)
            <p style="color: var(--ink-2); margin:0 0 8px; white-space: pre-line;">{{ $review->body }}</p>
        @endif

        <div class="d-flex align-items-center justify-content-between" style="font-size: .78rem; color: var(--muted);">
            <span>{{ $review->created_at->translatedFormat('d M Y') }}</span>
            <form method="POST" action="{{ route('portal.reviews.destroy', $review) }}"
                  onsubmit="return confirm(@js(__('portal.reviews.delete_confirm')));">
                @csrf @method('DELETE')
                <button type="submit"
                        class="pf-btn pf-btn--danger"
                        style="padding: 5px 10px; font-size: .78rem;">
                    <i class="bi bi-trash"></i> {{ __('portal.reviews.delete') }}
                </button>
            </form>
        </div>
    </div>
@empty
    <div class="pf-card">
        <div class="pf-empty">
            <i class="bi bi-star"></i>
            <div>{{ __('portal.reviews.empty') }}</div>
        </div>
    </div>
@endforelse

@push('styles')
<style>
    .pf-stars {
        display: inline-flex; gap: 2px;
        font-size: .95rem;
        color: var(--gold);
        line-height: 1;
    }
    .pf-stars .bi-star { color: #d1d5db; }
</style>
@endpush
@endsection
