@extends('errors.layout')

@section('title', 'محاولات متكررة')
@section('code', '429')
@section('accent', '#e48a8a')
@section('headline', 'مهلاً، نحتاج لحظة قصيرة')
@section('message', 'وصلت عدة محاولات خلال وقت قصير، لذلك أوقفنا التنفيذ مؤقتاً لحماية النظام. انتظر قليلاً ثم جرّب من جديد.')

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
    <button type="button" class="btn btn-primary" data-error-back>
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="m9 18 6-6-6-6"/>
        </svg>
        العودة للخلف
    </button>
    <a href="{{ auth()->check() || request()->is('admin*') ? url('/admin') : url('/') }}" class="btn btn-ghost">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>
            <polyline points="9 22 9 12 15 12 15 22"/>
        </svg>
        {{ auth()->check() || request()->is('admin*') ? 'لوحة التحكم' : 'الصفحة الرئيسية' }}
    </a>
@endsection
