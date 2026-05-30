@extends('layouts.admin')
@section('title', 'عروض وخصومات الأصناف')

@section('content')
<x-admin.breadcrumb
    title="عروض وخصومات الأصناف"
    icon="bi-tag-fill"
    subtitle="إدارة أسعار العرض الدائمة، الحملات المجدولة، وHappy Hour">
    <x-slot:actions>
        @can('create', App\Models\MenuPromotion::class)
            <a href="{{ route('admin.promotions.create') }}" class="btn btn-primary">
                <i class="bi bi-plus-circle-fill"></i> عرض جديد
            </a>
        @endcan
    </x-slot:actions>
</x-admin.breadcrumb>

<div class="alert alert-light border d-flex align-items-start gap-3 mb-3"
     style="border-right:4px solid var(--bs-info)!important;">
    <i class="bi bi-info-circle-fill text-info fs-4 mt-1"></i>
    <div class="small">
        <strong class="d-block mb-1">كيف تعمل العروض؟</strong>
        <ul class="mb-0" style="padding-inline-start: 1.2rem;">
            <li>السعر الفعلي للزبون = أحدث عرض «نشط» يحقق شروط الوقت (تاريخ + يوم + ساعة).</li>
            <li>لو تطابق أكثر من عرض، الأعلى أولوية يفوز، ثم الأخص (صنف معيّن &gt; فئة كاملة).</li>
            <li>سعر الصنف وقت الطلب يُحفظ بالـ snapshot — انتهاء العرض لاحقاً لا يؤثر على فواتير سابقة.</li>
        </ul>
    </div>
</div>

<form method="GET" class="row g-2 align-items-end mb-3">
    <div class="col-md-4">
        <label class="form-label small text-muted fw-bold">بحث بالاسم</label>
        <input type="search" name="search" value="{{ request('search') }}" class="form-control"
               placeholder="مثلاً: رمضان، عرض الإفطار…">
    </div>
    <div class="col-md-3">
        <label class="form-label small text-muted fw-bold">الحالة</label>
        <select name="status" class="form-select">
            <option value="">— الكل —</option>
            <option value="active"   @selected(request('status') === 'active')>نشطة</option>
            <option value="paused"   @selected(request('status') === 'paused')>متوقفة</option>
            <option value="upcoming" @selected(request('status') === 'upcoming')>قادمة (لم تبدأ)</option>
            <option value="expired"  @selected(request('status') === 'expired')>منتهية</option>
        </select>
    </div>
    <div class="col-md-2">
        <button class="btn btn-primary w-100"><i class="bi bi-search"></i> عرض</button>
    </div>
</form>

<x-admin.data-panel title="العروض" icon="bi-tags" :count="$promotions->total()">
    @if($promotions->isNotEmpty())
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead class="bg-light">
                    <tr>
                        <th>العرض</th>
                        <th>النطاق</th>
                        <th>الخصم</th>
                        <th>الجدولة</th>
                        <th>الفرع</th>
                        <th>أولوية</th>
                        <th>الحالة</th>
                        <th class="text-center" style="width:150px">إجراءات</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($promotions as $p)
                        @php
                            $targetName = $p->target_type === 'menu_item'
                                ? ($items[$p->target_id] ?? '— صنف محذوف —')
                                : ($cats[$p->target_id] ?? '— فئة محذوفة —');
                            $isLive = $p->isLiveAt(now());
                            $isUpcoming = $p->starts_at && $p->starts_at->gt(now());
                            $isExpired = $p->ends_at && $p->ends_at->lt(now());
                        @endphp
                        <tr class="{{ ! $p->active ? 'text-muted' : '' }}">
                            <td>
                                <strong>{{ $p->name }}</strong>
                                @if($p->description)
                                    <small class="d-block text-muted">{{ Str::limit($p->description, 60) }}</small>
                                @endif
                            </td>
                            <td>
                                <span class="badge bg-{{ $p->target_type === 'menu_item' ? 'primary' : 'secondary' }}">
                                    {{ $p->target_type === 'menu_item' ? 'صنف' : 'فئة' }}
                                </span>
                                <strong class="d-block">{{ $targetName }}</strong>
                            </td>
                            <td>
                                <strong class="text-success">{{ $p->valueLabel() }}</strong>
                                <small class="d-block text-muted">{{ $p->typeLabel() }}</small>
                            </td>
                            <td><small>{{ $p->scheduleLabel() }}</small></td>
                            <td><small>{{ $p->branch?->name ?? 'كل الفروع' }}</small></td>
                            <td><span class="badge bg-light text-dark border">{{ $p->priority }}</span></td>
                            <td>
                                @if(! $p->active)
                                    <span class="badge bg-secondary"><i class="bi bi-pause-fill"></i> متوقفة</span>
                                @elseif($isLive)
                                    <span class="badge bg-success"><i class="bi bi-broadcast"></i> نشطة الآن</span>
                                @elseif($isUpcoming)
                                    <span class="badge bg-info"><i class="bi bi-hourglass-top"></i> قادمة</span>
                                @elseif($isExpired)
                                    <span class="badge bg-dark"><i class="bi bi-calendar-x"></i> منتهية</span>
                                @else
                                    <span class="badge bg-warning text-dark"><i class="bi bi-clock"></i> خارج الوقت</span>
                                @endif
                            </td>
                            <td class="text-center">
                                <form action="{{ route('admin.promotions.toggle', $p) }}" method="POST" class="d-inline">
                                    @csrf @method('PATCH')
                                    <button class="btn btn-sm {{ $p->active ? 'btn-outline-warning' : 'btn-outline-success' }}"
                                            title="{{ $p->active ? 'إيقاف مؤقت' : 'تشغيل' }}">
                                        <i class="bi {{ $p->active ? 'bi-pause-fill' : 'bi-play-fill' }}"></i>
                                    </button>
                                </form>
                                <a href="{{ route('admin.promotions.edit', $p) }}" class="btn btn-sm btn-primary"
                                   title="تعديل">
                                    <i class="bi bi-pencil"></i>
                                </a>
                                <form action="{{ route('admin.promotions.destroy', $p) }}" method="POST" class="d-inline"
                                      onsubmit="return confirm('حذف العرض «{{ $p->name }}»؟ هذا الإجراء لا يُرجع.');">
                                    @csrf @method('DELETE')
                                    <button class="btn btn-sm btn-outline-danger" title="حذف">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="mt-3">{{ $promotions->links() }}</div>
    @else
        <div class="text-center py-5 text-muted">
            <i class="bi bi-tag fs-1 d-block mb-2"></i>
            لا توجد عروض بعد. اضغط «عرض جديد» لإنشاء أول خصم.
        </div>
    @endif
</x-admin.data-panel>
@endsection
