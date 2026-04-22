@extends('layouts.admin')
@section('title','تعديل: '.$unit->name)
@section('content')
<x-admin.breadcrumb title="تعديل: {{ $unit->name }}" icon="bi-pencil-square"
    :crumbs="[['label' => 'وحدات القياس', 'url' => route('admin.units.index')]]" />

<x-admin.data-panel title="النموذج" icon="bi-pencil-square">
    <x-slot:actions>
        <a href="{{ route('admin.units.index') }}" class="btn btn-light"><i class="bi bi-arrow-right"></i> رجوع للقائمة</a>
    </x-slot:actions>


    <form method="POST" action="{{ route('admin.units.update', $unit) }}">
            @method('PUT')
            @include('admin.units._form')
        </form>
</x-admin.data-panel>
@endsection
