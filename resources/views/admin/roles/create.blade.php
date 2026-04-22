@extends('layouts.admin')
@section('title','دور جديد')
@section('content')
<x-admin.breadcrumb title="دور جديد" icon="bi-plus-circle-fill"
    :crumbs="[['label' => 'الأدوار', 'url' => route('admin.roles.index')]]" />

<x-admin.data-panel title="دور جديد" icon="bi-plus-circle-fill">
    <x-slot:actions>
        <a href="{{ route('admin.roles.index') }}" class="btn btn-light"><i class="bi bi-arrow-right"></i> رجوع للقائمة</a>
    </x-slot:actions>


    <form method="POST" action="{{ route('admin.roles.store') }}">
            @include('admin.roles._form')
        </form>
</x-admin.data-panel>
@endsection
