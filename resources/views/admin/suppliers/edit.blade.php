@extends('layouts.admin')
@section('title', 'تعديل: '.$supplier->name)

@section('content')
<x-admin.breadcrumb title="تعديل: {{ $supplier->name }}" icon="bi-pencil-square"
    :crumbs="[
        ['label' => 'الموردون', 'url' => route('admin.suppliers.index')],
        ['label' => $supplier->name, 'url' => route('admin.suppliers.show', $supplier)],
    ]" />

<x-admin.data-panel title="النموذج" icon="bi-pencil-square">
    <x-slot:actions>
        <a href="{{ route('admin.suppliers.index') }}" class="btn btn-light"><i class="bi bi-arrow-right"></i> رجوع للقائمة</a>
    </x-slot:actions>


    <form method="POST" action="{{ route('admin.suppliers.update', $supplier) }}">
            @method('PUT')
            @include('admin.suppliers._form')
        </form>
</x-admin.data-panel>
@endsection
