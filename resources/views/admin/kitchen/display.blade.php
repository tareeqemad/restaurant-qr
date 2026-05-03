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
{{-- @livewireScripts bundles Alpine in Livewire v4 — loading a second
     copy from CDN caused "Alpine already initialized" warnings and, worse,
     broke some wire:click DOM updates so actions saved but the UI didn't
     refresh until the page was reloaded. --}}
@livewireScripts
@endpush
@endsection
