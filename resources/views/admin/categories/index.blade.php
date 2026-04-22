@extends('layouts.admin')
@section('title', 'الأقسام')

@section('content')
<x-admin.breadcrumb title="أقسام القائمة" icon="bi-tag-fill"
    subtitle="تنظيم قائمة الطعام بأقسام — كل قسم يرتبط بمحطة تحضير" />

<x-admin.stat-rail :stats="[
    ['label' => 'إجمالي الأقسام', 'value' => $stats['total'],      'icon' => 'bi-collection',        'color' => 'primary'],
    ['label' => 'نشطة',            'value' => $stats['active'],     'icon' => 'bi-check-circle-fill','color' => 'success'],
    ['label' => 'فيها أصناف',      'value' => $stats['with_items'], 'icon' => 'bi-egg-fried',        'color' => 'accent'],
    ['label' => 'فارغة',           'value' => $stats['empty'],      'icon' => 'bi-inbox',            'color' => 'muted'],
]" />

<x-admin.data-panel title="قائمة الأقسام" :count="$categories->total()" icon="bi-tag-fill">
    <x-slot:actions>
        <a href="{{ route('admin.categories.create') }}" class="btn btn-primary">
            <i class="bi bi-plus-lg"></i> قسم جديد
        </a>
    </x-slot:actions>
    <div class="table-responsive">
        <table class="table align-middle">
            <thead class="bg-light">
                <tr>
                    <th>الأيقونة</th>
                    <th>الاسم</th>
                    <th>English</th>
                    <th>المحطة</th>
                    <th>الأصناف</th>
                    <th>الترتيب</th>
                    <th>الحالة</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse($categories as $c)
                    <tr>
                        <td>
                            @if($c->image)
                                <img src="{{ $c->imageUrl() }}" alt="{{ $c->name }}"
                                    loading="lazy"
                                    style="width:56px; height:56px; object-fit:cover; border-radius:10px; border:2px solid {{ $c->color }};">
                            @else
                                <span style="background:{{ $c->color }}; color:white; width:56px; height:56px; display:inline-flex; align-items:center; justify-content:center; border-radius:10px; font-size:1.3rem;">
                                    <i class="bi {{ $c->icon ?: 'bi-tag' }}"></i>
                                </span>
                            @endif
                        </td>
                        <td class="fw-bold">{{ $c->name }}</td>
                        <td class="text-muted">{{ $c->name_en }}</td>
                        <td>{{ $c->station?->name ?? '—' }}</td>
                        <td><span class="badge bg-secondary">{{ $c->menu_items_count ?? 0 }}</span></td>
                        <td>{{ $c->display_order }}</td>
                        <td>
                            @if($c->active)
                                <span class="badge bg-success">نشط</span>
                            @else
                                <span class="badge bg-secondary">متوقف</span>
                            @endif
                        </td>
                        <td>
                            <a href="{{ route('admin.categories.edit', $c) }}" class="btn btn-sm btn-light"><i class="bi bi-pencil"></i></a>
                            <form action="{{ route('admin.categories.destroy', $c) }}" method="POST" class="d-inline" onsubmit="return confirm('حذف القسم؟')">
                                @csrf @method('DELETE')
                                <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8">
                        <x-admin.empty-state
                            icon="bi-tag-fill"
                            title="ما في أقسام بعد"
                            message="أضف أول قسم لتنظيم قائمة الطعام.">
                            <x-slot:cta>
                                <a href="{{ route('admin.categories.create') }}" class="btn btn-primary">
                                    <i class="bi bi-plus-lg"></i> أضف قسم جديد
                                </a>
                            </x-slot:cta>
                        </x-admin.empty-state>
                    </td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <x-slot:footer>{{ $categories->links() }}</x-slot:footer>
</x-admin.data-panel>
@endsection
