@extends('layouts.admin')
@section('title', 'الطاولات')

{{-- Board styles in the <head> so the cards paint styled on the first frame —
     no flash of unstyled content (a component-body <style> applied late). --}}
@push('styles')
    <link rel="stylesheet"
          href="{{ asset('assets/dashtic/css/tables-board.css') }}?v={{ filemtime(public_path('assets/dashtic/css/tables-board.css')) }}">
@endpush

@section('content')
<x-admin.breadcrumb title="الطاولات" icon="bi-grid-3x3-gap"
    subtitle="إدارة الصالة والطاولات وأكواد QR" />

<section class="tables-page-shell">
    {{-- Managers only. Two reasons, and either alone is enough:
         1. "طاولة جديدة" was ungated while TablePolicy::create is admin|manager,
            so a waiter was shown a button that 403s in his hand.
         2. Measured on a real waiter's tablet (845×604): his feed started at
            y=420 — 70% of the screen gone before his job began, leaving 184px,
            one card. A decorative page title and a button he can't press are
            not worth a third of what he can see. --}}
    @can('create', \App\Models\Table::class)
        <div class="tables-page-head">
            <div class="tables-page-title">
                <span>
                    <i class="bi bi-grid-3x3-gap-fill"></i>
                    إدارة الصالة
                </span>
                <h2>تشغيل الطاولات</h2>
            </div>

            <a href="{{ route('admin.tables.create') }}" class="btn btn-primary tables-page-action">
                <i class="bi bi-plus-lg"></i>
                طاولة جديدة
            </a>
        </div>
    @endcan

    <livewire:admin.tables-board />
</section>

@push('scripts')
@livewireScripts
@endpush
@endsection
