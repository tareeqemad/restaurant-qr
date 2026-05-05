@extends('layouts.admin')
@section('title', 'اقتراحات الشراء')

@push('head-scripts')
<script src="{{ asset('assets/dashtic/js/relax-reorder-bulk-builder.js') }}?v={{ filemtime(public_path('assets/dashtic/js/relax-reorder-bulk-builder.js')) }}"></script>
@endpush

@section('content')
<x-admin.breadcrumb title="اقتراحات الشراء" icon="bi-cart-plus-fill"
    subtitle="ماذا تشتري من من بكم — مع تقدير أيام النفاد وإلحاح كل بند." />

<livewire:admin.reorder-bulk-builder />
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
