@extends('layouts.admin')
@section('title','تعديل: '.$group->name)
@section('content')
<x-admin.breadcrumb title="تعديل: {{ $group->name }}" icon="bi-pencil-square"
    :crumbs="[['label' => 'الإضافات', 'url' => route('admin.modifiers.index')]]" />

<x-admin.data-panel title="النموذج" icon="bi-pencil-square">
    <x-slot:actions>
        <a href="{{ route('admin.modifiers.index') }}" class="btn btn-light"><i class="bi bi-arrow-right"></i> رجوع للقائمة</a>
    </x-slot:actions>


    <form method="POST" action="{{ route('admin.modifiers.update', $group) }}">
            @method('PUT')
            @include('admin.modifiers._form')
        </form>
</x-admin.data-panel>
@endsection
