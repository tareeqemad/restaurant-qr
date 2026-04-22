@extends('layouts.admin')
@section('title', 'تعديل موظف')
@section('content')
<x-admin.breadcrumb title="تعديل: {{ $user->name }}" icon="bi-pencil-square"
    :crumbs="[['label' => 'الموظفون', 'url' => route('admin.users.index')]]" />

<x-admin.data-panel title="النموذج" icon="bi-pencil-square">
    <x-slot:actions>
        <a href="{{ route('admin.users.index') }}" class="btn btn-light"><i class="bi bi-arrow-right"></i> رجوع للقائمة</a>
    </x-slot:actions>


    <form action="{{ route('admin.users.update', $user) }}" method="POST">
            @method('PUT')
            @include('admin.users._form')
        </form>
</x-admin.data-panel>
@endsection
