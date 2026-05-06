@extends('layouts.admin')
@section('title', 'وحدات القياس')

@section('content')
<x-admin.breadcrumb title="وحدات القياس" icon="bi-rulers"
    subtitle="وحدات قياس المكونات مع معامل التحويل للوحدة الأساسية" />

<x-admin.data-panel title="وحدات القياس" :count="$units->total()" icon="bi-rulers">
    <x-slot:actions>
        <a href="{{ route('admin.units.create') }}" class="btn btn-primary">
            <i class="bi bi-plus-lg"></i> وحدة جديدة
        </a>
    </x-slot:actions>
    <div class="table-responsive">
        <table class="table align-middle">
            <thead class="bg-light">
                <tr>
                    <th>الرمز</th>
                    <th>الاسم</th>
                    <th>النوع</th>
                    <th>معامل التحويل</th>
                    <th>أساسية</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse($units as $u)
                    <tr>
                        <td><code>{{ $u->code }}</code></td>
                        <td class="fw-bold">{{ $u->name }}</td>
                        <td>
                            @switch($u->unit_type)
                                @case('weight') <span class="badge bg-primary-transparent">وزن</span> @break
                                @case('volume') <span class="badge bg-info-transparent">حجم</span> @break
                                @case('count')  <span class="badge bg-success-transparent">عدد</span> @break
                                @case('length') <span class="badge bg-warning-transparent">طول</span> @break
                                @default        <span class="badge bg-secondary">{{ $u->unit_type }}</span>
                            @endswitch
                        </td>
                        <td>{{ \App\Helpers\Qty::format($u->factor_to_base) }}</td>
                        <td>@if($u->is_base)<i class="bi bi-check-circle-fill text-success"></i>@endif</td>
                        <td>
                            <a href="{{ route('admin.units.edit', $u) }}" class="btn btn-sm btn-light"><i class="bi bi-pencil"></i></a>
                            <form action="{{ route('admin.units.destroy', $u) }}" method="POST" class="d-inline" onsubmit="return confirm('حذف؟')">
                                @csrf @method('DELETE')
                                <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6">
                        <x-admin.empty-state icon="bi-rulers" title="ما في وحدات قياس" message="أضف وحدات القياس لمكونات المخزون." />
                    </td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <x-slot:footer>{{ $units->links() }}</x-slot:footer>
</x-admin.data-panel>
@endsection
