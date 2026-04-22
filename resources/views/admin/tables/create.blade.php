@extends('layouts.admin')
@section('title','طاولة جديدة')
@section('content')
<x-admin.breadcrumb title="طاولة جديدة" icon="bi-plus-circle-fill"
    :crumbs="[['label' => 'الطاولات', 'url' => route('admin.tables.index')]]" />

<x-admin.data-panel title="طاولة جديدة" icon="bi-plus-circle-fill">
    <x-slot:actions>
        <a href="{{ route('admin.tables.index') }}" class="btn btn-light"><i class="bi bi-arrow-right"></i> رجوع للقائمة</a>
    </x-slot:actions>


    <form action="{{ route('admin.tables.store') }}" method="POST">
            @include('admin.tables._form')
        </form>
</x-admin.data-panel>
@endsection
