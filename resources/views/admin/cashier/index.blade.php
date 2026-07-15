@extends('layouts.admin')
@section('title', 'الكاشير')

@section('content')
<x-admin.breadcrumb title="الكاشير" icon="bi-cash-stack" />

{{-- ?session=ID pre-selects a session's checkout in the dashboard. This is
     the merge backbone: every classic money action (split, settle, unpark,
     void, transfer) posts to its existing CashierController route and the
     controller redirects back here with the session id, so the cashier lands
     on the same live checkout with wire:poll resuming — one screen, no bounce
     to the old server-rendered page. --}}
<livewire:cashier.dashboard :session="request()->integer('session') ?: null" />

@push('scripts')
@livewireScripts
@endpush
@endsection
