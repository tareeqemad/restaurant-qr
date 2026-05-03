@extends('layouts.admin')
@section('title', 'استلام: '.$po->number)

@section('content')
<x-admin.breadcrumb
    title="استلام بضاعة"
    icon="bi-box-arrow-in-down"
    subtitle="PO #{{ $po->number }} — {{ $po->supplier?->name }}" />

<livewire:admin.po-receive-form :po="$po" />
@endsection

@push('scripts')
@livewireScripts
<script>
    document.addEventListener('livewire:initialized', () => {
        Livewire.on('flash', (e) => {
            const payload = Array.isArray(e) ? e[0] : e;
            if (window.showNotification) {
                window.showNotification('', payload.message, payload.type);
            }
        });
    });
</script>
@endpush
