@extends('layouts.admin')
@section('title','مسبب حساسية جديد')
@section('content')
<x-admin.breadcrumb title="مسبب حساسية جديد" icon="bi-plus-circle-fill"
    :crumbs="[['label' => 'مسببات الحساسية', 'url' => route('admin.allergens.index')]]" />

<x-admin.data-panel title="مسبب حساسية جديد" icon="bi-plus-circle-fill">
    <x-slot:actions>
        <a href="{{ route('admin.allergens.index') }}" class="btn btn-light"><i class="bi bi-arrow-right"></i> رجوع للقائمة</a>
    </x-slot:actions>


    <form method="POST" action="{{ route('admin.allergens.store') }}">
            @include('admin.allergens._form')
        </form>
</x-admin.data-panel>
@endsection
