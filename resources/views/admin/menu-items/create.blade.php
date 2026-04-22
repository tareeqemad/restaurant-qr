@extends('layouts.admin')
@section('title','صنف جديد')
@section('content')
<x-admin.breadcrumb title="صنف جديد" icon="bi-plus-circle-fill"
    :crumbs="[['label' => 'الأصناف', 'url' => route('admin.menu-items.index')]]" />

<x-admin.data-panel title="صنف جديد" icon="bi-plus-circle-fill">
    <x-slot:actions>
        <a href="{{ route('admin.menu-items.index') }}" class="btn btn-light"><i class="bi bi-arrow-right"></i> رجوع للقائمة</a>
    </x-slot:actions>


    <form method="POST" enctype="multipart/form-data" action="{{ route('admin.menu-items.store') }}">
            @include('admin.menu-items._form')
        </form>
</x-admin.data-panel>
@endsection
