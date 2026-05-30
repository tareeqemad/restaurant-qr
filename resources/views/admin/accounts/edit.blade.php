@extends('layouts.admin')
@section('title', 'تعديل حساب: '.$account->name)

@section('content')
<x-admin.breadcrumb
    :title="'تعديل: '.$account->code.' — '.$account->name"
    icon="bi-pencil-square"
    :crumbs="[['label' => 'شجرة الحسابات', 'url' => route('admin.accounts.index')]]"/>

<form method="POST" action="{{ route('admin.accounts.update', $account) }}">
    @method('PATCH')
    @include('admin.accounts._form')
</form>
@endsection
