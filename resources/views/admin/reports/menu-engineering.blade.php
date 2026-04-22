@extends('layouts.admin')
@section('title', 'هندسة المنيو')

@section('content')
<x-admin.breadcrumb title="هندسة المنيو" icon="bi-bezier2"
    subtitle="تصنيف الأصناف حسب الربحية × الشعبية لاتخاذ قرارات التسعير"
    :crumbs="[['label' = />

<div class="data-panel-filters mb-3" style="background: white; border-radius: 14px; padding: 1rem; border: 1px solid rgba(var(--primary-rgb),.1);">
    <form class="row g-2 align-items-end">
        <div class="col-md-3">
            <label class="form-label small text-muted fw-bold">من تاريخ</label>
            <input type="date" name="from" value="{{ $from }}" class="form-control">
        </div>
        <div class="col-md-3">
            <label class="form-label small text-muted fw-bold">إلى تاريخ</label>
            <input type="date" name="to" value="{{ $to }}" class="form-control">
        </div>
        <div class="col-md-3">
            <button class="btn btn-primary w-100"><i class="bi bi-funnel"></i> تحديث</button>
        </div>
        <div class="col-md-3">
            <div class="btn-group w-100">
                <a href="?from={{ now()->subDays(30)->toDateString() }}&to={{ now()->toDateString() }}" class="btn btn-light btn-sm">30 يوم</a>
                <a href="?from={{ now()->startOfMonth()->toDateString() }}&to={{ now()->toDateString() }}" class="btn btn-light btn-sm">الشهر</a>
                <a href="?from={{ now()->subDays(7)->toDateString() }}&to={{ now()->toDateString() }}"  class="btn btn-light btn-sm">7 أيام</a>
            </div>
        </div>
    </form>
</div>

@if($classified->isEmpty())
    <x-admin.empty-state
        icon="bi-bezier2"
        title="ما في بيانات كافية"
        message="لا يوجد مبيعات في الفترة المحددة لتحليل المنيو. جرّب تمديد الفترة أو تأكد من وجود طلبات معتمدة." />
@else

<x-admin.stat-rail :stats="[
    ['label' => '⭐ Stars',      'value' => $buckets['star'],      'icon' => 'bi-star-fill',          'color' => 'success'],
    ['label' => '🐎 Plowhorses', 'value' => $buckets['plowhorse'], 'icon' => 'bi-speedometer2',       'color' => 'accent'],
    ['label' => '🧩 Puzzles',    'value' => $buckets['puzzle'],    'icon' => 'bi-puzzle-fill',        'color' => 'primary'],
    ['label' => '🐕 Dogs',        'value' => $buckets['dog'],       'icon' => 'bi-x-octagon-fill',    'color' => 'danger'],
]" />

{{-- Matrix visualization --}}
<div class="row g-3 mb-3">
    <div class="col-xl-7">
        <x-admin.data-panel title="مصفوفة التصنيف" icon="bi-grid-3x3-gap-fill">
            <div class="p-3">
                @php
                    $classes = [
                        'star' => [
                            'label' => '⭐ نجوم',
                            'desc'  => 'ربحية عالية + مبيعات عالية — أبقها بارزة في المنيو',
                            'color' => '#1f4733',
                            'bg'    => 'rgba(31,71,51,.08)',
                        ],
                        'puzzle' => [
                            'label' => '🧩 ألغاز',
                            'desc'  => 'ربحية عالية + مبيعات منخفضة — روّج لها، حسّن الوصف، ضعها في أماكن بارزة',
                            'color' => '#3d6b47',
                            'bg'    => 'rgba(61,107,71,.08)',
                        ],
                        'plowhorse' => [
                            'label' => '🐎 محراث',
                            'desc'  => 'مبيعات عالية + ربحية منخفضة — ارفع السعر تدريجياً أو قلّل التكلفة',
                            'color' => '#b8872a',
                            'bg'    => 'rgba(184,135,42,.08)',
                        ],
                        'dog' => [
                            'label' => '🐕 كلاب',
                            'desc'  => 'مبيعات وربحية منخفضة — فكّر بحذفها أو إعادة تصميمها',
                            'color' => '#b91c1c',
                            'bg'    => 'rgba(185,28,28,.08)',
                        ],
                    ];
                @endphp

                <div class="me-matrix">
                    {{-- Labels for axes --}}
                    <div class="me-axis-y">الشعبية ↑</div>
                    <div class="me-axis-x">الربحية →</div>

                    <div class="me-quadrant" style="background: {{ $classes['star']['bg'] }}; border-color: {{ $classes['star']['color'] }};">
                        <div class="me-quadrant-head" style="color: {{ $classes['star']['color'] }};">
                            {{ $classes['star']['label'] }}
                            <span class="badge" style="background: {{ $classes['star']['color'] }}; color: white;">{{ $buckets['star'] }}</span>
                        </div>
                        <div class="me-quadrant-desc">{{ $classes['star']['desc'] }}</div>
                        <div class="me-quadrant-items">
                            @foreach($classified->where('class', 'star')->take(4) as $it)
                                <div class="me-item-chip" style="background: {{ $classes['star']['color'] }}; color: white;">
                                    {{ $it->name }}
                                </div>
                            @endforeach
                            @if($buckets['star'] > 4)
                                <small class="text-muted">+ {{ $buckets['star'] - 4 }} أخرى</small>
                            @endif
                        </div>
                    </div>

                    <div class="me-quadrant" style="background: {{ $classes['plowhorse']['bg'] }}; border-color: {{ $classes['plowhorse']['color'] }};">
                        <div class="me-quadrant-head" style="color: {{ $classes['plowhorse']['color'] }};">
                            {{ $classes['plowhorse']['label'] }}
                            <span class="badge" style="background: {{ $classes['plowhorse']['color'] }}; color: white;">{{ $buckets['plowhorse'] }}</span>
                        </div>
                        <div class="me-quadrant-desc">{{ $classes['plowhorse']['desc'] }}</div>
                        <div class="me-quadrant-items">
                            @foreach($classified->where('class', 'plowhorse')->take(4) as $it)
                                <div class="me-item-chip" style="background: {{ $classes['plowhorse']['color'] }}; color: white;">
                                    {{ $it->name }}
                                </div>
                            @endforeach
                            @if($buckets['plowhorse'] > 4)
                                <small class="text-muted">+ {{ $buckets['plowhorse'] - 4 }} أخرى</small>
                            @endif
                        </div>
                    </div>

                    <div class="me-quadrant" style="background: {{ $classes['puzzle']['bg'] }}; border-color: {{ $classes['puzzle']['color'] }};">
                        <div class="me-quadrant-head" style="color: {{ $classes['puzzle']['color'] }};">
                            {{ $classes['puzzle']['label'] }}
                            <span class="badge" style="background: {{ $classes['puzzle']['color'] }}; color: white;">{{ $buckets['puzzle'] }}</span>
                        </div>
                        <div class="me-quadrant-desc">{{ $classes['puzzle']['desc'] }}</div>
                        <div class="me-quadrant-items">
                            @foreach($classified->where('class', 'puzzle')->take(4) as $it)
                                <div class="me-item-chip" style="background: {{ $classes['puzzle']['color'] }}; color: white;">
                                    {{ $it->name }}
                                </div>
                            @endforeach
                            @if($buckets['puzzle'] > 4)
                                <small class="text-muted">+ {{ $buckets['puzzle'] - 4 }} أخرى</small>
                            @endif
                        </div>
                    </div>

                    <div class="me-quadrant" style="background: {{ $classes['dog']['bg'] }}; border-color: {{ $classes['dog']['color'] }};">
                        <div class="me-quadrant-head" style="color: {{ $classes['dog']['color'] }};">
                            {{ $classes['dog']['label'] }}
                            <span class="badge" style="background: {{ $classes['dog']['color'] }}; color: white;">{{ $buckets['dog'] }}</span>
                        </div>
                        <div class="me-quadrant-desc">{{ $classes['dog']['desc'] }}</div>
                        <div class="me-quadrant-items">
                            @foreach($classified->where('class', 'dog')->take(4) as $it)
                                <div class="me-item-chip" style="background: {{ $classes['dog']['color'] }}; color: white;">
                                    {{ $it->name }}
                                </div>
                            @endforeach
                            @if($buckets['dog'] > 4)
                                <small class="text-muted">+ {{ $buckets['dog'] - 4 }} أخرى</small>
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        </x-admin.data-panel>
    </div>

    {{-- Thresholds + recommendations --}}
    <div class="col-xl-5">
        <x-admin.data-panel title="العتبات المستخدمة" icon="bi-rulers">
            <div class="p-3">
                <div class="small text-muted mb-3">
                    <i class="bi bi-info-circle"></i>
                    نستخدم الوسيط (median) للتقسيم، وهو أكثر متانة من المتوسط الحسابي.
                </div>
                <div class="d-flex justify-content-between py-2 border-bottom">
                    <span class="fw-bold">وسيط الكمية المباعة</span>
                    <span class="fw-bold" style="color:var(--primary);">{{ number_format($thresholds['median_qty'], 0) }} قطعة</span>
                </div>
                <div class="d-flex justify-content-between py-2">
                    <span class="fw-bold">وسيط هامش الربح</span>
                    <span class="fw-bold" style="color:var(--primary);">{{ number_format($thresholds['median_margin'], 1) }}%</span>
                </div>
                <div class="mt-3 p-3 rounded" style="background: rgba(var(--accent-rgb),.05); border: 1px dashed rgba(var(--accent-rgb),.3);">
                    <strong style="color: var(--accent-dark);">💡 تفسير</strong>
                    <ul class="mb-0 mt-2 small" style="padding-inline-start: 1.25rem;">
                        <li>الأصناف فوق الوسيطين = نجوم (⭐)</li>
                        <li>الأصناف تحت الوسيطين = كلاب (🐕)</li>
                        <li>بين الاثنين = أَحد طرفي المصفوفة</li>
                    </ul>
                </div>
            </div>
        </x-admin.data-panel>
    </div>
</div>

{{-- Full classified table --}}
<x-admin.data-panel title="كل الأصناف مصنّفة" :count="$classified->count()" icon="bi-table">
    <div class="table-responsive">
        <table class="table align-middle">
            <thead class="bg-light">
                <tr>
                    <th>التصنيف</th>
                    <th>الصنف</th>
                    <th>كمية مباعة</th>
                    <th>الإيرادات</th>
                    <th>التكلفة</th>
                    <th>الربح</th>
                    <th>هامش %</th>
                </tr>
            </thead>
            <tbody>
                @foreach($classified as $it)
                    @php
                        $cls = $classes[$it->class];
                    @endphp
                    <tr>
                        <td>
                            <span class="badge" style="background: {{ $cls['color'] }}; color: white;">
                                {{ $cls['label'] }}
                            </span>
                        </td>
                        <td class="fw-bold">{{ $it->name }}</td>
                        <td>{{ number_format((float) $it->qty_sold, 0) }}</td>
                        <td class="fw-bold" style="color: var(--primary);">{{ \App\Helpers\Money::format($it->revenue) }}</td>
                        <td class="text-muted">{{ \App\Helpers\Money::format($it->cogs) }}</td>
                        <td class="fw-bold" style="color: {{ $it->profit > 0 ? 'var(--primary)' : '#b91c1c' }};">
                            {{ \App\Helpers\Money::format($it->profit) }}
                        </td>
                        <td>
                            <span class="fw-bold" style="color: {{ $it->margin_pct >= 50 ? 'var(--primary)' : ($it->margin_pct >= 30 ? '#8a6920' : '#b91c1c') }};">
                                {{ number_format($it->margin_pct, 1) }}%
                            </span>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</x-admin.data-panel>
@endif

@push('styles')
<style>
.me-matrix {
    display: grid;
    grid-template-columns: 1fr 1fr;
    grid-template-rows: 1fr 1fr;
    gap: .75rem;
    position: relative;
    padding: 0 1.5rem 1.5rem 1.5rem;
    min-height: 480px;
}
.me-axis-y {
    position: absolute;
    inset-inline-start: 0;
    top: 50%;
    transform: translateY(-50%) rotate(-90deg);
    transform-origin: left;
    font-size: .75rem;
    font-weight: 900;
    color: var(--primary);
    letter-spacing: .06em;
}
.me-axis-x {
    position: absolute;
    bottom: 0;
    inset-inline-end: 0;
    font-size: .75rem;
    font-weight: 900;
    color: var(--primary);
    letter-spacing: .06em;
}
.me-quadrant {
    border: 2px solid;
    border-radius: 16px;
    padding: 1rem;
    display: flex;
    flex-direction: column;
    gap: .5rem;
    transition: transform .15s ease;
}
.me-quadrant:hover { transform: scale(1.01); }
.me-quadrant-head {
    font-size: 1.05rem;
    font-weight: 900;
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.me-quadrant-desc {
    font-size: .8rem;
    color: #57534e;
    line-height: 1.4;
}
.me-quadrant-items {
    display: flex;
    flex-wrap: wrap;
    gap: 4px;
    margin-top: auto;
}
.me-item-chip {
    font-size: .72rem;
    font-weight: 700;
    padding: 3px 10px;
    border-radius: 99px;
}
@media print {
    .app-sidebar, .app-header, form, .breadcrumb-list, .btn { display: none !important; }
    .app-content { margin: 0 !important; }
}
</style>
@endpush
@endsection
