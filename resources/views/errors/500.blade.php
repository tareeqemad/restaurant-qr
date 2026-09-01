@extends('errors.layout')

@section('title', 'خلل غير متوقع')
@section('code', '500')
@section('accent', '#e8a24a')
@section('headline', 'تعذر إكمال العملية الآن')
@section('message', 'حدث خلل غير متوقع ولم نتمكن من إكمال طلبك. جرّب مرة ثانية، وإذا تكرر الأمر أخبر موظف المطعم.')

@section('illustration')
    {{-- Warning triangle with ! --}}
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
        <path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z" fill="currentColor" fill-opacity=".15"/>
        <line x1="12" y1="9" x2="12" y2="13" stroke-width="2"/>
        <circle cx="12" cy="17" r="1" fill="currentColor"/>
    </svg>
@endsection

@section('actions')
    <button type="button" class="btn btn-primary" data-error-reload>
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8"/>
            <path d="M21 3v5h-5"/>
            <path d="M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16"/>
            <path d="M3 21v-5h5"/>
        </svg>
        إعادة المحاولة
    </button>
    <a href="{{ auth()->check() || request()->is('admin*') ? url('/admin') : url('/') }}" class="btn btn-ghost">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>
            <polyline points="9 22 9 12 15 12 15 22"/>
        </svg>
        {{ auth()->check() || request()->is('admin*') ? 'لوحة التحكم' : 'الصفحة الرئيسية' }}
    </a>
@endsection
