@extends('layouts.admin')
@section('title', 'دفعات المكونات')

@section('content')
<x-admin.breadcrumb title="دفعات المكونات" icon="bi-box2-fill"
    subtitle="تتبع الدفعات وتواريخ الصلاحية (FIFO)" />

{{-- Plain-Arabic explainer at the top of the page so a manager
     opening it for the first time understands what a "batch" is and
     why it's separate from supplier invoices / payments. --}}
<div class="card custom-card mb-3" style="border-color: rgba(15, 71, 49, .12);">
    <div class="card-body p-3 small" style="line-height: 1.85;">
        <div class="d-flex align-items-start gap-2 mb-2">
            <i class="bi bi-info-circle-fill text-primary fs-5"></i>
            <strong class="fs-13">شو هي «الدفعات» وليش بتلزم؟</strong>
        </div>
        <div class="text-muted">
            كل مرة بتستلم بضاعة من مورد، النظام بيخزّن السطر كـ <strong>دفعة</strong> منفصلة فيها:
            تاريخ استلامها، تاريخ صلاحيتها، الكمية الأصلية، الكمية المتبقية، وتكلفة الوحدة في تلك الدفعة بالذات.
            <strong>تلزم لثلاث أمور أساسية:</strong>
            <ul class="mb-0 ps-3 mt-1">
                <li><strong>FIFO</strong> — الاستهلاك يبدأ من الدفعة الأقدم تلقائياً (الكمية المتبقية تنقص أولاً للأقدم).</li>
                <li><strong>تتبّع الصلاحية</strong> — تشوف أي دفعة بتنتهي خلال أسبوع وأي واحدة منتهية فعلياً قبل ما تخسرها.</li>
                <li><strong>دقة التكلفة والاسترجاع</strong> — كل دفعة عندها سعرها الفعلي ورقمها، فلو ظهرت مشكلة في شحنة معينة من المورد بتعرف بالضبط أي وصفات استخدمتها.</li>
            </ul>
            <div class="mt-2">
                <strong>تتحدّث متى؟</strong>
                عند <strong>استلام أمر شراء</strong> (إنشاء دفعة جديدة) و<strong>استهلاك المخزون</strong>
                من البيع/الهدر/التحويل (تنقيص الكمية المتبقية).
                <span class="text-warning">
                    <i class="bi bi-exclamation-circle"></i>
                    <strong>دفع فاتورة المورد لا يؤثر على الدفعات</strong> — الدفعات تتعقّب البضاعة الفعلية،
                    والفواتير تتعقّب المستحقات المالية. كلاهما مسارات منفصلة عن قصد.
                </span>
            </div>
        </div>
    </div>
</div>

<x-admin.stat-rail :stats="[
    ['label' => 'دفعات نشطة',          'value' => $stats['active'],                                'icon' => 'bi-box2-fill',                'color' => 'primary'],
    ['label' => 'تنتهي خلال 7 أيام',    'value' => $stats['expiring'],                              'icon' => 'bi-alarm-fill',                'color' => 'accent'],
    ['label' => 'منتهية الصلاحية',      'value' => $stats['expired'],                               'icon' => 'bi-x-octagon-fill',           'color' => 'danger'],
    ['label' => 'قيمة المخزون',          'value' => \App\Helpers\Money::format($stats['total_value']),'icon' => 'bi-cash-coin',                'color' => 'success'],
]" />

@if($stats['expired'] > 0)
<div class="alert alert-danger">
    <i class="bi bi-exclamation-triangle-fill"></i>
    يوجد <strong>{{ $stats['expired'] }}</strong> دفعة منتهية الصلاحية ولها مخزون متبقٍ!
    يرجى معالجتها كـ "هدر" لإزالتها من المخزون.
    <a href="?expired=1" class="alert-link">عرضها الآن</a>
</div>
@endif

<x-admin.data-panel title="الدفعات" :count="$batches->total()" icon="bi-box2">
    <x-slot:actions>
        @if(request()->hasAny(['ingredient_id', 'storage_location_id', 'expired', 'expiring', 'active']))
            <a href="{{ route('admin.batches.index') }}" class="btn btn-light"><i class="bi bi-x-circle"></i> مسح</a>
        @endif
    </x-slot:actions>

    <x-slot:filters>
        <form class="row g-2">
            <div class="col-md-3">
                <select name="ingredient_id" class="form-select" data-relax-choice data-choice-search-placeholder="ابحث عن مكوّن...">
                    <option value="">كل المكونات</option>
                    @foreach($ingredients as $ing)
                        <option value="{{ $ing->id }}" @selected(request('ingredient_id')==$ing->id)>{{ $ing->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-3">
                <select name="storage_location_id" class="form-select" data-relax-choice data-choice-search-placeholder="ابحث عن موقع...">
                    <option value="">كل مواقع التخزين</option>
                    @foreach($storageLocations as $location)
                        <option value="{{ $location->id }}" @selected(request('storage_location_id')==$location->id)>
                            {{ $location->name }}{{ $location->code ? ' - '.$location->code : '' }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-4 d-flex gap-3 align-items-center">
                <label><input type="checkbox" name="active"  value="1" @checked(request('active'))>  نشطة فقط</label>
                <label><input type="checkbox" name="expiring" value="1" @checked(request('expiring'))> تنتهي قريباً</label>
                <label><input type="checkbox" name="expired"  value="1" @checked(request('expired'))>  منتهية</label>
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
                    <th>موقع التخزين</th>
                    <th>المكوّن</th>
                    <th>رقم الدفعة</th>
                    <th>تاريخ الاستلام</th>
                    <th>الصلاحية</th>
                    <th>الكمية الأولية</th>
                    <th>المتبقي</th>
                    <th>التكلفة/وحدة</th>
                    <th>القيمة الحالية</th>
                </tr>
            </thead>
            <tbody>
                @forelse($batches as $b)
                    @php
                        $days = $b->daysUntilExpiry();
                        $rowClass = $b->isExpired() ? 'table-danger' : ($b->isNearExpiry() ? 'table-warning' : '');
                    @endphp
                    <tr class="{{ $rowClass }} {{ $b->isDepleted() ? 'text-muted' : '' }}">
                        <td>{{ $b->storageLocation?->name ?? '—' }}</td>
                        <td class="fw-bold">{{ $b->ingredient?->name ?? '—' }}</td>
                        <td>{{ $b->batch_number ?: '—' }}</td>
                        <td>{{ $b->received_date->format('Y-m-d') }}</td>
                        <td>
                            @if($b->expiry_date)
                                {{ $b->expiry_date->format('Y-m-d') }}
                                @if($b->isExpired())
                                    <br><small class="text-danger fw-bold">منتهية!</small>
                                @elseif($b->isNearExpiry(7))
                                    <br><small class="fw-bold" style="color:#8a6920;">خلال {{ $days }} يوم</small>
                                @endif
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </td>
                        @php $bBase = $b->ingredient?->baseUnit?->code; @endphp
                        <td title="{{ \App\Helpers\Qty::format($b->initial_qty) }} {{ $bBase }}">
                            {{ \App\Helpers\QuantityFormatter::smart((float) $b->initial_qty, $bBase) }}
                        </td>
                        <td class="fw-bold">
                            @if($b->isDepleted())
                                <span class="text-muted"><i class="bi bi-check2"></i> نفذت</span>
                            @else
                                <span title="{{ \App\Helpers\Qty::format($b->remaining_qty) }} {{ $bBase }}">
                                    {{ \App\Helpers\QuantityFormatter::smart((float) $b->remaining_qty, $bBase) }}
                                </span>
                            @endif
                        </td>
                        <td title="تكلفة الوحدة الأساسية ({{ $bBase }})">
                            {{ \App\Helpers\Qty::format($b->unit_cost) }}
                        </td>
                        <td class="fw-bold" style="color:var(--primary);">
                            {{ \App\Helpers\Money::format((float) $b->remaining_qty * (float) $b->unit_cost) }}
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="9">
                        <x-admin.empty-state
                            icon="bi-box2"
                            title="ما في دفعات بعد"
                            message="تُنشأ الدفعات تلقائياً عند استلام أوامر الشراء، أو يمكنك إضافتها يدوياً." />
                    </td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <x-slot:footer>{{ $batches->links() }}</x-slot:footer>
</x-admin.data-panel>

{{-- Manual batch modal --}}
<div class="modal fade" id="addBatch" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="{{ route('admin.batches.store') }}">
                @csrf
                <div class="modal-header">
                    <h5>إضافة دفعة يدوية</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-info small">
                        تُستخدم عادة لتسجيل مخزون موجود سلفاً لم يدخل عبر PO. ستتم زيادة المخزون تلقائياً.
                    </div>
                    <div class="mb-2">
                        <label class="form-label">المكوّن <span class="text-danger">*</span></label>
                        <select name="ingredient_id" class="form-select" required data-relax-choice data-choice-search-placeholder="ابحث عن مكوّن...">
                            <option value="">— اختر —</option>
                            @foreach($ingredients as $ing)
                                <option value="{{ $ing->id }}">{{ $ing->name }} ({{ $ing->baseUnit?->code }})</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="mb-2">
                        <label class="form-label">موقع التخزين</label>
                        <select name="storage_location_id" class="form-select" data-relax-choice data-choice-search-placeholder="ابحث عن موقع...">
                            @foreach($storageLocations as $location)
                                <option value="{{ $location->id }}" @selected($location->is_default)>
                                    {{ $location->name }}{{ $location->code ? ' - '.$location->code : '' }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="row g-2">
                        <div class="col-6">
                            <label class="form-label">الكمية <span class="text-danger">*</span></label>
                            <input type="number" step="0.0001" min="0.0001" name="qty" class="form-control" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label">التكلفة / وحدة</label>
                            <input type="number" step="0.0001" min="0" name="unit_cost" class="form-control">
                        </div>
                    </div>
                    <div class="row g-2 mt-1">
                        <div class="col-6">
                            <label class="form-label">رقم الدفعة</label>
                            <input type="text" name="batch_number" class="form-control" placeholder="(اختياري)">
                        </div>
                        <div class="col-6">
                            <label class="form-label">تاريخ الصلاحية</label>
                            <input type="date" name="expiry_date" class="form-control">
                        </div>
                    </div>
                    <div class="mt-2">
                        <label class="form-label">ملاحظات</label>
                        <textarea name="notes" class="form-control" rows="2"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">تراجع</button>
                    <button class="btn btn-primary"><i class="bi bi-plus-lg"></i> إنشاء الدفعة</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection
