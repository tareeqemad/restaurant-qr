@extends('layouts.admin')
@section('title','تعديل: '.$allergen->name)
@section('content')
<x-admin.breadcrumb title="تعديل: {{ $allergen->name }}" icon="bi-pencil-square"
    :crumbs="[['label' => 'مسببات الحساسية', 'url' => route('admin.allergens.index')]]" />

<x-admin.data-panel title="النموذج" icon="bi-pencil-square">
    <x-slot:actions>
        <a href="{{ route('admin.allergens.index') }}" class="btn btn-light"><i class="bi bi-arrow-right"></i> رجوع للقائمة</a>
    </x-slot:actions>


    <form method="POST" action="{{ route('admin.allergens.update', $allergen) }}">
            @method('PUT')
            @include('admin.allergens._form')
        </form>
</x-admin.data-panel>
@endsection
