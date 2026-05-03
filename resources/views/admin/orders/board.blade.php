@extends('layouts.admin')
@section('title', 'طلبات الصالة')

@section('content')
<x-admin.breadcrumb title="طلبات الصالة" icon="bi-person-check-fill"
    subtitle="اعتماد طلبات الزبائن من الطاولات وإرسالها للمطبخ والبار">
    <x-slot:actions>
        <a href="{{ route('admin.orders.list') }}" class="btn btn-light">
            <i class="bi bi-table"></i>
            عرض القائمة
        </a>
    </x-slot:actions>
</x-admin.breadcrumb>

<livewire:admin.waiter-orders-board />

@push('scripts')
@livewireScripts
@endpush
@endsection
