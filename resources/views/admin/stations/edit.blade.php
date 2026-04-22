@extends('layouts.admin')
@section('title','تعديل: '.$station->name)
@section('content')
<x-admin.breadcrumb title="تعديل: {{ $station->name }}" icon="bi-pencil-square"
    :crumbs="[['label' => 'المحطات', 'url' => route('admin.stations.index')]]" />

<x-admin.data-panel title="النموذج" icon="bi-pencil-square">
    <x-slot:actions>
        <a href="{{ route('admin.stations.index') }}" class="btn btn-light"><i class="bi bi-arrow-right"></i> رجوع للقائمة</a>
    </x-slot:actions>


    <form method="POST" action="{{ route('admin.stations.update', $station) }}">
            @method('PUT')
            @include('admin.stations._form')
        </form>
</x-admin.data-panel>
@endsection
