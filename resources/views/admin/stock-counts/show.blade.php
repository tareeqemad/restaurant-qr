@extends('layouts.admin')
@section('title', $count->number)

@section('content')
<x-admin.breadcrumb
    title="جرد {{ $count->number }}"
    icon="bi-clipboard-check"
    subtitle="{{ optional($count->count_date)->format('Y-m-d') }} · {{ $count->storageLocation?->name ?? 'إجمالي الفرع' }} · أنشأه {{ $count->creator?->name ?? '—' }}" />

<livewire:admin.stock-count-workspace :count="$count" />
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
