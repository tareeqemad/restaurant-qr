@extends('layouts.admin')

@section('title', 'أعمار الذمم')

@section('content')
<x-admin.breadcrumb
    title="أعمار الذمم"
    icon="bi-hourglass-split"
    subtitle="مطابقة الذمم المدينة والدائنة مع تفاصيل العملاء والموردين"
    :crumbs="[['label' => 'التقارير', 'url' => route('admin.reports.index')]]" />

<form method="GET" class="row g-2 align-items-end mb-3">
    <div class="col-md-4">
        <label class="form-label small text-muted fw-bold">كما في تاريخ</label>
        <input type="date" name="as_of" value="{{ $asOf }}" class="form-control">
    </div>
    <div class="col-md-3 d-grid">
        <button class="btn btn-primary"><i class="bi bi-search"></i> استعلام</button>
    </div>
</form>

<div class="row g-3">
    <div class="col-lg-6">
        @include('admin.accounting.partials.aging-table', [
            'title' => 'الذمم المدينة - العملاء',
            'icon' => 'bi-person-lines-fill',
            'rows' => $arRows,
            'totals' => $arTotals,
        ])
    </div>
    <div class="col-lg-6">
        @include('admin.accounting.partials.aging-table', [
            'title' => 'الذمم الدائنة - الموردون',
            'icon' => 'bi-truck',
            'rows' => $apRows,
            'totals' => $apTotals,
        ])
    </div>
</div>
@endsection
