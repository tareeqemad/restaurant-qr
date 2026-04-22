@extends('layouts.admin')@section('title','تقرير الورديات')
@section('content')
<x-admin.breadcrumb title="تقرير الورديات" icon="bi-clock-history"
    subtitle="كل الورديات المفتوحة والمغلقة"
    :crumbs="[['label' => 'التقارير']]" />

<div class="card">
<div class="card-body">
<table class="table"><thead class="bg-light"><tr><th>موظف</th><th>افتتاح</th><th>إغلاق</th><th>مدة</th><th>كاش</th><th>كارد</th><th>أخرى</th><th>مجموع</th><th>فرق</th></tr></thead>
<tbody>@foreach($shifts as $s)
<tr>
    <td class="fw-bold">{{ $s->user->name }}</td>
    <td>{{ $s->opened_at->format('Y-m-d H:i') }}</td>
    <td>{{ $s->closed_at?->format('Y-m-d H:i') ?? '—' }}</td>
    <td>{{ $s->closed_at ? $s->opened_at->diffForHumans($s->closed_at, true) : 'مفتوح' }}</td>
    <td>{{ number_format((float)$s->cash_sales, 2) }}</td>
    <td>{{ number_format((float)$s->card_sales, 2) }}</td>
    <td>{{ number_format((float)$s->other_sales, 2) }}</td>
    <td class="fw-bold">{{ number_format((float)$s->total_sales, 2) }}</td>
    <td class="{{ (float)$s->cash_variance < 0 ? 'text-danger' : 'text-success' }} fw-bold">{{ number_format((float)$s->cash_variance, 2) }}</td>
</tr>
@endforeach</tbody></table>
{{ $shifts->links() }}
</div></div>
@endsection
