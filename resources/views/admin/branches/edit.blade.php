@extends('layouts.admin')
@section('title', 'تعديل فرع')

@section('content')
<x-admin.breadcrumb :title="'تعديل فرع: ' . $branch->name" icon="bi-pencil-square"
    :crumbs="[['label' => 'الفروع', 'url' => route('admin.branches.index')]]" />

<x-admin.data-panel title="تعديل بيانات الفرع" icon="bi-pencil-square">
    <x-slot:actions>
        <a href="{{ route('admin.branches.index') }}" class="btn btn-light">
            <i class="bi bi-arrow-right"></i> رجوع للقائمة
        </a>
    </x-slot:actions>

    <form method="POST" action="{{ route('admin.branches.update', $branch) }}">
        @method('PUT')
        @include('admin.branches._form')
    </form>
</x-admin.data-panel>
@endsection
