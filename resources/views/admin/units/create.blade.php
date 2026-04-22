@extends('layouts.admin')
@section('title','وحدة جديدة')
@section('content')
<x-admin.breadcrumb title="وحدة جديدة" icon="bi-plus-circle-fill"
    :crumbs="[['label' => 'وحدات القياس', 'url' => route('admin.units.index')]]" />

<x-admin.data-panel title="وحدة جديدة" icon="bi-plus-circle-fill">
    <x-slot:actions>
        <a href="{{ route('admin.units.index') }}" class="btn btn-light"><i class="bi bi-arrow-right"></i> رجوع للقائمة</a>
    </x-slot:actions>


    <form method="POST" action="{{ route('admin.units.store') }}">
            @include('admin.units._form')
        </form>
</x-admin.data-panel>
@endsection
