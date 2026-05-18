@extends('layouts.admin')
@section('title', 'الكاشير')

@section('content')
<x-admin.breadcrumb title="الكاشير" icon="bi-cash-stack" />

<livewire:cashier.dashboard />

@push('scripts')
@livewireScripts
@endpush
@endsection
