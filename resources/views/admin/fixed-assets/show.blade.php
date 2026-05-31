@extends('layouts.admin')

@section('title', $asset->asset_number.' - '.$asset->name)

@section('content')
<x-admin.breadcrumb
    :title="$asset->asset_number.' - '.$asset->name"
    icon="bi-building-gear"
    subtitle="تفاصيل الأصل، الإهلاك، والاستبعاد"
    :crumbs="[
        ['label' => 'الأصول الثابتة', 'url' => route('admin.accounting.fixed-assets.index')],
        ['label' => $asset->asset_number]
    ]" />

<div class="row g-3 mb-3">
    <div class="col-md-3">
        <x-admin.kpi icon="bi-cash-stack" color="primary" :value="\App\Helpers\Money::formatAccounting($asset->cost)" label="تكلفة الأصل" />
    </div>
    <div class="col-md-3">
        <x-admin.kpi icon="bi-graph-down-arrow" color="warning" :value="\App\Helpers\Money::formatAccounting($asset->accumulated_depreciation)" label="مجمع الإهلاك" />
    </div>
    <div class="col-md-3">
        <x-admin.kpi icon="bi-wallet2" color="success" :value="\App\Helpers\Money::formatAccounting($asset->bookValue())" label="القيمة الدفترية" />
    </div>
    <div class="col-md-3">
        <x-admin.kpi icon="bi-calendar3" color="info" :value="\App\Helpers\Money::formatAccounting($asset->monthlyDepreciationAmount())" label="إهلاك شهري" />
    </div>
</div>

<div class="row g-3">
    <div class="col-xl-7">
        <div class="card mb-3">
            <div class="card-header bg-light"><strong>بيانات الأصل</strong></div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <div class="text-muted small">الحالة</div>
                        <strong>{{ $statusLabels[$asset->status] ?? $asset->status }}</strong>
                    </div>
                    <div class="col-md-6">
                        <div class="text-muted small">الفئة</div>
                        <strong>{{ $asset->category ?? '—' }}</strong>
                    </div>
                    <div class="col-md-6">
                        <div class="text-muted small">تاريخ الشراء</div>
                        <strong>{{ $asset->acquisition_date?->toDateString() }}</strong>
                    </div>
                    <div class="col-md-6">
                        <div class="text-muted small">تاريخ بدء الاستخدام</div>
                        <strong>{{ $asset->in_service_date?->toDateString() }}</strong>
                    </div>
                    <div class="col-md-6">
                        <div class="text-muted small">العمر الإنتاجي</div>
                        <strong>{{ $asset->useful_life_months }} شهر</strong>
                    </div>
                    <div class="col-md-6">
                        <div class="text-muted small">المورد</div>
                        <strong>{{ $asset->supplier?->name ?? $asset->vendor_name ?? '—' }}</strong>
                    </div>
                    <div class="col-md-6">
                        <div class="text-muted small">عملة الشراء</div>
                        <strong>{{ $asset->currency_code }} × {{ number_format((float) $asset->exchange_rate, 8) }}</strong>
                    </div>
                    <div class="col-md-6">
                        <div class="text-muted small">التكلفة بعملة الشراء</div>
                        <strong>{{ number_format((float) $asset->foreign_cost, 2) }} {{ $asset->currency_code }}</strong>
                    </div>
                    <div class="col-md-6">
                        <div class="text-muted small">قيد الشراء</div>
                        @if($asset->purchaseEntry)
                            <a href="{{ route('admin.accounting.journal', ['search' => $asset->purchaseEntry->entry_no]) }}">{{ $asset->purchaseEntry->entry_no }}</a>
                        @else
                            <span>—</span>
                        @endif
                    </div>
                    <div class="col-md-6">
                        <div class="text-muted small">قيد الاستبعاد</div>
                        @if($asset->disposalEntry)
                            <a href="{{ route('admin.accounting.journal', ['search' => $asset->disposalEntry->entry_no]) }}">{{ $asset->disposalEntry->entry_no }}</a>
                        @else
                            <span>—</span>
                        @endif
                    </div>
                </div>
                @if($asset->notes)
                    <div class="alert alert-light border mt-3 mb-0">{{ $asset->notes }}</div>
                @endif
            </div>
        </div>

        <x-admin.data-panel title="سجل الإهلاك" icon="bi-clock-history" :count="$asset->depreciations->count()">
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead class="bg-light">
                        <tr>
                            <th>الفترة</th>
                            <th>تاريخ القيد</th>
                            <th class="text-end">المبلغ</th>
                            <th class="text-end">المجمع بعد القيد</th>
                            <th>القيد</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($asset->depreciations->sortByDesc('period_end') as $row)
                            <tr>
                                <td>{{ $row->period_start?->format('Y-m') }}</td>
                                <td>{{ $row->posted_on?->toDateString() }}</td>
                                <td class="text-end">{{ \App\Helpers\Money::formatAccounting($row->amount) }}</td>
                                <td class="text-end">{{ \App\Helpers\Money::formatAccounting($row->accumulated_after) }}</td>
                                <td>
                                    @if($row->journalEntry)
                                        <a href="{{ route('admin.accounting.journal', ['search' => $row->journalEntry->entry_no]) }}">{{ $row->journalEntry->entry_no }}</a>
                                    @else
                                        —
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="text-center text-muted py-4">لم يتم ترحيل إهلاك بعد.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-admin.data-panel>
    </div>

    <div class="col-xl-5">
        <div class="card mb-3">
            <div class="card-header bg-light"><strong>ترحيل إهلاك شهري</strong></div>
            <div class="card-body">
                <div class="d-flex justify-content-between border-bottom pb-2 mb-3">
                    <span>المبلغ المتوقع للفترة القادمة</span>
                    <strong>{{ \App\Helpers\Money::formatAccounting($nextDepreciationAmount) }}</strong>
                </div>
                <form method="POST" action="{{ route('admin.accounting.fixed-assets.depreciation', $asset) }}">
                    @csrf
                    <div class="mb-3">
                        <label class="form-label fw-bold">شهر الإهلاك</label>
                        <input type="month" name="period_month" value="{{ now()->format('Y-m') }}" class="form-control" required @disabled($asset->status === 'disposed')>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">تاريخ القيد</label>
                        <input type="date" name="posted_on" value="{{ now()->toDateString() }}" class="form-control" required @disabled($asset->status === 'disposed')>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">ملاحظات</label>
                        <textarea name="notes" rows="2" class="form-control" @disabled($asset->status === 'disposed')></textarea>
                    </div>
                    <button class="btn btn-primary w-100" @disabled($asset->status === 'disposed' || $asset->remainingDepreciableAmount() <= 0.01)>
                        <i class="bi bi-journal-check"></i> ترحيل الإهلاك
                    </button>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-header bg-light"><strong>استبعاد / بيع الأصل</strong></div>
            <div class="card-body">
                @if($asset->status === 'disposed')
                    <div class="alert alert-secondary mb-0">
                        تم استبعاد الأصل بتاريخ {{ $asset->disposed_on?->toDateString() }} بقيمة {{ \App\Helpers\Money::formatAccounting($asset->disposal_proceeds) }}.
                    </div>
                @else
                    <form method="POST" action="{{ route('admin.accounting.fixed-assets.dispose', $asset) }}">
                        @csrf
                        <div class="mb-3">
                            <label class="form-label fw-bold">تاريخ الاستبعاد</label>
                            <input type="date" name="disposed_on" value="{{ now()->toDateString() }}" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold">متحصلات البيع</label>
                            <input type="number" step="0.01" min="0" name="disposal_proceeds" value="0" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold">طريقة التحصيل</label>
                            <select name="disposal_payment_method" class="form-select">
                                @foreach($disposalPaymentMethods as $method => $label)
                                    <option value="{{ $method }}" @selected($method === 'bank_transfer')>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold">ملاحظات</label>
                            <textarea name="notes" rows="2" class="form-control"></textarea>
                        </div>
                        <button class="btn btn-danger w-100">
                            <i class="bi bi-box-arrow-down"></i> استبعاد وترحيل القيد
                        </button>
                    </form>
                @endif
            </div>
        </div>
    </div>
</div>
@endsection
