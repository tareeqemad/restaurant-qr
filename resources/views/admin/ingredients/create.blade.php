@extends('layouts.admin')
@section('title','مكون جديد')
@section('content')
<x-admin.breadcrumb title="مكون جديد" icon="bi-plus-circle-fill"
    :crumbs="[['label' => 'المكونات', 'url' => route('admin.ingredients.index')]]" />

<x-admin.data-panel title="مكون جديد" icon="bi-plus-circle-fill">
    <x-slot:actions>
        <a href="{{ route('admin.ingredients.index') }}" class="btn btn-light"><i class="bi bi-arrow-right"></i> رجوع للقائمة</a>
    </x-slot:actions>


    <form method="POST" action="{{ route('admin.ingredients.store') }}">
            @include('admin.ingredients._form')
        </form>
</x-admin.data-panel>
@endsection
