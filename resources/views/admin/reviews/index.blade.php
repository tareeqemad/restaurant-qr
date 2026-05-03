@extends('layouts.admin')
@section('title', 'التقييمات')

@section('content')
<x-admin.breadcrumb title="التقييمات" icon="bi-star"
    subtitle="تقييمات زبائن الفرع — أنت لا تستطيع تحرير الكلمات، لكن يمكنك إخفاء غير اللائق" />

<livewire:admin.reviews-board />

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
