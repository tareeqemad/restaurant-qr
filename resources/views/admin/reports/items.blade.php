@extends('layouts.admin')@section('title','أكثر الأصناف مبيعاً')
@section('content')
<x-admin.breadcrumb title="أكثر الأصناف مبيعاً" icon="bi-trophy"
    subtitle="ترتيب الأصناف حسب الكمية أو الإيراد"
    :crumbs="[['label' => 'التقارير']]" />

<div class="card">
<div class="card-body">
<form class="row g-2 mb-3">
    <div class="col-md-3"><input type="date" name="from" value="{{ $from }}" class="form-control"></div>
    <div class="col-md-3"><input type="date" name="to" value="{{ $to }}" class="form-control"></div>
    <div class="col-md-2"><button class="btn btn-primary w-100">فلترة</button></div>
</form>
<table class="table"><thead class="bg-light"><tr><th>#</th><th>الصنف</th><th>الكمية</th><th>الإجمالي</th></tr></thead>
<tbody>@forelse($rows as $i => $r)
<tr><td>{{ $i + 1 + ($rows->currentPage()-1)*50 }}</td><td class="fw-bold">{{ $r->name_snapshot }}</td><td>{{ number_format((float)$r->qty, 2) }}</td><td>{{ number_format((float)$r->total, 2) }}</td></tr>
@empty
<tr><td colspan="4">
    <x-admin.empty-state
        icon="bi-trophy"
        title="ما في بيانات"
        message="لم تُباع أي أصناف ضمن الفترة المختارة." />
</td></tr>
@endforelse
</tbody></table>
{{ $rows->links() }}
</div></div>
@endsection
