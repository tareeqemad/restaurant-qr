@extends('layouts.admin')
@section('title', 'الأصناف')

@section('content')
<x-admin.breadcrumb title="أصناف القائمة" icon="bi-egg-fried"
    subtitle="كل أصناف المنيو مع الأسعار والتوفر" />

<x-admin.stat-rail :stats="[
    ['label' => 'إجمالي الأصناف', 'value' => $stats['total'],       'icon' => 'bi-collection',         'color' => 'primary'],
    ['label' => 'متوفرة',          'value' => $stats['available'],   'icon' => 'bi-check-circle-fill', 'color' => 'success'],
    ['label' => 'مميّزة',          'value' => $stats['featured'],    'icon' => 'bi-star-fill',         'color' => 'accent'],
    ['label' => 'غير متوفرة',      'value' => $stats['unavailable'], 'icon' => 'bi-x-circle-fill',     'color' => 'danger'],
]" />

<x-admin.data-panel title="قائمة الأصناف" :count="$items->total()" icon="bi-egg-fried">
    <x-slot:actions>
        <a href="{{ route('admin.menu-items.create') }}" class="btn btn-primary">
            <i class="bi bi-plus-lg"></i> صنف جديد
        </a>
        @if(request()->hasAny(['search', 'category_id', 'only_unavailable']))
            <a href="{{ route('admin.menu-items.index') }}" class="btn btn-light">
                <i class="bi bi-x-circle"></i> مسح الفلاتر
            </a>
        @endif
    </x-slot:actions>

    <x-slot:filters>
        <form class="row g-2">
            <div class="col-md-4">
                <input name="search" value="{{ request('search') }}" class="form-control" placeholder="🔍 ابحث بالاسم أو SKU">
            </div>
            <div class="col-md-3">
                <select name="category_id" class="form-select" data-relax-choice data-choice-search-placeholder="ابحث عن قسم...">
                    <option value="">كل الأقسام</option>
                    @foreach($categories as $c)
                        <option value="{{ $c->id }}" @selected(request('category_id')==$c->id)>{{ $c->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-3">
                <label class="d-flex align-items-center gap-2 h-100">
                    <input type="checkbox" name="only_unavailable" value="1" @checked(request('only_unavailable'))>
                    غير المتوفرة فقط
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
                    <th>صورة</th>
                    <th>الصنف</th>
                    <th>القسم</th>
                    <th>السعر</th>
                    <th>التكلفة</th>
                    <th>الربح</th>
                    <th>التحضير</th>
                    <th>التوفر</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse($items as $i)
                    @php
                        $price  = (float) $i->price;
                        $cost   = (float) $i->cost;
                        $profit = $price - $cost;
                        $margin = $price > 0 ? ($profit / $price) * 100 : 0;
                        // Margin colour bands: green (>60%), amber (30-60%), red (<30%)
                        $marginColor = $margin >= 60 ? 'var(--primary)' : ($margin >= 30 ? '#b8872a' : '#b91c1c');

                        // Live stock health — lets the manager see at a
                        // glance which items the menu will hide from
                        // customers right now, even when the manual
                        // "متوفر" toggle is still on. Skip items
                        // without recipes (wifi cards etc.).
                        $stockShort = null;
                        if ($i->recipeItems->count() > 0) {
                            $stockShort = $i->stockShortages(1.0);
                        }
                    @endphp
                    <tr>
                        <td><img src="{{ $i->imageUrl() }}" width="48" height="48" class="rounded" style="object-fit:cover"></td>
                        <td>
                            <div class="fw-bold">{{ $i->name }}</div>
                            <small class="text-muted">{{ $i->name_en }} · {{ $i->sku }}</small>
                        </td>
                        <td>{{ $i->category?->name }}</td>
                        <td class="fw-bold">{{ \App\Helpers\Money::format($price) }}</td>
                        <td>
                            @if($cost > 0)
                                <span class="text-muted">{{ \App\Helpers\Money::format($cost) }}</span>
                            @else
                                <span class="badge bg-secondary" title="لم تُحدد وصفة أو تكلفة">—</span>
                            @endif
                        </td>
                        <td>
                            @if($cost > 0 && $price > 0)
                                <div class="fw-bold" style="color: {{ $marginColor }};">
                                    {{ \App\Helpers\Money::format($profit) }}
                                </div>
                                <small style="color: {{ $marginColor }};">{{ number_format($margin, 1) }}%</small>
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </td>
                        <td>{{ $i->prep_time_minutes }} د</td>
                        <td>
                            <form action="{{ route('admin.menu-items.toggle-availability', $i) }}" method="POST" class="d-inline">
                                @csrf @method('PATCH')
                                @if($i->is_available)
                                    <button class="btn btn-sm btn-success"><i class="bi bi-check-circle"></i> متوفر</button>
                                @else
                                    <button class="btn btn-sm btn-danger"><i class="bi bi-x-circle"></i> غير متوفر</button>
                                @endif
                            </form>
                            @if($i->is_available && is_array($stockShort) && !empty($stockShort))
                                {{-- Manual toggle says "on" but the
                                     kitchen can't actually make it —
                                     surface the shortage so the manager
                                     knows the menu is auto-hiding it. --}}
                                <div class="mt-1" title="{{ collect($stockShort)->map(fn($s)=>$s['ingredient'].' (متاح '.rtrim(rtrim(number_format($s['available'],2),'0'),'.').')')->join('، ') }}">
                                    <span class="badge bg-warning text-dark">
                                        <i class="bi bi-box-seam"></i> نفد المخزون
                                    </span>
                                </div>
                            @endif
                        </td>
                        <td>
                            <a href="{{ route('admin.menu-items.edit', $i) }}" class="btn btn-sm btn-light"><i class="bi bi-pencil"></i></a>
                            <form action="{{ route('admin.menu-items.destroy', $i) }}" method="POST" class="d-inline" onsubmit="return confirm('حذف الصنف؟')">
                                @csrf @method('DELETE')
                                <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="9">
                        <x-admin.empty-state
                            icon="bi-egg-fried"
                            title="ما في أصناف بعد"
                            message="أضف أول صنف لقائمتك — سعر، وصف، صورة، ومكونات.">
                            <x-slot:cta>
                                <a href="{{ route('admin.menu-items.create') }}" class="btn btn-primary">
                                    <i class="bi bi-plus-lg"></i> أضف صنفاً
                                </a>
                            </x-slot:cta>
                        </x-admin.empty-state>
                    </td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <x-slot:footer>{{ $items->links() }}</x-slot:footer>
</x-admin.data-panel>
@endsection
