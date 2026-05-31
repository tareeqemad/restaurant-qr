@extends('portal.layout')
@section('title', __('portal.order.unavailable_title'))

@section('content')
<div class="pf-section pf-section--centered">
    <i class="bi bi-shop" style="font-size:3rem;color:#9ca3af;"></i>
    <h1 class="pf-h1 mt-3">{{ __('portal.order.no_branches_heading') }}</h1>
    <p class="pf-sub">{{ __('portal.order.no_branches_body') }}</p>
    <a href="{{ route('portal.dashboard') }}" class="pf-btn pf-btn--primary mt-3">
        <i class="bi bi-arrow-right"></i> {{ __('portal.order.back_home') }}
    </a>
</div>
@endsection
