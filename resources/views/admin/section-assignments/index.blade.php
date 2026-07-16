@extends('layouts.admin')
@section('title', 'توزيع الأقسام')

{{-- Styles in the <head> so the roster paints styled on the first frame —
     a component-body <style> applies late and flashes unstyled chips. --}}
@push('styles')
    <link rel="stylesheet"
          href="{{ asset('assets/dashtic/css/section-assignments.css') }}?v={{ filemtime(public_path('assets/dashtic/css/section-assignments.css')) }}">
@endpush

@section('content')
<x-admin.breadcrumb title="توزيع الأقسام" icon="bi-people"
    subtitle="مين مسؤول عن أي قسم اليوم" />

<livewire:admin.section-assignments />

@push('scripts')
@livewireScripts
@endpush
@endsection
