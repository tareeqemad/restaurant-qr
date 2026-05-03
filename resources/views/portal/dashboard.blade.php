@extends('portal.layout')
@section('title', 'الرئيسية')

@section('content')
<div class="pf-card">
    <h1 class="pf-title">أهلاً، {{ $customer->name }} 👋</h1>
    <p class="pf-subtitle">جاهز لحجز طاولتك القادمة؟</p>

    <div class="pf-stat-grid">
        <div class="pf-stat">
            <div class="pf-stat__value">{{ $stats['upcoming'] }}</div>
            <div class="pf-stat__label">حجوزات قادمة</div>
        </div>
        <div class="pf-stat">
            <div class="pf-stat__value">{{ $stats['completed'] }}</div>
            <div class="pf-stat__label">زيارات مكتملة</div>
        </div>
        <div class="pf-stat">
            <div class="pf-stat__value">{{ $stats['cancelled'] }}</div>
            <div class="pf-stat__label">حجوزات ملغاة</div>
        </div>
    </div>

    <a href="{{ route('portal.reservations.create') }}" class="pf-btn pf-btn--block">
        <i class="bi bi-calendar-plus"></i> احجز طاولة
    </a>
</div>

@if($nextRes)
    <div class="pf-section-head">
        <h2>حجزك القادم</h2>
        <a href="{{ route('portal.reservations.index') }}" class="pf-link-bare" style="font-size:.85rem;">
            كل الحجوزات →
        </a>
    </div>

    <div class="pf-res">
        <div class="pf-res__date">
            <div class="pf-res__day">{{ $nextRes->reserved_for->format('d') }}</div>
            <div class="pf-res__month">{{ $nextRes->reserved_for->translatedFormat('M') }}</div>
        </div>
        <div class="pf-res__body">
            <div class="pf-res__head">
                <span class="pf-res__branch">{{ $nextRes->branch->name }}</span>
                <span class="pf-pill pf-pill--{{ $nextRes->status->color() }}">{{ $nextRes->status->label() }}</span>
            </div>
            <div class="pf-res__meta">
                <span><i class="bi bi-clock"></i> {{ $nextRes->reserved_for->format('H:i') }}</span>
                <span><i class="bi bi-people"></i> {{ $nextRes->party_size }} ضيوف</span>
                <span class="pf-res__ref">{{ $nextRes->reference }}</span>
            </div>
        </div>
    </div>
@else
    <div class="pf-section-head">
        <h2>حجزك القادم</h2>
    </div>
    <div class="pf-card">
        <div class="pf-empty">
            <i class="bi bi-calendar2-event"></i>
            <div>لا توجد حجوزات قادمة. ابدأ بحجز طاولتك الآن.</div>
        </div>
    </div>
@endif
@endsection
