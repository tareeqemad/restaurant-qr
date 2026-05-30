@extends('layouts.admin')
@section('title', 'حساب جديد')

@section('content')
<x-admin.breadcrumb
    title="حساب جديد"
    icon="bi-plus-circle-fill"
    :crumbs="[['label' => 'شجرة الحسابات', 'url' => route('admin.accounts.index')]]"/>

<form method="POST" action="{{ route('admin.accounts.store') }}">
    @include('admin.accounts._form')
</form>
@endsection
