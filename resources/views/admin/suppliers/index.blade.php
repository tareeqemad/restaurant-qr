@extends('layouts.admin')
@section('title', 'الموردون')

@section('content')
<x-admin.breadcrumb title="الموردون" icon="bi-truck"
    subtitle="إدارة الموردين وربطهم بالمكونات لتتبع التكلفة والشراء" />

<x-admin.stat-rail :stats="[
    ['label' => 'إجمالي الموردين', 'value' => $stats['total'],    'icon' => 'bi-truck',             'color' => 'primary'],
    ['label' => 'موردون فعّالون',    'value' => $stats['active'],   'icon' => 'bi-check-circle-fill', 'color' => 'success'],
    ['label' => 'مرتبطون بمكونات',   'value' => $stats['linked'],   'icon' => 'bi-link-45deg',        'color' => 'accent'],
    ['label' => 'غير فعّالين',       'value' => $stats['inactive'], 'icon' => 'bi-pause-circle',      'color' => 'muted'],
]" />

<x-admin.data-panel title="قائمة الموردين" :count="$suppliers->total()" icon="bi-truck">
    <x-slot:actions>
        <a href="{{ route('admin.suppliers.create') }}" class="btn btn-primary">
            <i class="bi bi-plus-lg"></i> مورد جديد
        </a>
        @if(request()->hasAny(['search', 'inactive']))
            <a href="{{ route('admin.suppliers.index') }}" class="btn btn-light">
                <i class="bi bi-x-circle"></i> مسح الفلاتر
            </a>
        @endif
    </x-slot:actions>

    <x-slot:filters>
        <form class="row g-2">
            <div class="col-md-6">
                <input name="search" value="{{ request('search') }}" class="form-control" placeholder="🔍 اسم أو هاتف أو شخص مسؤول">
            </div>
            <div class="col-md-4">
                <label class="d-flex align-items-center gap-2 h-100">
                    <input type="checkbox" name="inactive" value="1" @checked(request('inactive'))>
                    عرض غير الفعّالين فقط
                </label>
            </div>
            <div class="col-12 text-center mt-2">
                <button class="btn btn-primary px-5"><i class="bi bi-funnel"></i> استعلام</button>
            </div>
        </form>
    </x-slot:filters>

    <div class="table-responsive">
        <table class="table align-middle">
            <thead class="bg-light">
                <tr>
                    <th>المورد</th>
                    <th>الشخص المسؤول</th>
                    <th>الهاتف</th>
                    <th>البريد</th>
                    <th>المكونات</th>
                    <th>الحالة</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse($suppliers as $s)
                    <tr>
                        <td>
                            <div class="fw-bold">{{ $s->name }}</div>
                            @if($s->address)
                                <small class="text-muted d-block text-truncate" style="max-width:240px;">
                                    <i class="bi bi-geo-alt"></i> {{ $s->address }}
                                </small>
                            @endif
                        </td>
                        <td>{{ $s->contact_person ?? '—' }}</td>
                        <td dir="ltr">{{ $s->phone ?? '—' }}</td>
                        <td dir="ltr">{{ $s->email ?? '—' }}</td>
                        <td>
                            @if($s->ingredients_count > 0)
                                <a href="{{ route('admin.suppliers.show', $s) }}" class="badge bg-primary-transparent" style="text-decoration:none;">
                                    <i class="bi bi-boxes"></i> {{ $s->ingredients_count }}
                                </a>
                            @else
                                <span class="badge bg-secondary">—</span>
                            @endif
                        </td>
                        <td>
                            @if($s->active)
                                <span class="badge bg-success">فعّال</span>
                            @else
                                <span class="badge bg-secondary">متوقف</span>
                            @endif
                        </td>
                        <td>
                            <a href="{{ route('admin.suppliers.show', $s) }}" class="btn btn-sm btn-primary">
                                <i class="bi bi-eye"></i> تفاصيل
                            </a>
                            <a href="{{ route('admin.suppliers.edit', $s) }}" class="btn btn-sm btn-light"><i class="bi bi-pencil"></i></a>
                            <form action="{{ route('admin.suppliers.destroy', $s) }}" method="POST" class="d-inline" onsubmit="return confirm('حذف المورد؟')">
                                @csrf @method('DELETE')
                                <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7">
                        <x-admin.empty-state
                            icon="bi-truck"
                            title="ما في موردين بعد"
                            message="أضف الموردين لتتبع أوامر الشراء والتكاليف.">
                            <x-slot:cta>
                                <a href="{{ route('admin.suppliers.create') }}" class="btn btn-primary">
                                    <i class="bi bi-plus-lg"></i> مورد جديد
                                </a>
                            </x-slot:cta>
                        </x-admin.empty-state>
                    </td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <x-slot:footer>{{ $suppliers->links() }}</x-slot:footer>
</x-admin.data-panel>
@endsection
