@extends('layouts.admin')@section('title','استهلاك المخزن')
@section('content')
<x-admin.breadcrumb title="استهلاك المخزن" icon="bi-boxes"
    subtitle="حركة المكونات المستهلكة في فترة محددة"
    :crumbs="[['label' => 'التقارير']]" />

<div class="card">
<div class="card-body">
<form class="row g-2 mb-3">
    <div class="col-md-3"><input type="date" name="from" value="{{ $from }}" class="form-control"></div>
    <div class="col-md-3"><input type="date" name="to" value="{{ $to }}" class="form-control"></div>
    <div class="col-md-2"><button class="btn btn-primary w-100">فلترة</button></div>
</form>
<table class="table"><thead class="bg-light"><tr><th>المكون</th><th>إضافات</th><th>استهلاك</th><th>هدر</th><th>تكلفة الاستهلاك</th></tr></thead>
<tbody>@foreach($rows as $ingredientId => $byType)
    @php $ing = $byType->first()->ingredient; @endphp
    <tr>
        <td class="fw-bold">{{ $ing->name }}</td>
        <td class="text-success">{{ number_format((float)($byType->firstWhere('type','in')->qty ?? 0), 2) }}</td>
        <td class="text-warning">{{ number_format((float)($byType->firstWhere('type','out')->qty ?? 0), 2) }}</td>
        <td class="text-danger">{{ number_format((float)($byType->firstWhere('type','waste')->qty ?? 0), 2) }}</td>
        <td>{{ number_format((float)($byType->firstWhere('type','out')->total_cost ?? 0), 2) }}</td>
    </tr>
@endforeach</tbody></table>
</div></div>
@endsection
