@extends('layouts.admin')
@section('title', 'تسجيل هدر')

{{--
    NOTE: We intentionally do NOT include public/assets/dashtic/js/relax-waste-logger.js
    here. That legacy file defines `window.wasteLogger` with the OLD shape (no
    `ingredientSearch`, no `reasonLookupId`, no `filteredIngredients`, etc.) and
    races against the `@script` block in the Livewire component, leaving Alpine
    bound to the wrong factory. The Livewire component's `@script` is the single
    source of truth.
--}}

@section('content')
<x-admin.breadcrumb
    title="تسجيل هدر"
    icon="bi-trash3"
    subtitle="سجّل أي كمية خرجت من المخزون بدون بيع — انتهاء صلاحية، تلف، انسكاب، فقد في التحضير، سرقة. النظام يخصم المخزون فوراً ويصنّف الهدر في تقرير الأرباح والخسائر." />

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
