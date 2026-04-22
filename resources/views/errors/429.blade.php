@extends('errors.layout')

@section('title', '429')
@section('code', '429')
@section('accent', '#e48a8a')
@section('headline', 'طلبات كثيرة جداً')
@section('message', 'تم تجاوز الحد المسموح من الطلبات. الرجاء الانتظار قليلاً قبل المحاولة مرة أخرى.')

@section('illustration')
    {{-- Stopwatch --}}
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
        <circle cx="12" cy="14" r="8" fill="currentColor" fill-opacity=".15"/>
        <path d="M12 10v4l2 2"/>
        <path d="M9 2h6"/>
        <path d="M12 2v2"/>
    </svg>
@endsection

@section('actions')
    <a href="javascript:history.back()" class="btn btn-primary">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="m9 18 6-6-6-6"/>
        </svg>
        العودة للخلف
    </a>
    <a href="{{ url('/admin') }}" class="btn btn-ghost">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>
            <polyline points="9 22 9 12 15 12 15 22"/>
        </svg>
        لوحة التحكم
    </a>
@endsection
