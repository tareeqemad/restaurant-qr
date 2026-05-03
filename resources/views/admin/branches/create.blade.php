@extends('layouts.admin')
@section('title', 'فرع جديد')

@section('content')
<x-admin.breadcrumb title="فرع جديد" icon="bi-plus-circle-fill"
    :crumbs="[['label' => 'الفروع', 'url' => route('admin.branches.index')]]" />

<x-admin.data-panel title="فرع جديد" icon="bi-plus-circle-fill">
    <x-slot:actions>
        <a href="{{ route('admin.branches.index') }}" class="btn btn-light">
            <i class="bi bi-arrow-right"></i> رجوع للقائمة
        </a>
    </x-slot:actions>

    <form method="POST" action="{{ route('admin.branches.store') }}">
        @include('admin.branches._form')
    </form>
</x-admin.data-panel>
@endsection
