@extends('layouts.admin')
@section('title', 'تعديل '.$po->number)

@section('content')
<x-admin.breadcrumb title="تعديل: {{ $po->number }}" icon="bi-pencil-square" />

<livewire:admin.po-line-builder :po="$po" />
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
