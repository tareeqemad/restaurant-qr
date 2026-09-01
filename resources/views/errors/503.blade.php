@extends('errors.layout')

@section('title', 'صيانة مؤقتة')
@section('code', '503')
@section('accent', '#8cc8a3')
@section('headline', 'الخدمة متوقفة مؤقتاً')
@section('message', 'نجري تحديثاً قصيراً على النظام. بياناتك محفوظة، حدّث الصفحة بعد قليل للمتابعة.')

@section('illustration')
    {{-- Wrench + gear --}}
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
        <path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z" fill="currentColor" fill-opacity=".15"/>
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
        تحديث الصفحة
    </button>
    <a href="{{ auth()->check() || request()->is('admin*') ? url('/admin') : url('/') }}" class="btn btn-ghost">{{ auth()->check() || request()->is('admin*') ? 'لوحة التحكم' : 'الصفحة الرئيسية' }}</a>
@endsection
