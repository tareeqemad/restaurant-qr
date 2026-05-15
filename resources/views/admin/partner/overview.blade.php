@extends('layouts.admin')
@section('title', 'لمحة على فروعي')

@push('styles')
    <link rel="stylesheet" href="{{ route('optimized.asset', ['path' => 'assets/dashtic/css/owner-overview.css', 'v' => filemtime(public_path('assets/dashtic/css/owner-overview.css'))]) }}">
@endpush

@section('content')
<x-admin.breadcrumb
    title="لمحة على فروعي"
    icon="bi-buildings"
    subtitle="الأرقام اللحظية لكل فروعك في مكان واحد — بدون ما تبدّل بين الفروع." />

<livewire:admin.partner-overview />

@push('scripts')
@livewireScripts
@endpush
@endsection
