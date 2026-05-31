@extends('portal.layout')
@section('title', __('portal.order.pick_branch_title'))

@section('content')
<div class="pf-section">
    <h1 class="pf-h1">{{ __('portal.order.pick_branch_heading') }}</h1>
    <p class="pf-sub">{{ __('portal.order.pick_branch_subtitle') }}</p>

    <div class="pob-grid">
        @foreach($branches as $b)
            @php $hue = ($b->id * 47) % 360; $isDefault = $b->id === $defaultBranchId; @endphp
            <a href="{{ route('portal.order.menu', $b) }}" class="pob-card" style="--hue: {{ $hue }};">
                <span class="pob-avatar">{{ mb_substr($b->name, 0, 1, 'UTF-8') }}</span>
                <div class="pob-body">
                    <h3>{{ $b->localizedName() }}</h3>
                    @if($b->city)<span class="pob-city"><i class="bi bi-geo-alt"></i> {{ $b->city }}</span>@endif
                    @if($b->phone)<span class="pob-phone" dir="ltr">{{ $b->phone }}</span>@endif
                </div>
                @if($isDefault)
                    <span class="pob-default" title="{{ __('portal.order.default_branch') }}">
                        <i class="bi bi-star-fill"></i>
                    </span>
                @endif
                <i class="bi bi-arrow-left pob-arrow"></i>
            </a>
        @endforeach
    </div>
</div>

@push('styles')
<style>
    .pob-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 14px; margin-top: 18px; }
    .pob-card {
        display: flex; align-items: center; gap: 12px;
        padding: 16px;
        background: #fff;
        border: 1px solid #e5e7eb;
        border-radius: 14px;
        text-decoration: none; color: inherit;
        position: relative;
        transition: transform .15s, box-shadow .15s, border-color .15s;
    }
    .pob-card::before {
        content:''; position:absolute; inset:0 0 auto 0; height:3px;
        background: linear-gradient(90deg, hsl(var(--hue) 55% 45%), hsl(var(--hue) 60% 35%));
        border-radius: 14px 14px 0 0;
    }
    .pob-card:hover { transform: translateY(-2px); box-shadow: 0 8px 20px -6px rgba(15,23,42,.15); border-color: hsl(var(--hue) 55% 45% / .35); }
    .pob-avatar {
        width: 52px; height: 52px; border-radius: 12px;
        flex-shrink: 0;
        display: inline-flex; align-items: center; justify-content: center;
        background: linear-gradient(135deg, hsl(var(--hue) 55% 50%), hsl(var(--hue) 60% 38%));
        color: #fff; font-weight: 800; font-size: 1.4rem;
    }
    .pob-body { flex: 1; min-width: 0; }
    .pob-body h3 { margin: 0 0 4px; font-size: 1rem; font-weight: 800; color: var(--ink); }
    .pob-city, .pob-phone { display: block; font-size: .78rem; color: #6b7280; }
    .pob-default { color: #f59e0b; font-size: 1rem; }
    .pob-arrow { color: #9ca3af; font-size: 1.1rem; }
</style>
@endpush
@endsection
