@extends('layouts.admin')
@section('title', 'تعديل العرض: '.$promotion->name)

@section('content')
<x-admin.breadcrumb
    :title="'تعديل: '.$promotion->name"
    icon="bi-tag-fill"
    :crumbs="[['label' => 'العروض', 'url' => route('admin.promotions.index')]]"/>

<form action="{{ route('admin.promotions.update', $promotion) }}" method="POST">
    @method('PATCH')
    @include('admin.promotions._form')
</form>
@endsection
