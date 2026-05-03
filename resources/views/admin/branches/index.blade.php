@extends('layouts.admin')
@section('title', 'الفروع')

@section('content')
<x-admin.breadcrumb title="الفروع" icon="bi-buildings"
    subtitle="إدارة فروع المطعم — الكاشير والمطبخ والمخزون لكلٍّ منها معزولة تماماً" />

<livewire:admin.branches-board />

@push('scripts')
@livewireScripts
<script>
    // Surface Livewire flash dispatches as the same toast/alert UI the rest
    // of the admin uses (admin-crud.js installs a global `notify` helper).
    document.addEventListener('livewire:initialized', () => {
        Livewire.on('flash', (e) => {
            const payload = Array.isArray(e) ? e[0] : e;
            const msg  = payload?.message ?? '';
            const type = payload?.type ?? 'info';
            if (window.notify) {
                window.notify(msg, type);
            } else {
                alert(msg);
            }
        });
    });
</script>
@endpush
@endsection
