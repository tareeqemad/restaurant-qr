@extends('layouts.admin')
@section('title','تعديل: '.$ingredient->name)
@section('content')
<x-admin.breadcrumb title="تعديل: {{ $ingredient->name }}" icon="bi-pencil-square"
    :crumbs="[['label' => 'المكونات', 'url' => route('admin.ingredients.index')]]" />

<x-admin.data-panel title="النموذج" icon="bi-pencil-square">
    <x-slot:actions>
        <a href="{{ route('admin.ingredients.index') }}" class="btn btn-light"><i class="bi bi-arrow-right"></i> رجوع للقائمة</a>
    </x-slot:actions>


    <form method="POST" action="{{ route('admin.ingredients.update', $ingredient) }}">
            @method('PUT')
            @include('admin.ingredients._form')
        </form>
</x-admin.data-panel>
@endsection
