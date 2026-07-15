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

    <livewire:admin.tables-board />
</section>

@push('scripts')
@livewireScripts
@endpush
@endsection
