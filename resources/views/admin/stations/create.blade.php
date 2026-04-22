@extends('layouts.admin')
@section('title','محطة جديدة')
@section('content')
<x-admin.breadcrumb title="محطة جديدة" icon="bi-plus-circle-fill"
    :crumbs="[['label' => 'المحطات', 'url' => route('admin.stations.index')]]" />

<x-admin.data-panel title="محطة جديدة" icon="bi-plus-circle-fill">
    <x-slot:actions>
        <a href="{{ route('admin.stations.index') }}" class="btn btn-light"><i class="bi bi-arrow-right"></i> رجوع للقائمة</a>
    </x-slot:actions>


    <form method="POST" action="{{ route('admin.stations.store') }}">
            @include('admin.stations._form')
        </form>
</x-admin.data-panel>
@endsection
