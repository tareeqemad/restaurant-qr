@extends('errors.layout')

@section('title', 'الصفحة غير موجودة')
@section('code', '404')
@section('headline', 'لم نجد هذه الصفحة')
@section('message', 'قد يكون الرابط قديماً أو تم نقلك إلى عنوان غير صحيح. ارجع للصفحة السابقة أو ابدأ من المكان المناسب لك.')

@section('illustration')
    {{-- Compass needle broken --}}
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
        <circle cx="12" cy="12" r="10"/>
        <polygon points="16.24 7.76 14.12 14.12 7.76 16.24 9.88 9.88 16.24 7.76" fill="currentColor" fill-opacity=".2"/>
        <circle cx="12" cy="12" r="1" fill="currentColor"/>
    </svg>
@endsection

@section('actions')
    <a href="{{ auth()->check() || request()->is('admin*') ? url('/admin') : url('/') }}" class="btn btn-primary">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>
            <polyline points="9 22 9 12 15 12 15 22"/>
        </svg>
        {{ auth()->check() || request()->is('admin*') ? 'لوحة التحكم' : 'الصفحة الرئيسية' }}
    </a>
    <button type="button" class="btn btn-ghost" data-error-back>
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="m9 18 6-6-6-6"/>
        </svg>
        الصفحة السابقة
    </button>
@endsection
