@extends('layouts.admin')
@section('title', $station->name)

@section('content')
<x-admin.breadcrumb
    title="شاشة {{ $station->name }}"
    icon="{{ $station->icon ?: 'bi-fire' }}"
    subtitle="عرض الطلبات الحية للمحطة — مصمم للسرعة وكثافة الطلبات" />

<livewire:admin.kitchen-board
    :station-id="$station->id"
    :station-code="$station->code"
    :station-name="$station->name"
    :station-color="$station->color ?: '#1f4733'" />

@push('scripts')
@livewireScripts
<script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.14.1/dist/cdn.min.js"></script>
@endpush
@endsection
