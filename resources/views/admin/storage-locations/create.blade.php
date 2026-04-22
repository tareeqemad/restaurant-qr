@extends('layouts.admin')
@section('title', 'موقع جديد')

@section('content')
<x-admin.breadcrumb title="موقع جديد" icon="bi-plus-circle-fill"
    :crumbs="[['label' => 'مواقع التخزين', 'url' => route('admin.storage-locations.index')]]" />
<x-admin.data-panel title="موقع جديد" icon="bi-plus-circle-fill">
    <x-slot:actions>
        <a href="{{ route('admin.storage-locations.index') }}" class="btn btn-light"><i class="bi bi-arrow-right"></i> رجوع للقائمة</a>
    </x-slot:actions>


    <form method="POST" action="{{ route('admin.storage-locations.store') }}">
        @include('admin.storage-locations._form')
    </form>
</x-admin.data-panel>
@endsection
