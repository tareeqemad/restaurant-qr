@extends('layouts.admin')
@section('title', 'مصروف جديد')

@section('content')
<x-admin.breadcrumb title="مصروف جديد" icon="bi-cash-coin"
    :crumbs="[['label' => 'المصروفات', 'url' => route('admin.expenses.index')]]" />

<x-admin.data-panel title="تسجيل مصروف جديد" icon="bi-plus-circle">
    <form method="POST" action="{{ route('admin.expenses.store') }}" enctype="multipart/form-data">
        @csrf
        @include('admin.expenses._form')

        <hr class="my-4">

        <div class="d-flex gap-2 justify-content-end">
            <a href="{{ route('admin.expenses.index') }}" class="btn btn-light">إلغاء</a>
            <button class="btn btn-primary">
                <i class="bi bi-save"></i> حفظ — بانتظار الاعتماد
            </button>
        </div>
    </form>
</x-admin.data-panel>
@endsection
