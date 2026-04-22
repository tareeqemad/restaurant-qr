@extends('layouts.admin')@section('title','تقرير المبيعات')
@section('content')
<x-admin.breadcrumb title="المبيعات اليومية" icon="bi-cash-coin"
    subtitle="تقرير إجمالي المبيعات والخصومات حسب التاريخ"
    :crumbs="[['label' => 'التقارير']]" />

<div class="card">
<div class="card-body">
<form class="row g-2 mb-3">
    <div class="col-md-3"><input type="date" name="from" value="{{ $from }}" class="form-control"></div>
    <div class="col-md-3"><input type="date" name="to" value="{{ $to }}" class="form-control"></div>
    <div class="col-md-2"><button class="btn btn-primary w-100">فلترة</button></div>
</form>
<table class="table"><thead class="bg-light"><tr><th>اليوم</th><th>فواتير</th><th>فرعي</th><th>ضريبة</th><th>خدمة</th><th>الإجمالي</th><th>المدفوع</th></tr></thead>
<tbody>
@forelse($rows as $r)
<tr>
    <td class="fw-bold">{{ $r->day }}</td>
    <td>{{ $r->invoices_count }}</td>
    <td>{{ number_format($r->subtotal, 2) }}</td>
    <td>{{ number_format($r->tax, 2) }}</td>
    <td>{{ number_format($r->service, 2) }}</td>
    <td class="fw-bold">{{ number_format($r->total, 2) }}</td>
    <td class="text-success fw-bold">{{ number_format($r->paid, 2) }}</td>
</tr>
@empty
<tr><td colspan="7">
    <x-admin.empty-state
        icon="bi-cash-coin"
        title="ما في مبيعات"
        message="لا يوجد بيانات مبيعات ضمن الفترة المختارة." />
</td></tr>
@endforelse
</tbody>
@if($rows->count())
<tfoot class="bg-light fw-bold"><tr>
    <td>المجموع</td>
    <td>{{ $rows->sum('invoices_count') }}</td>
    <td>{{ number_format($rows->sum('subtotal'), 2) }}</td>
    <td>{{ number_format($rows->sum('tax'), 2) }}</td>
    <td>{{ number_format($rows->sum('service'), 2) }}</td>
    <td>{{ number_format($rows->sum('total'), 2) }}</td>
    <td class="text-success">{{ number_format($rows->sum('paid'), 2) }}</td>
</tr></tfoot>
@endif
</table>
</div></div>
@endsection
