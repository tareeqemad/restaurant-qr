@extends('layouts.admin')
@section('title','تعديل: '.$role->label)
@section('content')
<x-admin.breadcrumb title="تعديل: {{ $role->label }}" icon="bi-pencil-square"
    :crumbs="[['label' => 'الأدوار', 'url' => route('admin.roles.index')]]" />

<x-admin.data-panel title="النموذج" icon="bi-pencil-square">
    <x-slot:actions>
        <a href="{{ route('admin.roles.index') }}" class="btn btn-light"><i class="bi bi-arrow-right"></i> رجوع للقائمة</a>
    </x-slot:actions>


    <form method="POST" action="{{ route('admin.roles.update', $role) }}">
            @method('PUT')
            @include('admin.roles._form')
        </form>
</x-admin.data-panel>
@endsection
