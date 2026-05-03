@extends('layouts.admin')
@section('title', 'أمر شراء جديد')

@section('content')
<x-admin.breadcrumb title="أمر شراء جديد" icon="bi-plus-circle-fill" />

<livewire:admin.po-line-builder />
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
