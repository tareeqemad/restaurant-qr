@extends('layouts.admin')
@section('title', 'مورد جديد')

@section('content')
<x-admin.breadcrumb title="مورد جديد" icon="bi-plus-circle-fill"
    :crumbs="[['label' => 'الموردون', 'url' => route('admin.suppliers.index')]]" />

<x-admin.data-panel title="مورد جديد" icon="bi-plus-circle-fill">
    <x-slot:actions>
        <a href="{{ route('admin.suppliers.index') }}" class="btn btn-light"><i class="bi bi-arrow-right"></i> رجوع للقائمة</a>
    </x-slot:actions>


    <form method="POST" action="{{ route('admin.suppliers.store') }}">
            @include('admin.suppliers._form')
        </form>
</x-admin.data-panel>
@endsection
