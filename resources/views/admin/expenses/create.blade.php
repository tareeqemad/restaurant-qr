@extends('layouts.admin')
@section('title', 'مصروف جديد')

@php
    $hasActiveBranch = (bool) \App\Support\BranchContext::current();
@endphp

@section('content')
<x-admin.breadcrumb title="مصروف جديد" icon="bi-cash-coin"
    :crumbs="[['label' => 'المصروفات', 'url' => route('admin.expenses.index')]]" />

<x-admin.data-panel title="تسجيل مصروف جديد" icon="bi-plus-circle">
    @unless($hasActiveBranch)
        <div class="alert alert-warning">
            <i class="bi bi-exclamation-triangle-fill"></i>
            اختر فرعاً محدداً من الأعلى قبل تسجيل المصروف. التسجيل غير متاح في وضع كل الفروع.
        </div>
    @endunless

    <form method="POST" action="{{ route('admin.expenses.store') }}" enctype="multipart/form-data">
        @csrf
        @include('admin.expenses._form')

        <hr class="my-4">

        <div class="form-actions">
            <a href="{{ route('admin.expenses.index') }}" class="btn btn-light">إلغاء</a>
            <button class="btn btn-primary" @disabled(! $hasActiveBranch || $categories->isEmpty())>
                <i class="bi bi-save"></i> حفظ — بانتظار الاعتماد
            </button>
        </div>
    </form>
</x-admin.data-panel>
@endsection
