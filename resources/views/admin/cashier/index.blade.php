@extends('layouts.admin')
@section('title', 'الكاشير')

@section('content')
<x-admin.breadcrumb title="الكاشير" icon="bi-cash-stack"
    subtitle="واجهة سريعة لإدارة الفواتير والدفع — بدون إعادة تحميل الصفحة" />

<livewire:cashier.dashboard />

@push('scripts')
@livewireScripts
@endpush
@endsection
