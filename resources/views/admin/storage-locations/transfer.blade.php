@extends('layouts.admin')
@section('title', 'نقل مخزون بين المواقع')

@section('content')
<x-admin.breadcrumb title="نقل بين المواقع" icon="bi-arrow-left-right" />

<livewire:admin.location-transfer />
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
