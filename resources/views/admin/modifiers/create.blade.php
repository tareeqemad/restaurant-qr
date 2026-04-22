@extends('layouts.admin')
@section('title','مجموعة إضافات جديدة')
@section('content')
<x-admin.breadcrumb title="مجموعة إضافات جديدة" icon="bi-plus-circle-fill"
    :crumbs="[['label' => 'الإضافات', 'url' => route('admin.modifiers.index')]]" />

<x-admin.data-panel title="مجموعة إضافات جديدة" icon="bi-plus-circle-fill">
    <x-slot:actions>
        <a href="{{ route('admin.modifiers.index') }}" class="btn btn-light"><i class="bi bi-arrow-right"></i> رجوع للقائمة</a>
    </x-slot:actions>


    <form method="POST" action="{{ route('admin.modifiers.store') }}">
            @include('admin.modifiers._form')
        </form>
</x-admin.data-panel>
@endsection
