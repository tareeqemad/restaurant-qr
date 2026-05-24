@extends('layouts.admin')
@section('title', 'بدل وجبات الموظفين')

@section('content')
<x-admin.breadcrumb
    title="بدل وجبات الموظفين"
    icon="bi-cup-hot-fill"
    :subtitle="'شهر ' . $month->translatedFormat('F Y')">
    <x-slot:actions>
        <a href="{{ route('admin.staff-meals.closures') }}" class="btn btn-outline-secondary">
            <i class="bi bi-archive"></i> الإقفالات السابقة
        </a>
        <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#closeMonthModal"
                title="ينقل كل المستحقات المفتوحة في الشهر المختار إلى الإقفال ويولّد كشف خصم رواتب جاهز للطباعة">
            <i class="bi bi-calendar-check"></i> إقفال الشهر
        </button>
        <a href="{{ route('admin.staff-meals.quick_consume') }}" class="btn btn-warning">
            <i class="bi bi-cup-straw"></i> استهلاك سريع
        </a>
    </x-slot:actions>
</x-admin.breadcrumb>

{{-- Plain-language note explaining how staff meal money flows through
     the books — addresses the "ما بدي يصير خسارة" concern by showing
     the user up-front that gifts are recognized as a deliberate operating
     expense (employee benefit), NOT as a loss. --}}
<div class="alert alert-light border d-flex align-items-start gap-3 mb-3" style="border-right:4px solid var(--bs-info)!important;">
    <i class="bi bi-info-circle-fill text-info fs-4 mt-1"></i>
    <div class="small">
        <strong class="d-block mb-1">كيف تظهر هذه الأرقام في التقارير المحاسبية؟</strong>
        <ul class="mb-0" style="padding-inline-start: 1.2rem;">
            <li><strong>وجبة مدفوعة لاحقاً</strong> (نقد/خصم راتب): الإيراد في
                <code>4030</code> «إيرادات بدل وجبات الموظفين» مقابل تكلفة الطعام في
                <code>5000</code> — الفرق ربح طبيعي.</li>
            <li><strong>وجبة هدية أو إعفاء</strong>: تظهر في <code>5060</code>
                «هدايا ومكافآت موظفين عينية» تحت <strong>المصاريف التشغيلية</strong>
                — مصروف اعتيادي مثل الرواتب، <strong>وليست خسارة</strong>.</li>
            <li><strong>شطب/تنازل</strong>: يُسجَّل في <code>5200</code>
                «ديون معدومة» — هذا هو الوحيد اللي بيظهر كخسارة فعلية.</li>
        </ul>
    </div>
</div>

<form method="GET" class="row g-2 align-items-end mb-3">
    <div class="col-md-3">
        <label class="form-label small text-muted fw-bold">الشهر</label>
        <input type="month" name="month" value="{{ $month->format('Y-m') }}" class="form-control">
    </div>
    <div class="col-md-2">
        <button class="btn btn-primary w-100"><i class="bi bi-search"></i> عرض</button>
    </div>
</form>

<div class="row g-3 mb-3">
    <div class="col-md">
        <div class="card text-center"><div class="card-body">
            <small class="text-muted d-block">موظفون لهم بدل</small>
            <h4 class="mb-0 text-primary">{{ $totals['staff_count'] }}</h4>
        </div></div>
    </div>
    <div class="col-md">
        <div class="card text-center"><div class="card-body">
            <small class="text-muted d-block">إجمالي الحدود</small>
            <h4 class="mb-0">{{ \App\Helpers\Money::format($totals['total_allowance']) }}</h4>
        </div></div>
    </div>
    <div class="col-md">
        <div class="card text-center"><div class="card-body">
            <small class="text-muted d-block">استهلاك الشهر</small>
            <h4 class="mb-0 text-accent">{{ \App\Helpers\Money::format($totals['total_used']) }}</h4>
        </div></div>
    </div>
    {{-- Gifts metric — sum of all "وجبات مجانية" + "إعفاءات" granted this
         month. Separated from `total_used` so management sees giveaways
         as a distinct figure and doesn't confuse them with debt. --}}
    <div class="col-md">
        <div class="card text-center {{ ($totals['total_gifted'] ?? 0) > 0 ? 'border-info' : '' }}"><div class="card-body">
            <small class="text-muted d-block">هدايا وإعفاءات</small>
            <h4 class="mb-0 {{ ($totals['total_gifted'] ?? 0) > 0 ? 'text-info' : 'text-muted' }}">
                <i class="bi bi-gift"></i>
                {{ \App\Helpers\Money::format($totals['total_gifted'] ?? 0) }}
            </h4>
        </div></div>
    </div>
    <div class="col-md">
        <div class="card text-center {{ $totals['over_limit_count'] > 0 ? 'border-danger' : '' }}"><div class="card-body">
            <small class="text-muted d-block">متجاوزون الحد</small>
            <h4 class="mb-0 {{ $totals['over_limit_count'] > 0 ? 'text-danger' : 'text-success' }}">
                {{ $totals['over_limit_count'] }}
            </h4>
        </div></div>
    </div>
</div>

<x-admin.data-panel title="حسابات الموظفين" icon="bi-people-fill" :count="$rows->count()">
    @if($rows->count())
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead class="bg-light">
                    <tr>
                        <th>الموظف</th>
                        <th class="text-end">الحد الشهري</th>
                        <th class="text-end">المُستهلك هذا الشهر</th>
                        <th class="text-end">الاستخدام</th>
                        <th class="text-end">سقف الدين</th>
                        <th class="text-end">إجمالي المستحق</th>
                        <th class="text-center" style="width:170px">إجراءات</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($rows as $r)
                        @php
                            $s = $r['summary'];
                            $over = $s['overflow'] > 0;
                            $pct  = $s['usage_pct'];
                            $ceiling = $s['ceiling'];
                            $headroom = $s['ceiling_headroom'];
                            // Row gets coloured when the employee is past 100% OR over the hard ceiling
                            $hardOver = $headroom !== null && $headroom < 0;
                            $rowClass = $hardOver ? 'table-danger' : ($over ? 'table-warning' : ($s['used'] > 0 ? '' : 'text-muted'));
                            // Progress-bar color matches the threshold thresholds (80/100/120)
                            $barClass = match(true) {
                                $pct === null    => 'bg-secondary',
                                $pct >= 120      => 'bg-danger',
                                $pct >= 100      => 'bg-warning',
                                $pct >= 80       => 'bg-info',
                                default          => 'bg-success',
                            };
                        @endphp
                        <tr class="{{ $rowClass }}">
                            <td>
                                <strong>{{ $r['user']->name }}</strong>
                                <small class="text-muted d-block">{{ $r['user']->role }}</small>
                            </td>
                            <td class="text-end">{{ \App\Helpers\Money::format($s['allowance']) }}</td>
                            <td class="text-end fw-bold">
                                {{ \App\Helpers\Money::format($s['used']) }}
                                @if(($s['gifted'] ?? 0) > 0)
                                    <small class="d-block text-info" title="قيمة الهدايا والإعفاءات هذا الشهر">
                                        <i class="bi bi-gift"></i> هدايا: {{ \App\Helpers\Money::format($s['gifted']) }}
                                    </small>
                                @endif
                            </td>
                            <td class="text-end" style="min-width:140px">
                                @if($pct !== null)
                                    <div class="d-flex align-items-center gap-2 justify-content-end">
                                        <small class="fw-bold {{ $pct >= 100 ? 'text-danger' : 'text-muted' }}">{{ $pct }}%</small>
                                        <div class="progress flex-grow-1" style="height:8px; max-width:90px;">
                                            <div class="progress-bar {{ $barClass }}" role="progressbar"
                                                 style="width: {{ min(100, $pct) }}%"></div>
                                        </div>
                                    </div>
                                    @if($over)
                                        <small class="text-danger d-block">+{{ \App\Helpers\Money::format($s['overflow']) }}</small>
                                    @endif
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </td>
                            <td class="text-end">
                                @if($ceiling !== null)
                                    <strong>{{ \App\Helpers\Money::format($ceiling) }}</strong>
                                    @if($headroom !== null)
                                        <small class="d-block {{ $headroom < 0 ? 'text-danger' : ($headroom < $ceiling * 0.2 ? 'text-warning' : 'text-muted') }}">
                                            متاح: {{ \App\Helpers\Money::format(max(0, $headroom)) }}
                                            @if($headroom < 0)
                                                <i class="bi bi-exclamation-triangle-fill" title="تجاوز السقف"></i>
                                            @endif
                                        </small>
                                    @endif
                                @else
                                    <small class="text-muted">— غير محدد —</small>
                                @endif
                            </td>
                            <td class="text-end fw-bold text-warning">{{ \App\Helpers\Money::format($s['outstanding']) }}</td>
                            <td class="text-center">
                                <a href="{{ route('admin.staff-meals.show', $r['user']) }}" class="btn btn-sm btn-primary">
                                    <i class="bi bi-list-ul"></i> تفاصيل + تسوية
                                </a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @else
        <div class="text-center py-5 text-muted">
            <i class="bi bi-cup-hot fs-1 d-block mb-2"></i>
            لا موظفين لهم بدل وجبات مفعّل. عدّل ملف الموظف وضِف قيمة لـ"بدل الوجبات الشهري".
        </div>
    @endif
</x-admin.data-panel>

{{-- ────────── إقفال الشهر ────────── --}}
<div class="modal fade" id="closeMonthModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" action="{{ route('admin.staff-meals.close_month') }}" class="modal-content">
            @csrf
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-calendar-check"></i> إقفال شهر بدل الوجبات</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-warning small d-flex align-items-start gap-2 mb-3">
                    <i class="bi bi-info-circle-fill mt-1"></i>
                    <div>
                        كل المستحقات المفتوحة في الشهر المختار ستتحول إلى <strong>مُسوّاة</strong> بالطريقة المختارة.
                        النظام يولّد <strong>كشف خصم رواتب</strong> جاهز للطباعة. العملية idempotent — لو أُغلق
                        الشهر سابقاً، رح يفتح الكشف القديم.
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold">الشهر</label>
                    <input type="month" name="month" value="{{ $month->format('Y-m') }}" class="form-control" required>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold">طريقة التسوية</label>
                    <select name="method" class="form-select" required>
                        <option value="payroll_deduction" selected>خصم من رواتب الشهر التالي</option>
                        <option value="cash">تحصيل نقدي (دفعوا فعلياً)</option>
                        <option value="writeoff">إعدام/تنازل إداري</option>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label">ملاحظات (اختيارية)</label>
                    <textarea name="notes" rows="2" class="form-control" placeholder="مثلاً: ملحق راتب يونيو 2026"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                <button type="submit" class="btn btn-success">
                    <i class="bi bi-check-circle"></i> إقفال + توليد الكشف
                </button>
            </div>
        </form>
    </div>
</div>
@endsection
