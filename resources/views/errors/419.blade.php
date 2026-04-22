@extends('errors.layout')

@section('title', '419')
@section('code', '419')
@section('headline', 'انتهت صلاحية الجلسة')
@section('message', 'لحمايتك، انتهت صلاحية جلستك بسبب عدم النشاط. يرجى تسجيل الدخول من جديد للمتابعة.')

@section('illustration')
    {{-- Hourglass --}}
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
        <path d="M5 22h14"/>
        <path d="M5 2h14"/>
        <path d="M17 22v-4.172a2 2 0 0 0-.586-1.414L12 12l-4.414 4.414A2 2 0 0 0 7 17.828V22" fill="currentColor" fill-opacity=".15"/>
        <path d="M7 2v4.172a2 2 0 0 0 .586 1.414L12 12l4.414-4.414A2 2 0 0 0 17 6.172V2" fill="currentColor" fill-opacity=".15"/>
    </svg>
@endsection

@section('actions')
    <a href="{{ route('login') }}" class="btn btn-primary">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/>
            <polyline points="10 17 15 12 10 7"/>
            <line x1="15" y1="12" x2="3" y2="12"/>
        </svg>
        تسجيل الدخول
    </a>
@endsection
