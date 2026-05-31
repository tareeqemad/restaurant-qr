@extends('portal.layout')
@section('title', __('portal.reservations.title'))

@section('content')
<header class="pf-hero">
    <div class="pf-hero__lead">
        <h1 class="pf-hero__title">{{ __('portal.reservations.title') }}</h1>
        <p class="pf-hero__subtitle">
            {{ __('portal.reservations.summary', ['upcoming' => $upcoming->count(), 'history' => $history->count()]) }}
        </p>
    </div>
    <div class="pf-hero__cta">
        <a href="{{ route('portal.reservations.create') }}" class="pf-btn">
            <i class="bi bi-plus-lg"></i> {{ __('portal.reservations.new') }}
        </a>
    </div>
</header>

<div class="pf-section-head">
    <h2>{{ __('portal.reservations.upcoming') }}</h2>
</div>

@forelse($upcoming as $r)
    <div class="pf-res">
        <div class="pf-res__date">
            <div class="pf-res__day">{{ $r->reserved_for->format('d') }}</div>
            <div class="pf-res__month">{{ $r->reserved_for->translatedFormat('M') }}</div>
        </div>
        <div class="pf-res__body">
            <div class="pf-res__head">
                <span class="pf-res__branch">{{ $r->branch->localizedName() }}</span>
                <span class="pf-pill pf-pill--{{ $r->status->color() }}">{{ $r->status->label() }}</span>
            </div>
            <div class="pf-res__meta">
                <span><i class="bi bi-clock"></i> {{ $r->reserved_for->format('H:i') }}</span>
                <span><i class="bi bi-people"></i> {{ trans_choice('portal.reservations.guest_count', $r->party_size, ['count' => $r->party_size]) }}</span>
                <span class="pf-res__ref">{{ $r->reference }}</span>
            </div>

            @if($r->status->isCancellableByCustomer())
                <form method="POST" action="{{ route('portal.reservations.cancel', $r) }}"
                      class="mt-2"
                      onsubmit="return confirm(@js(__('portal.reservations.cancel_confirm')));">
                    @csrf
                    <button type="submit"
                            class="pf-btn pf-btn--danger"
                            style="padding:6px 12px; font-size:.82rem;">
                        <i class="bi bi-x-circle"></i> {{ __('portal.reservations.cancel') }}
                    </button>
                </form>
            @endif
        </div>
    </div>
@empty
    <div class="pf-card">
        <div class="pf-empty">
            <i class="bi bi-calendar2-week"></i>
            <div>{{ __('portal.reservations.none_upcoming') }}</div>
        </div>
    </div>
@endforelse

@if($history->isNotEmpty())
    <div class="pf-section-head">
        <h2>{{ __('portal.reservations.history') }}</h2>
    </div>

    @foreach($history as $r)
        <div class="pf-res pf-res--past">
            <div class="pf-res__date pf-res__date--muted">
                <div class="pf-res__day">{{ $r->reserved_for->format('d') }}</div>
                <div class="pf-res__month">{{ $r->reserved_for->translatedFormat('M') }}</div>
            </div>
            <div class="pf-res__body">
                <div class="pf-res__head">
                    <span class="pf-res__branch">{{ $r->branch->localizedName() }}</span>
                    <span class="pf-pill pf-pill--{{ $r->status->color() }}">{{ $r->status->label() }}</span>
                </div>
                <div class="pf-res__meta">
                    <span><i class="bi bi-clock"></i> {{ $r->reserved_for->format('Y/m/d H:i') }}</span>
                    <span><i class="bi bi-people"></i> {{ $r->party_size }}</span>
                    <span class="pf-res__ref">{{ $r->reference }}</span>
                </div>
            </div>
        </div>
    @endforeach
@endif

@push('styles')
<style>
    /* History rows visually de-emphasised — smaller date tile, neutral background. */
    .pf-res--past { opacity: .85; }
    .pf-res__date--muted {
        background: #f3f4f6 !important;
    }
    .pf-res__date--muted .pf-res__day { color: #4b5563 !important; }
    .pf-res__date--muted .pf-res__month { color: #6b7280 !important; }

    /* Tighten reservation cards a touch in this view */
    .pf-res + .pf-res { margin-top: 10px; }
</style>
@endpush
@endsection
