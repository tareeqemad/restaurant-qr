@extends('layouts.admin')
@section('title', 'تعديل: '.$location->name)

@section('content')
<x-admin.breadcrumb title="تعديل: {{ $location->name }}" icon="bi-pencil-square"
    :crumbs="[['label' => 'مواقع التخزين', 'url' => route('admin.storage-locations.index')]]" />
<x-admin.data-panel title="النموذج" icon="bi-pencil-square">
    <x-slot:actions>
        <a href="{{ route('admin.storage-locations.index') }}" class="btn btn-light"><i class="bi bi-arrow-right"></i> رجوع للقائمة</a>
    </x-slot:actions>


    <form method="POST" action="{{ route('admin.storage-locations.update', $location) }}">
        @method('PUT')
        @include('admin.storage-locations._form')
    </form>
</x-admin.data-panel>
@endsection
