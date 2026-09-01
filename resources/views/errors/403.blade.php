@extends('errors.layout')

@section('title', 'وصول غير مسموح')
@section('code', '403')
@section('headline', 'هذه الصفحة تحتاج صلاحية مختلفة')
@section('message', 'حسابك يعمل بشكل طبيعي، لكنه غير مخوّل لفتح هذه الصفحة. يمكنك الرجوع أو التواصل مع المدير إذا كنت تحتاجها لعملك.')

@section('illustration')
    {{-- Closed padlock --}}
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
        <rect x="3" y="11" width="18" height="11" rx="2" ry="2" fill="currentColor" fill-opacity=".15"/>
        <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
        <circle cx="12" cy="16" r="1.5" fill="currentColor"/>
        <line x1="12" y1="17.5" x2="12" y2="19"/>
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
    <button type="button" class="btn btn-ghost" data-error-back>الصفحة السابقة</button>
@endsection
