@extends('layouts.admin')
@section('title','قسم جديد')
@section('content')
<x-admin.breadcrumb title="قسم جديد" icon="bi-plus-circle-fill"
    :crumbs="[['label' => 'الأقسام', 'url' => route('admin.categories.index')]]" />

<x-admin.data-panel title="قسم جديد" icon="bi-plus-circle-fill">
    <x-slot:actions>
        <a href="{{ route('admin.categories.index') }}" class="btn btn-light"><i class="bi bi-arrow-right"></i> رجوع للقائمة</a>
    </x-slot:actions>


    <form method="POST" action="{{ route('admin.categories.store') }}" enctype="multipart/form-data">
            @include('admin.categories._form')
        </form>
</x-admin.data-panel>
@endsection
