@extends('layouts.admin')
@section('title','تعديل قسم')
@section('content')
<x-admin.breadcrumb title="تعديل: {{ $category->name }}" icon="bi-pencil-square"
    :crumbs="[['label' => 'الأقسام', 'url' => route('admin.categories.index')]]" />

<x-admin.data-panel title="النموذج" icon="bi-pencil-square">
    <x-slot:actions>
        <a href="{{ route('admin.categories.index') }}" class="btn btn-light"><i class="bi bi-arrow-right"></i> رجوع للقائمة</a>
    </x-slot:actions>


    <form method="POST" action="{{ route('admin.categories.update', $category) }}" enctype="multipart/form-data">
            @method('PUT')
            @include('admin.categories._form')
        </form>
</x-admin.data-panel>
@endsection
