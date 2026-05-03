@extends('layouts.admin')
@section('title', 'تعديل المصروف '.$expense->expense_number)

@section('content')
<x-admin.breadcrumb :title="'تعديل '.$expense->expense_number" icon="bi-cash-coin"
    :crumbs="[['label' => 'المصروفات', 'url' => route('admin.expenses.index')]]" />

<x-admin.data-panel :title="'تعديل المصروف '.$expense->expense_number" icon="bi-pencil-square">
    <form method="POST" action="{{ route('admin.expenses.update', $expense) }}" enctype="multipart/form-data">
        @csrf @method('PUT')
        @include('admin.expenses._form')

        <hr class="my-4">

        <div class="d-flex gap-2 justify-content-end">
            <a href="{{ route('admin.expenses.index') }}" class="btn btn-light">إلغاء</a>
            <button class="btn btn-primary">
                <i class="bi bi-save"></i> حفظ التعديلات
            </button>
        </div>
    </form>
</x-admin.data-panel>
@endsection
