@extends('layouts.admin')
@section('title', 'تسجيل هدر')

@push('head-scripts')
<script src="{{ asset('assets/dashtic/js/relax-waste-logger.js') }}?v={{ filemtime(public_path('assets/dashtic/js/relax-waste-logger.js')) }}"></script>
@endpush

@section('content')
<x-admin.breadcrumb
    title="تسجيل هدر"
    icon="bi-trash3"
    subtitle="اختر مكوّناً وكمية وسبباً. لو السبب «انتهاء صلاحية» سيُقترح أقدم دفعة منتهية تلقائياً." />

<livewire:admin.waste-logger
    :preselected-ingredient-id="request('ingredient_id')" />
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
