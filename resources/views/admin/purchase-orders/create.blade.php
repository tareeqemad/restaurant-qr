@extends('layouts.admin')
@section('title', 'أمر شراء جديد')

@section('content')
<x-admin.breadcrumb title="أمر شراء جديد" icon="bi-plus-circle-fill"
    :crumbs="[['label' => 'أوامر الشراء', 'url' => route('admin.purchase-orders.index')]]" />

<x-admin.data-panel title="أمر شراء جديد" icon="bi-plus-circle-fill">
    <x-slot:actions>
        <a href="{{ route('admin.purchase-orders.index') }}" class="btn btn-light"><i class="bi bi-arrow-right"></i> رجوع للقائمة</a>
    </x-slot:actions>


    <form method="POST" action="{{ route('admin.purchase-orders.store') }}">
            @include('admin.purchase-orders._form')
        </form>
</x-admin.data-panel>
@endsection
