@extends('layouts.admin')
@section('title', 'عرض جديد')

@section('content')
<x-admin.breadcrumb
    title="عرض جديد"
    icon="bi-tag-fill"
    :crumbs="[['label' => 'العروض', 'url' => route('admin.promotions.index')]]"/>

<form action="{{ route('admin.promotions.store') }}" method="POST">
    @include('admin.promotions._form')
</form>
@endsection
