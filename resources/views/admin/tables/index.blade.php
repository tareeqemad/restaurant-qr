@extends('layouts.admin')
@section('title', 'الطاولات')

@section('content')
<x-admin.breadcrumb title="الطاولات" icon="bi-grid-3x3-gap"
    subtitle="إدارة طاولات المطعم وطباعة أكواد QR" />

<x-admin.data-panel title="قائمة الطاولات" icon="bi-grid-3x3-gap">
    <x-slot:actions>
        <a href="{{ route('admin.tables.create') }}" class="btn btn-primary">
            <i class="bi bi-plus-lg"></i> طاولة جديدة
        </a>
    </x-slot:actions>

    <livewire:admin.tables-board />
</x-admin.data-panel>

@push('scripts')
@livewireScripts
@endpush
@endsection
