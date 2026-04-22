@extends('layouts.admin')
@section('title','تعديل طاولة')
@section('content')
<x-admin.breadcrumb title="تعديل طاولة {{ $table->number }}" icon="bi-pencil-square"
    :crumbs="[['label' => 'الطاولات', 'url' => route('admin.tables.index')]]" />

<x-admin.data-panel title="النموذج" icon="bi-pencil-square">
    <x-slot:actions>
        <a href="{{ route('admin.tables.index') }}" class="btn btn-light"><i class="bi bi-arrow-right"></i> رجوع للقائمة</a>
    </x-slot:actions>


    <form action="{{ route('admin.tables.update', $table) }}" method="POST">
            @method('PUT')
            @include('admin.tables._form')
        </form>
</x-admin.data-panel>
@endsection
