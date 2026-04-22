@extends('layouts.admin')
@section('title', 'تعديل '.$po->number)

@section('content')
<x-admin.breadcrumb title="تعديل: {{ $po->number }}" icon="bi-pencil-square"
    :crumbs="[
        ['label' => 'أوامر الشراء', 'url' => route('admin.purchase-orders.index')],
        ['label' => $po->number, 'url' => route('admin.purchase-orders.show', $po)],
    ]" />

<x-admin.data-panel title="النموذج" icon="bi-pencil-square">
    <x-slot:actions>
        <a href="{{ route('admin.purchase-orders.index') }}" class="btn btn-light"><i class="bi bi-arrow-right"></i> رجوع للقائمة</a>
    </x-slot:actions>


    <form method="POST" action="{{ route('admin.purchase-orders.update', $po) }}">
            @method('PUT')
            @include('admin.purchase-orders._form')
        </form>
</x-admin.data-panel>
@endsection
