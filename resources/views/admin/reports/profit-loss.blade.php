@extends('layouts.admin')
@section('title', 'تقرير الأرباح والخسائر')

@php
    use App\Helpers\Money;

    $period = $r['period'];
    $sales  = $r['sales'];
    $costs  = $r['costs'];
    $profit = $r['profit'];
    $branchId = $period['branch_id'];
    $perBranchCost = $period['per_branch_cost'];

    $exportQs = http_build_query(array_filter([
        'from' => $period['from'],
        'to'   => $period['to'],
        'per_branch_cost' => $perBranchCost ? 1 : null,
    ]));
@endphp

@section('content')
<x-admin.breadcrumb
    title="تقرير الأرباح والخسائر"
    icon="bi-graph-up-arrow"
    subtitle="الصورة المالية الكاملة — كل الأرقام التي يحتاجها المدير في شاشة واحدة"
    :crumbs="[['label' => 'التقارير', 'url' => route('admin.reports.index')]]" />

{{-- ═══════════════ FILTER BAR ═══════════════ --}}
<div class="pl-filter mb-3">
    <form class="row g-2 align-items-end">
        <div class="col-md-2">
            <label class="form-label small fw-bold mb-1">من تاريخ</label>
            <input type="date" name="from" value="{{ $period['from'] }}" class="form-control form-control-sm">
        </div>
        <div class="col-md-2">
            <label class="form-label small fw-bold mb-1">إلى تاريخ</label>
            <input type="date" name="to" value="{{ $period['to'] }}" class="form-control form-control-sm">
        </div>
        <div class="col-md-5">
            <label class="form-label small fw-bold mb-1">اختصارات سريعة</label>
            <div class="btn-group w-100">
                <a href="?from={{ now()->toDateString() }}&to={{ now()->toDateString() }}{{ $perBranchCost ? '&per_branch_cost=1' : '' }}" class="btn btn-outline-secondary btn-sm">اليوم</a>
                <a href="?from={{ now()->subDays(6)->toDateString() }}&to={{ now()->toDateString() }}{{ $perBranchCost ? '&per_branch_cost=1' : '' }}" class="btn btn-outline-secondary btn-sm">٧ أيام</a>
                <a href="?from={{ now()->startOfMonth()->toDateString() }}&to={{ now()->toDateString() }}{{ $perBranchCost ? '&per_branch_cost=1' : '' }}" class="btn btn-outline-secondary btn-sm">الشهر</a>
                <a href="?from={{ now()->subMonth()->startOfMonth()->toDateString() }}&to={{ now()->subMonth()->endOfMonth()->toDateString() }}{{ $perBranchCost ? '&per_branch_cost=1' : '' }}" class="btn btn-outline-secondary btn-sm">الشهر السابق</a>
                <a href="?from={{ now()->startOfYear()->toDateString() }}&to={{ now()->toDateString() }}{{ $perBranchCost ? '&per_branch_cost=1' : '' }}" class="btn btn-outline-secondary btn-sm">السنة</a>
            </div>
        </div>
        <div class="col-md-3 d-grid">
            <button class="btn btn-primary btn-sm"><i class="bi bi-search"></i> استعلام</button>
        </div>

        @if($branchId)
            <div class="col-12">
                <label class="pl-cost-toggle d-flex align-items-start gap-2 p-2 rounded mt-1"
                       style="background: rgba(184,135,42,.08); border: 1px solid rgba(184,135,42,.25); cursor: pointer;">
                    <input type="checkbox" name="per_branch_cost" value="1" class="form-check-input m-0 mt-1"
                           @checked($perBranchCost) onchange="this.form.submit()">
                    <span class="d-block flex-grow-1">
                        <strong style="color:#b97818;">
                            <i class="bi bi-building"></i> احسب التكلفة بأسعار شراء هذا الفرع
                        </strong>

                        {{-- Plain-language explainer: the average admin doesn't read accounting
                             jargon. Cast the difference as "what you pay" vs "what the whole
                             restaurant averages" — that's the actual decision. --}}
                        <small class="text-muted d-block mt-1" style="line-height: 1.7;">
                            <span class="d-block mb-1">
                                <i class="bi bi-info-circle text-warning"></i>
                                <strong>إيش الفرق؟</strong>
                                لما الفروع بتشتري من موردين مختلفين، نفس المكوّن
                                (مثلاً: اللحم) ممكن يكون سعره مختلف من فرع لفرع.
                            </span>

                            <span class="d-block mt-2">
                                <span class="badge bg-secondary">مُغلق (الافتراضي)</span>
                                نحسب تكلفة الطبق بـ<strong>متوسط موحَّد</strong> لكل
                                المطعم. كل الفروع تشترك بنفس COGS.
                            </span>

                            <span class="d-block mt-2">
                                <span class="badge" style="background:#b97818; color:#fff;">شغّال</span>
                                نحسب تكلفة الطبق بـ<strong>السعر الفعلي اللي دفعه هذا الفرع</strong>
                                (متوسطه على فواتيره). فرع بيشتري أرخص = ربحه يطلع أعلى.
                            </span>

                            <span class="d-block mt-2 small fst-italic" style="color:#5b4a2a;">
                                <i class="bi bi-lightbulb"></i>
                                <strong>متى تفعّله؟</strong>
                                لو بدك تقارن الربحية الحقيقية بين الفروع، أو تعرف
                                أي فرع عنده مشكلة بأسعار الشراء.
                            </span>
                        </small>
                    </span>
                </label>
            </div>
        @endif
    </form>

    <div class="pl-filter__exports mt-3">
        <a href="{{ route('admin.reports.profit-loss.export.xlsx') }}?{{ $exportQs }}" class="btn btn-success btn-sm">
            <i class="bi bi-file-earmark-excel-fill"></i> تصدير Excel
        </a>
        <a href="{{ route('admin.reports.profit-loss.export.pdf') }}?{{ $exportQs }}" target="_blank" class="btn btn-dark btn-sm">
            <i class="bi bi-printer-fill"></i> تقرير للطباعة
        </a>
        <span class="ms-auto text-muted small">
            <i class="bi bi-calendar-range"></i>
            {{ \Carbon\Carbon::parse($period['from'])->locale('ar')->isoFormat('D MMMM YYYY') }}
            —
            {{ \Carbon\Carbon::parse($period['to'])->locale('ar')->isoFormat('D MMMM YYYY') }}
            ({{ $period['days'] }} يوم)
        </span>
    </div>
</div>

{{-- ═══════════════ HERO KPI RAIL ═══════════════ --}}
<div class="pl-hero">
    <div class="pl-kpi pl-kpi--revenue">
        <div class="pl-kpi__head">
            <i class="bi bi-cash-stack"></i>
            <span>صافي المبيعات</span>
            <button type="button" class="pl-help" data-tip="ما تبقى من الإيرادات بعد طرح الخصومات. هذا هو الدخل الحقيقي قبل التكاليف.">ⓘ</button>
        </div>
        <div class="pl-kpi__value">{{ Money::format($sales['net_sales']) }}</div>
        <div class="pl-kpi__sub">
            من إجمالي {{ Money::format($sales['gross_sales']) }}
            <span class="pl-kpi__chip text-danger">−{{ Money::format($sales['discounts_total']) }} خصم</span>
        </div>
    </div>

    <div class="pl-kpi pl-kpi--gross">
        <div class="pl-kpi__head">
            <i class="bi bi-bar-chart-fill"></i>
            <span>الربح الإجمالي</span>
            <button type="button" class="pl-help" data-tip="صافي المبيعات − تكلفة البضاعة المباعة − الهدر. الربح قبل المصروفات التشغيلية.">ⓘ</button>
        </div>
        <div class="pl-kpi__value">{{ Money::format($profit['gross_profit']) }}</div>
        <div class="pl-kpi__sub">
            هامش {{ number_format($profit['gross_margin_pct'], 1) }}%
            @if($profit['gross_margin_pct'] >= 50)
                <span class="badge bg-success">ممتاز</span>
            @elseif($profit['gross_margin_pct'] >= 30)
                <span class="badge bg-warning text-dark">مقبول</span>
            @else
                <span class="badge bg-danger">منخفض</span>
            @endif
        </div>
    </div>

    <div class="pl-kpi pl-kpi--expenses">
        <div class="pl-kpi__head">
            <i class="bi bi-cash-coin"></i>
            <span>إجمالي التكاليف</span>
            <button type="button" class="pl-help" data-tip="مجموع كل ما خُصم من الإيراد للوصول لصافي الربح: تكلفة البضاعة + الهدر + المصروفات + العمولات + الاستردادات.">ⓘ</button>
        </div>
        @php $totalCosts = $costs['cogs'] + $costs['waste'] + $costs['expenses'] + $costs['platform_commission'] + $costs['refunds']; @endphp
        <div class="pl-kpi__value">{{ Money::format($totalCosts) }}</div>
        <div class="pl-kpi__sub">
            {{ $sales['net_sales'] > 0 ? number_format(($totalCosts / $sales['net_sales']) * 100, 1) : 0 }}% من المبيعات
        </div>
    </div>

    <div class="pl-kpi pl-kpi--net {{ $profit['net_profit'] < 0 ? 'is-loss' : '' }}">
        <div class="pl-kpi__head">
            <i class="bi bi-trophy-fill"></i>
            <span>صافي الربح</span>
            <button type="button" class="pl-help" data-tip="الربح النهائي بعد كل التكاليف والمصروفات والعمولات والاستردادات. هذا ما يدخل جيب صاحب المطعم فعلاً.">ⓘ</button>
        </div>
        <div class="pl-kpi__value">{{ Money::format($profit['net_profit']) }}</div>
        <div class="pl-kpi__sub">
            هامش صافي {{ number_format($profit['net_margin_pct'], 1) }}%
        </div>
    </div>
</div>

<div class="row g-3">
    {{-- ═══════════════ INCOME STATEMENT (the waterfall) ═══════════════ --}}
    <div class="col-lg-7">
        <div class="pl-statement">
            <div class="pl-statement__head">
                <h5 class="mb-0"><i class="bi bi-receipt"></i> قائمة الدخل التفصيلية</h5>
                <small class="text-muted">كل سطر مع شرحه — مرر على ⓘ</small>
            </div>

            <div class="pl-row pl-row--positive">
                <span>الإيرادات الإجمالية</span>
                <button type="button" class="pl-help" data-tip="مجموع الفواتير المُصدرة في الفترة (المُحصَّلة كلياً أو جزئياً) قبل خصم أي خصومات أو إضافة ضريبة. أساس قياس النشاط التجاري.">ⓘ</button>
                <strong>{{ Money::format($sales['gross_sales']) }}</strong>
            </div>

            <div class="pl-row pl-row--negative pl-row--indent">
                <span>− الخصومات</span>
                <button type="button" class="pl-help" data-tip="مجموع الخصومات المُمنوحة على الفواتير في هذه الفترة (يدوية من الكاشير، تلقائية للعملاء الدائمين، كوبونات).">ⓘ</button>
                <strong class="text-danger">−{{ Money::format($sales['discounts_total']) }}</strong>
            </div>

            <div class="pl-row pl-row--subtotal">
                <span>= صافي المبيعات</span>
                <button type="button" class="pl-help" data-tip="الإيراد الحقيقي قبل التكاليف. كل النسب لاحقاً تُحسب من هذا الرقم.">ⓘ</button>
                <strong>{{ Money::format($sales['net_sales']) }}</strong>
            </div>

            <div class="pl-row pl-row--negative pl-row--indent">
                <span>− تكلفة البضاعة المباعة (COGS)</span>
                <button type="button" class="pl-help" data-tip="تكلفة المكوّنات الفعلية للأصناف التي بِيعت = الكمية × تكلفة المكوّن. لا يشمل المصروفات التشغيلية.{{ $perBranchCost ? ' (محسوبة بأسعار شراء الفرع)' : '' }}">ⓘ</button>
                <strong class="text-danger">−{{ Money::format($costs['cogs']) }}</strong>
            </div>

            <div class="pl-row pl-row--negative pl-row--indent">
                <span>− الهدر</span>
                <button type="button" class="pl-help" data-tip="قيمة المكوّنات التي سُجِّلت كتالف/منتهي الصلاحية. خسارة مباشرة لا يقابلها بيع.">ⓘ</button>
                <strong class="text-danger">−{{ Money::format($costs['waste']) }}</strong>
            </div>

            <div class="pl-row pl-row--subtotal pl-row--gross">
                <span>= الربح الإجمالي</span>
                <button type="button" class="pl-help" data-tip="الربح من بيع الطعام قبل أي مصروفات إدارية أو تشغيلية. مؤشر صحة قائمة الطعام نفسها.">ⓘ</button>
                <strong>{{ Money::format($profit['gross_profit']) }}</strong>
                <span class="pl-row__pct">{{ number_format($profit['gross_margin_pct'], 1) }}%</span>
            </div>

            <div class="pl-row pl-row--negative pl-row--indent">
                <span>− المصروفات التشغيلية</span>
                <button type="button" class="pl-help" data-tip="مصروفات المعتمدة في هذه الفترة (إيجار، رواتب، فواتير كهرباء وماء، مشتريات يدوية، نقدية صغيرة). تأتي من شاشة المصروفات.">ⓘ</button>
                <strong class="text-danger">−{{ Money::format($costs['expenses']) }}</strong>
            </div>

            <div class="pl-row pl-row--negative pl-row--indent">
                <span>− عمولات منصات التوصيل</span>
                <button type="button" class="pl-help" data-tip="ما تأخذه منصات التوصيل (طلبات، كريم، أوبر) كنسبة من قيمة الطلب. محسوبة من نسبة العمولة المسجَّلة على كل طلب.">ⓘ</button>
                <strong class="text-danger">−{{ Money::format($costs['platform_commission']) }}</strong>
            </div>

            <div class="pl-row pl-row--subtotal">
                <span>= الربح من العمليات</span>
                <button type="button" class="pl-help" data-tip="الربح بعد كل التكاليف التشغيلية، قبل الاستردادات الاستثنائية. مؤشر فعالية الإدارة اليومية.">ⓘ</button>
                <strong>{{ Money::format($profit['operating_profit']) }}</strong>
                <span class="pl-row__pct">{{ number_format($profit['operating_margin_pct'], 1) }}%</span>
            </div>

            {{-- Always render the refunds line — even at zero. Accountants
                 expect to see every P&L line explicitly so they know it was
                 checked. Hiding "Refunds: 0" makes the report look like the
                 system isn't tracking them. --}}
            <div class="pl-row pl-row--indent {{ $costs['refunds'] > 0 ? 'pl-row--negative' : 'pl-row--muted' }}">
                <span>− الاستردادات</span>
                <button type="button" class="pl-help" data-tip="مبالغ أُعيدت للزبائن (شكاوى، أخطاء، إلخ). تظهر منفصلة لتمييز الخسائر الاستثنائية عن المصروفات الاعتيادية.">ⓘ</button>
                <strong class="{{ $costs['refunds'] > 0 ? 'text-danger' : 'text-muted' }}">
                    {{ $costs['refunds'] > 0 ? '−' : '' }}{{ Money::format($costs['refunds']) }}
                </strong>
            </div>

            <div class="pl-row pl-row--final {{ $profit['net_profit'] < 0 ? 'is-loss' : '' }}">
                <span>= صافي الربح</span>
                <button type="button" class="pl-help" data-tip="الرقم النهائي. هذا ما يستطيع المالك سحبه أو إعادة استثماره.">ⓘ</button>
                <strong>{{ Money::format($profit['net_profit']) }}</strong>
                <span class="pl-row__pct">{{ number_format($profit['net_margin_pct'], 1) }}%</span>
            </div>
        </div>

        {{-- Side-collected funds — clearly NOT income --}}
        <div class="pl-statement mt-3">
            <div class="pl-statement__head">
                <h6 class="mb-0"><i class="bi bi-info-circle"></i> مبالغ محصَّلة بشكل منفصل (ليست إيراداً)</h6>
                <small class="text-muted">تُحصَّل من الزبون لكنها ليست دخلاً للمطعم</small>
            </div>
            <div class="pl-row pl-row--neutral">
                <span>الضريبة المحصَّلة</span>
                <button type="button" class="pl-help" data-tip="الضريبة المضافة المحصَّلة من الزبائن. تُورَّد للجهة الضريبية، ليست دخلاً.">ⓘ</button>
                <strong>{{ Money::format($sales['tax_collected']) }}</strong>
            </div>
            @if($sales['service_collected'] > 0)
                <div class="pl-row pl-row--neutral">
                    <span>رسوم الخدمة</span>
                    <button type="button" class="pl-help" data-tip="رسم الخدمة المُضاف (عادةً 10%). يُعتبر دخلاً للمطعم في النموذج المحاسبي الكامل، لكنه يظهر هنا منفصلاً للوضوح.">ⓘ</button>
                    <strong>{{ Money::format($sales['service_collected']) }}</strong>
                </div>
            @endif
            @if($sales['tip_collected'] > 0)
                <div class="pl-row pl-row--neutral">
                    <span>الإكراميات</span>
                    <button type="button" class="pl-help" data-tip="الإكراميات المُسجَّلة على الفواتير. عادةً تُمرَّر للموظفين، ليست دخلاً للمطعم.">ⓘ</button>
                    <strong>{{ Money::format($sales['tip_collected']) }}</strong>
                </div>
            @endif
            @if($sales['delivery_collected'] > 0)
                <div class="pl-row pl-row--neutral">
                    <span>رسوم التوصيل</span>
                    <button type="button" class="pl-help" data-tip="ما يدفعه الزبون مقابل التوصيل. يقابل تكلفة المندوب أو رسوم المنصة.">ⓘ</button>
                    <strong>{{ Money::format($sales['delivery_collected']) }}</strong>
                </div>
            @endif
        </div>
    </div>

    {{-- ═══════════════ DAILY TREND + KEY STATS ═══════════════ --}}
    <div class="col-lg-5">
        <div class="pl-card">
            <div class="pl-card__head">
                <strong><i class="bi bi-graph-up"></i> الاتجاه اليومي</strong>
            </div>
            <div class="pl-card__body">
                <canvas id="plTrend" height="220"></canvas>
            </div>
        </div>

        <div class="pl-statement mt-3">
            <div class="pl-statement__head">
                <h6 class="mb-0"><i class="bi bi-cash"></i> أرقام التحصيل</h6>
                <small class="text-muted">المال الذي وصل فعلاً</small>
            </div>
            <div class="pl-row pl-row--neutral">
                <span>إجمالي الفواتير</span>
                <strong>{{ Money::format($sales['total_billed']) }}</strong>
            </div>
            <div class="pl-row pl-row--positive">
                <span>المُحصَّل فعلاً</span>
                <strong>{{ Money::format($sales['total_collected']) }}</strong>
            </div>
            @if($sales['unpaid_balance'] > 0)
                <div class="pl-row pl-row--negative">
                    <span>متبقٍ على الزبائن (دين)</span>
                    <strong class="text-warning">{{ Money::format($sales['unpaid_balance']) }}</strong>
                </div>
            @endif
            <div class="pl-row pl-row--subtotal">
                <span>عدد الفواتير</span>
                <strong>{{ number_format($sales['invoice_count']) }}</strong>
            </div>
            <div class="pl-row pl-row--neutral">
                <span>متوسط قيمة الفاتورة</span>
                <strong>{{ Money::format($sales['average_ticket']) }}</strong>
            </div>
            <div class="pl-row pl-row--neutral">
                <span>متوسط المبيعات اليومية</span>
                <strong>{{ Money::format($sales['daily_average']) }}</strong>
            </div>
        </div>
    </div>
</div>

{{-- ═══════════════ DISCOUNTS BREAKDOWN ═══════════════ --}}
<div class="row g-3 mt-1">
    <div class="col-lg-6">
        <div class="pl-card">
            <div class="pl-card__head">
                <strong><i class="bi bi-percent"></i> تفصيل الخصومات</strong>
                <small class="text-muted">{{ $r['discounts']['count'] }} خصم بإجمالي {{ Money::format($r['discounts']['total']) }}</small>
            </div>
            <div class="pl-card__body">
                @if(empty($r['discounts']['by_category']))
                    <p class="text-muted text-center py-3 mb-0">لا توجد خصومات في هذه الفترة.</p>
                @else
                    <table class="table table-sm pl-table">
                        <thead>
                            <tr><th>التصنيف</th><th class="text-center">عدد</th><th class="text-end">المجموع</th><th class="text-end">% من المبيعات</th></tr>
                        </thead>
                        <tbody>
                            @foreach($r['discounts']['by_category'] as $row)
                                <tr>
                                    <td>
                                        <span class="pl-dot" style="background:{{ $row['color'] }}"></span>
                                        {{ $row['label'] }}
                                    </td>
                                    <td class="text-center">{{ $row['count'] }}</td>
                                    <td class="text-end fw-bold">{{ Money::format($row['total']) }}</td>
                                    <td class="text-end text-muted">{{ $sales['net_sales'] > 0 ? number_format(($row['total'] / $sales['net_sales']) * 100, 2) : '0.00' }}%</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>

                    @if(count($r['discounts']['by_cashier']))
                        <hr class="my-2">
                        <h6 class="small text-muted mb-2"><i class="bi bi-person-fill"></i> أعلى الموظفين منحاً للخصم</h6>
                        <table class="table table-sm pl-table mb-0">
                            <tbody>
                                @foreach($r['discounts']['by_cashier'] as $row)
                                    <tr>
                                        <td>{{ $row['name'] }}</td>
                                        <td class="text-center text-muted">{{ $row['count'] }} خصم</td>
                                        <td class="text-end fw-bold">{{ Money::format($row['total']) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @endif
                @endif
            </div>
        </div>
    </div>

    {{-- ═══════════════ EXPENSES BREAKDOWN ═══════════════ --}}
    <div class="col-lg-6">
        <div class="pl-card">
            <div class="pl-card__head">
                <strong><i class="bi bi-cash-coin"></i> تفصيل المصروفات التشغيلية</strong>
                <small class="text-muted">{{ Money::format($costs['expenses']) }} موزَّعة على {{ count($r['expenses_breakdown']['by_category']) }} تصنيف</small>
            </div>
            <div class="pl-card__body">
                @if(empty($r['expenses_breakdown']['by_category']))
                    <p class="text-muted text-center py-3 mb-0">لا توجد مصروفات معتمدة في هذه الفترة.</p>
                @else
                    <table class="table table-sm pl-table">
                        <thead>
                            <tr><th>التصنيف</th><th class="text-center">عدد</th><th class="text-end">المجموع</th><th class="text-end">% من المبيعات</th></tr>
                        </thead>
                        <tbody>
                            @foreach($r['expenses_breakdown']['by_category'] as $row)
                                <tr>
                                    <td>
                                        <span class="pl-dot" style="background:{{ $row['color'] }}"></span>
                                        {{ $row['label'] }}
                                    </td>
                                    <td class="text-center">{{ $row['count'] }}</td>
                                    <td class="text-end fw-bold">{{ Money::format($row['total']) }}</td>
                                    <td class="text-end text-muted">{{ $sales['net_sales'] > 0 ? number_format(($row['total'] / $sales['net_sales']) * 100, 2) : '0.00' }}%</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>

                    @if(count($r['expenses_breakdown']['by_method']))
                        <hr class="my-2">
                        <h6 class="small text-muted mb-2"><i class="bi bi-credit-card"></i> حسب طريقة الدفع</h6>
                        <div class="d-flex flex-wrap gap-2">
                            @foreach($r['expenses_breakdown']['by_method'] as $row)
                                <span class="badge bg-light text-dark border" style="padding: 6px 10px;">
                                    {{ $row['label'] }}: <strong>{{ Money::format($row['total']) }}</strong> ({{ $row['count'] }})
                                </span>
                            @endforeach
                        </div>
                    @endif
                @endif
            </div>
        </div>
    </div>
</div>

{{-- ═══════════════ TOP ITEMS ═══════════════ --}}
@if($r['top_items']->count())
    <div class="pl-card mt-3">
        <div class="pl-card__head">
            <strong><i class="bi bi-stars"></i> الأصناف الأكثر مساهمة في الربح</strong>
            <small class="text-muted">مرتَّبة حسب الربح المُحقَّق (الإيراد − تكلفة المكوّنات)</small>
        </div>
        <div class="pl-card__body">
            <table class="table table-sm pl-table mb-0">
                <thead>
                    <tr>
                        <th>الصنف</th>
                        <th class="text-center">الكمية</th>
                        <th class="text-end">الإيراد</th>
                        <th class="text-end">التكلفة</th>
                        <th class="text-end">الربح</th>
                        <th class="text-end">هامش%</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($r['top_items'] as $i => $it)
                        @php $margin = $it->revenue > 0 ? ($it->profit / $it->revenue) * 100 : 0; @endphp
                        <tr>
                            <td>
                                <span class="pl-rank">#{{ $i + 1 }}</span>
                                <strong>{{ $it->name }}</strong>
                            </td>
                            <td class="text-center">{{ rtrim(rtrim(number_format((float) $it->qty, 2), '0'), '.') }}</td>
                            <td class="text-end">{{ Money::format($it->revenue) }}</td>
                            <td class="text-end text-danger">{{ Money::format($it->cogs) }}</td>
                            <td class="text-end fw-bold text-success">{{ Money::format($it->profit) }}</td>
                            <td class="text-end">
                                <span class="badge bg-{{ $margin >= 50 ? 'success' : ($margin >= 30 ? 'warning text-dark' : 'danger') }}">
                                    {{ number_format($margin, 1) }}%
                                </span>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endif

{{-- ═══════════════ GLOSSARY ═══════════════ --}}
<details class="pl-glossary mt-3">
    <summary><i class="bi bi-book"></i> مفاتيح فهم التقرير (المعادلات والمصطلحات)</summary>
    <div class="pl-glossary__body">
        <dl>
            <dt>الإيرادات الإجمالية</dt>
            <dd>مجموع <code>subtotal</code> للفواتير المُصدرة في الفترة (المُحصَّلة كلياً أو جزئياً) قبل خصم الخصومات.</dd>

            <dt>الخصومات</dt>
            <dd>مجموع الخصومات على الفواتير في الفترة. تشمل الخصومات اليدوية من الكاشير وأي تخفيضات تلقائية.</dd>

            <dt>صافي المبيعات</dt>
            <dd>= الإيرادات الإجمالية − الخصومات. أساس حساب كل النسب لاحقاً.</dd>

            <dt>تكلفة البضاعة المباعة (COGS)</dt>
            <dd>= Σ (كمية الصنف المباع × تكلفة المكوّنات للصنف). محسوبة من الوصفات المسجَّلة.</dd>

            <dt>الهدر</dt>
            <dd>قيمة المكوّنات المُسجَّلة كهدر/تالف في الفترة. خسارة مباشرة لا يقابلها بيع.</dd>

            <dt>الربح الإجمالي</dt>
            <dd>= صافي المبيعات − COGS − الهدر. مؤشر صحة قائمة الطعام والتسعير.</dd>

            <dt>المصروفات التشغيلية</dt>
            <dd>المصروفات المعتمدة في الفترة من شاشة المصروفات (إيجار، رواتب، فواتير، نقدية صغيرة…).</dd>

            <dt>عمولات منصات التوصيل</dt>
            <dd>= Σ (إجمالي الطلب × نسبة عمولة المنصة). محسوبة من الطلبات المُسجَّلة بمصدر خارجي.</dd>

            <dt>الربح من العمليات</dt>
            <dd>= الربح الإجمالي − المصروفات − عمولات المنصات. مؤشر فعالية الإدارة اليومية.</dd>

            <dt>الاستردادات</dt>
            <dd>المبالغ المُعادة للزبائن في الفترة (شكاوى، أخطاء…). تظهر منفصلة لتمييز الخسائر الاستثنائية.</dd>

            <dt>صافي الربح</dt>
            <dd>= الربح من العمليات − الاستردادات. <strong>الرقم النهائي.</strong></dd>

            <dt>هامش الربح %</dt>
            <dd>= (الربح ÷ صافي المبيعات) × 100. <strong>50%+ ممتاز · 30–50% مقبول · أقل من 30% يحتاج مراجعة.</strong></dd>

            <dt>الضريبة المحصَّلة</dt>
            <dd>تظهر كمعلومة فقط — ليست دخلاً للمطعم، تُورَّد للجهة الضريبية.</dd>

            <dt>تاريخ الفلترة لكل بند</dt>
            <dd>الفواتير: <code>issued_at</code> · المصروفات: <code>expense_date</code> · الاستردادات: <code>refunded_at</code> · الهدر: <code>occurred_at</code>.</dd>
        </dl>
    </div>
</details>

{{-- ═══════════════ STYLES ═══════════════ --}}
<style>
.pl-filter {
    background: white; border-radius: 14px; padding: 1rem;
    border: 1px solid rgba(15,71,49,.1); box-shadow: 0 1px 3px rgba(0,0,0,.04);
}
.pl-filter__exports {
    display: flex; align-items: center; gap: 8px; flex-wrap: wrap;
    border-top: 1px dashed rgba(15,71,49,.15); padding-top: 12px;
}

/* HERO KPI */
.pl-hero {
    display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; margin-bottom: 1rem;
}
@media (max-width: 992px) { .pl-hero { grid-template-columns: 1fr 1fr; } }
@media (max-width: 576px) { .pl-hero { grid-template-columns: 1fr; } }
.pl-kpi {
    background: white; border-radius: 14px; padding: 18px 20px;
    border: 1px solid rgba(15,71,49,.08); position: relative; overflow: hidden;
    box-shadow: 0 2px 8px rgba(0,0,0,.04);
}
.pl-kpi::after {
    content: ''; position: absolute; top: 0; right: 0; width: 4px; height: 100%; background: var(--accent, #b97818);
}
.pl-kpi--revenue::after { background: #1f4733; }
.pl-kpi--gross::after   { background: #2d6e4a; }
.pl-kpi--expenses::after{ background: #b45309; }
.pl-kpi--net::after     { background: #b97818; }
.pl-kpi--net.is-loss::after { background: #b91c1c; }
.pl-kpi__head {
    display: flex; align-items: center; gap: 8px; color: #475569; font-size: .85rem; margin-bottom: 8px;
}
.pl-kpi__head i { font-size: 1rem; }
.pl-kpi__value { font-size: 1.6rem; font-weight: 800; color: #0f2d22; line-height: 1.2; }
.pl-kpi--net.is-loss .pl-kpi__value { color: #b91c1c; }
.pl-kpi__sub { font-size: .8rem; color: #64748b; margin-top: 4px; display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }
.pl-kpi__chip { padding: 2px 8px; background: #fef2f2; border-radius: 6px; font-weight: 600; font-size: .75rem; }

/* INCOME STATEMENT */
.pl-statement {
    background: white; border-radius: 14px; padding: 16px;
    border: 1px solid rgba(15,71,49,.08); box-shadow: 0 1px 3px rgba(0,0,0,.03);
}
.pl-statement__head {
    display: flex; justify-content: space-between; align-items: baseline;
    border-bottom: 2px solid #b97818; padding-bottom: 10px; margin-bottom: 12px;
}
.pl-statement__head h5, .pl-statement__head h6 { color: #0f2d22; font-weight: 700; }

.pl-row {
    display: flex; align-items: center; gap: 10px;
    padding: 9px 4px; border-bottom: 1px dashed #e2e8f0;
    font-size: .92rem;
}
.pl-row:last-child { border-bottom: none; }
.pl-row span:first-child { flex: 1; color: #334155; }
.pl-row strong { color: #0f2d22; min-width: 110px; text-align: end; }
.pl-row__pct {
    background: #f1f5f9; padding: 2px 8px; border-radius: 6px; font-size: .78rem;
    font-weight: 600; color: #475569; min-width: 56px; text-align: center;
}

.pl-row--indent { padding-right: 1.5rem; }
.pl-row--indent span:first-child { color: #64748b; font-size: .87rem; }

.pl-row--positive strong { color: #14532d; }
.pl-row--negative strong { color: #b91c1c; }
/* Muted variant — used for zero-value cost lines (e.g. refunds = 0).
   The line still renders so the manager sees it was checked, but in a
   softer color so it doesn't compete visually with real costs. */
.pl-row--muted span:first-child { color: #94a3b8; }
.pl-row--muted strong { color: #94a3b8; }
.pl-row--subtotal {
    background: #f8fafc; border-radius: 8px; padding: 11px 12px;
    margin: 6px 0; border: 1px solid #e2e8f0; border-bottom: 1px solid #e2e8f0;
    font-weight: 600;
}
.pl-row--subtotal span:first-child { color: #0f2d22; }
.pl-row--gross { background: #f0fdf4; border-color: #bbf7d0; }
.pl-row--gross strong { color: #14532d; }

.pl-row--final {
    background: linear-gradient(135deg, #f0fdf4, #fef9e7);
    border: 2px solid #b97818; border-radius: 10px;
    padding: 14px 16px; margin-top: 10px; font-size: 1.05rem; font-weight: 700;
}
.pl-row--final span:first-child { color: #0f2d22; font-size: 1rem; }
.pl-row--final strong { font-size: 1.25rem; color: #b97818; min-width: 130px; }
.pl-row--final.is-loss { background: linear-gradient(135deg, #fef2f2, #fff7e6); border-color: #b91c1c; }
.pl-row--final.is-loss strong { color: #b91c1c; }

.pl-row--neutral strong { color: #475569; }

/* HELP TIP */
.pl-help {
    width: 18px; height: 18px; padding: 0; border: 1px solid #cbd5e1;
    background: white; color: #64748b; border-radius: 50%; font-size: .7rem;
    cursor: help; line-height: 1; display: inline-flex; align-items: center; justify-content: center;
    transition: all .15s;
}
.pl-help:hover { background: #b97818; color: white; border-color: #b97818; transform: scale(1.1); }

/* CARD */
.pl-card {
    background: white; border-radius: 14px;
    border: 1px solid rgba(15,71,49,.08); box-shadow: 0 1px 3px rgba(0,0,0,.03); overflow: hidden;
}
.pl-card__head {
    padding: 12px 16px; background: linear-gradient(180deg, #fafdfa, #f5f9f5);
    border-bottom: 1px solid #e6efe9; display: flex; justify-content: space-between; align-items: baseline;
}
.pl-card__head strong { color: #0f2d22; }
.pl-card__body { padding: 14px 16px; }

/* TABLE */
.pl-table { font-size: .87rem; }
.pl-table thead th {
    font-size: .75rem; color: #64748b; font-weight: 600; text-transform: uppercase;
    letter-spacing: .5px; border-bottom: 2px solid #b97818; padding: 6px 8px;
}
.pl-table td { padding: 8px; vertical-align: middle; }
.pl-dot {
    display: inline-block; width: 8px; height: 8px; border-radius: 50%; margin-left: 6px; vertical-align: middle;
}
.pl-rank {
    display: inline-block; width: 24px; height: 24px; line-height: 24px; text-align: center;
    background: #fef9e7; color: #b97818; font-weight: 700; border-radius: 50%; font-size: .72rem; margin-left: 6px;
}

/* GLOSSARY */
.pl-glossary {
    background: white; border-radius: 14px; padding: 14px 18px;
    border: 1px solid rgba(15,71,49,.08); box-shadow: 0 1px 3px rgba(0,0,0,.03);
}
.pl-glossary > summary {
    cursor: pointer; font-weight: 700; color: #0f2d22; padding: 4px 0;
    list-style: none; display: flex; align-items: center; gap: 8px;
}
.pl-glossary > summary::-webkit-details-marker { display: none; }
.pl-glossary > summary::after {
    content: '⌄'; margin-right: auto; transition: transform .2s; font-size: 1.2rem;
}
.pl-glossary[open] > summary::after { transform: rotate(180deg); }
.pl-glossary__body {
    margin-top: 12px; padding-top: 12px; border-top: 1px dashed #e2e8f0;
}
.pl-glossary dl { margin: 0; }
.pl-glossary dt {
    font-weight: 700; color: #b97818; font-size: .9rem; margin-top: 10px;
}
.pl-glossary dt:first-child { margin-top: 0; }
.pl-glossary dd { color: #475569; font-size: .87rem; margin: 2px 0 0 0; line-height: 1.7; }
.pl-glossary code {
    background: #f1f5f9; padding: 1px 6px; border-radius: 4px; color: #0f2d22; font-size: .82rem;
}

/* PRINT */
@media print {
    .pl-filter, nav, .breadcrumb-wrap, button { display: none !important; }
    .pl-kpi, .pl-statement, .pl-card { box-shadow: none !important; border: 1px solid #94a3b8 !important; break-inside: avoid; }
    body { background: white !important; }
}
</style>

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    // Tooltips on ⓘ
    document.querySelectorAll('.pl-help').forEach(btn => {
        btn.setAttribute('title', btn.dataset.tip || '');
    });

    // Trend chart
    const ctx = document.getElementById('plTrend');
    if (!ctx) return;
    const data = @json($r['trend']);
    new Chart(ctx, {
        type: 'line',
        data: {
            labels: data.map(d => d.label),
            datasets: [
                { label: 'صافي المبيعات', data: data.map(d => d.net_sales), borderColor: '#1f4733', backgroundColor: 'rgba(31,71,51,.08)', fill: true, tension: .35, borderWidth: 2 },
                { label: 'الربح',         data: data.map(d => d.profit),    borderColor: '#b97818', backgroundColor: 'rgba(185,120,24,.08)', fill: true, tension: .35, borderWidth: 2 },
                { label: 'المصروفات',     data: data.map(d => d.expenses),  borderColor: '#b45309', backgroundColor: 'transparent', borderDash: [6,4], tension: .25, borderWidth: 1.6 },
            ],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { position: 'bottom', labels: { font: { family: 'Cairo, sans-serif', size: 11 } } },
                tooltip: { rtl: true, bodyFont: { family: 'Cairo, sans-serif' }, titleFont: { family: 'Cairo, sans-serif' } },
            },
            scales: {
                x: { ticks: { font: { family: 'Cairo, sans-serif', size: 10 } } },
                y: { ticks: { font: { family: 'Cairo, sans-serif' }, callback: v => v.toLocaleString('ar') } },
            },
        }
    });
});
</script>
@endpush
@endsection
