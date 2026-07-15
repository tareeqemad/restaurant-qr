@extends('layouts.admin')
@section('title', 'الحجوزات')

@push('styles')
    <link rel="stylesheet" href="{{ asset('assets/dashtic/css/reservations-board.css') }}?v={{ filemtime(public_path('assets/dashtic/css/reservations-board.css')) }}">
@endpush

@section('content')
<x-admin.breadcrumb title="الحجوزات" icon="bi-calendar-event"
    subtitle="حجوزات الفرع الحالي — تأكيد، جلوس، إغلاق، إلغاء" />

<livewire:admin.reservations-board />

@push('scripts')
@livewireScripts
<script>
    document.addEventListener('livewire:initialized', () => {
        Livewire.on('flash', (e) => {
            const payload = Array.isArray(e) ? e[0] : e;
            const msg  = payload?.message ?? '';
            const type = payload?.type ?? 'info';
            if (window.notify) window.notify(msg, type); else alert(msg);
        });
    });
</script>
@endpush
@endsection
