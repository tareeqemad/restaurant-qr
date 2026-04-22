@extends('layouts.admin')
@section('title', 'موظف جديد')
@section('content')
<x-admin.breadcrumb title="موظف جديد" icon="bi-plus-circle-fill"
    :crumbs="[['label' => 'الموظفون', 'url' => route('admin.users.index')]]" />

<x-admin.data-panel title="موظف جديد" icon="bi-plus-circle-fill">
    <x-slot:actions>
        <a href="{{ route('admin.users.index') }}" class="btn btn-light"><i class="bi bi-arrow-right"></i> رجوع للقائمة</a>
    </x-slot:actions>


    <form action="{{ route('admin.users.store') }}" method="POST">
            @include('admin.users._form')
        </form>
</x-admin.data-panel>
@endsection
