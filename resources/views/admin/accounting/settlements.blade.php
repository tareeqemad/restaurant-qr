@extends('layouts.admin')

@section('title', 'التسويات المحاسبية')

@section('content')
<x-admin.breadcrumb
    title="التسويات المحاسبية"
    icon="bi-arrow-left-right"
    subtitle="ترحيل سداد الضريبة، صرف الإكراميات، وتسوية دفعات البطاقات مع الرسوم من دفتر القيود"
    :crumbs="[['label' => 'القيود اليومية', 'url' => route('admin.accounting.journal')]]" />

<form method="GET" action="{{ route('admin.accounting.settlements') }}" class="card mb-3">
    <div class="card-body">
        <div class="row g-3 align-items-end">
            <div class="col-md-3">
                <label class="form-label fw-bold">من تاريخ</label>
                <input type="date" name="from" value="{{ $from }}" class="form-control">
            </div>
            <div class="col-md-3">
                <label class="form-label fw-bold">إلى تاريخ</label>
                <input type="date" name="to" value="{{ $to }}" class="form-control">
            </div>
            <div class="col-md-3">
                <label class="form-label fw-bold">رصيد الإكراميات حتى</label>
                <input type="date" name="as_of" value="{{ $asOf }}" class="form-control">
            </div>
            <div class="col-md-3 d-grid">
                <button class="btn btn-primary"><i class="bi bi-funnel"></i> تحديث الأرصدة</button>
            </div>
        </div>
    </div>
</form>

<div class="row g-3">
    <div class="col-xl-4">
        <div class="card h-100">
            <div class="card-header bg-light">
                <strong><i class="bi bi-percent me-1"></i> سداد {{ $taxAmounts['tax_label'] }}</strong>
            </div>
            <div class="card-body">
                <div class="d-flex justify-content-between border-bottom py-2">
                    <span>ضريبة المخرجات</span>
                    <strong>{{ \App\Helpers\Money::formatAccounting($taxAmounts['output_tax']) }}</strong>
                </div>
                @unless($taxAmounts['is_us_market'])
                    <div class="d-flex justify-content-between border-bottom py-2">
                        <span>ضريبة المدخلات</span>
                        <strong>{{ \App\Helpers\Money::formatAccounting($taxAmounts['input_tax']) }}</strong>
                    </div>
                @endunless
                <div class="d-flex justify-content-between py-2 mb-3">
                    <span class="fw-bold">المستحق للسداد</span>
                    <strong class="text-primary">{{ \App\Helpers\Money::formatAccounting($taxAmounts['payable']) }}</strong>
                </div>

                <form method="POST" action="{{ route('admin.accounting.settlements.tax-payment') }}">
                    @csrf
                    <input type="hidden" name="from" value="{{ $from }}">
                    <input type="hidden" name="to" value="{{ $to }}">
                    <div class="mb-3">
                        <label class="form-label fw-bold">تاريخ القيد</label>
                        <input type="date" name="posted_on" value="{{ now()->toDateString() }}" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">طريقة السداد</label>
                        <select name="payment_method" class="form-select" required>
                            @foreach($paymentMethods as $method => $label)
                                <option value="{{ $method }}" @selected($method === 'bank_transfer')>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <button class="btn btn-primary w-100" @disabled($taxAmounts['payable'] <= 0.01)>
                        <i class="bi bi-journal-check"></i> ترحيل سداد الضريبة
                    </button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-xl-4">
        <div class="card h-100">
            <div class="card-header bg-light">
                <strong><i class="bi bi-cash-coin me-1"></i> صرف الإكراميات</strong>
            </div>
            <div class="card-body">
                <div class="d-flex justify-content-between py-2 mb-3 border-bottom">
                    <span>الرصيد المستحق للموظفين</span>
                    <strong class="text-primary">{{ \App\Helpers\Money::formatAccounting($tipsPayable) }}</strong>
                </div>

                <form method="POST" action="{{ route('admin.accounting.settlements.tips-payout') }}">
                    @csrf
                    <div class="mb-3">
                        <label class="form-label fw-bold">تاريخ القيد</label>
                        <input type="date" name="posted_on" value="{{ $asOf }}" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">المبلغ المصروف</label>
                        <input type="number" step="0.01" min="0" max="{{ $tipsPayable }}" name="amount" value="{{ $tipsPayable }}" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">طريقة الصرف</label>
                        <select name="payment_method" class="form-select" required>
                            @foreach($paymentMethods as $method => $label)
                                <option value="{{ $method }}" @selected($method === 'cash')>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">ملاحظات</label>
                        <textarea name="notes" rows="2" class="form-control"></textarea>
                    </div>
                    <button class="btn btn-primary w-100" @disabled($tipsPayable <= 0.01)>
                        <i class="bi bi-journal-check"></i> ترحيل صرف الإكراميات
                    </button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-xl-4">
        <div class="card h-100">
            <div class="card-header bg-light">
                <strong><i class="bi bi-credit-card-2-front me-1"></i> تسوية بوابة الدفع</strong>
            </div>
            <div class="card-body">
                <form method="POST" action="{{ route('admin.accounting.settlements.payment-clearing') }}">
                    @csrf
                    <div class="mb-3">
                        <label class="form-label fw-bold">تاريخ القيد</label>
                        <input type="date" name="posted_on" value="{{ now()->toDateString() }}" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">حساب التحصيل/المقاصة</label>
                        <select name="clearing_account_id" class="form-select" required>
                            @foreach($assetAccounts as $account)
                                <option value="{{ $account->id }}">{{ $account->code }} - {{ $account->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">حساب الإيداع</label>
                        <select name="deposit_account_id" class="form-select" required>
                            @foreach($assetAccounts as $account)
                                <option value="{{ $account->id }}" @selected($account->code === \App\Services\Accounting\AccountingService::BANK)>{{ $account->code }} - {{ $account->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="row g-2">
                        <div class="col-6">
                            <label class="form-label fw-bold">الإجمالي</label>
                            <input type="number" step="0.01" min="0" name="gross_amount" class="form-control" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-bold">العمولة</label>
                            <input type="number" step="0.01" min="0" name="fee_amount" value="0" class="form-control" required>
                        </div>
                    </div>
                    <div class="mt-3 mb-3">
                        <label class="form-label fw-bold">ملاحظات</label>
                        <textarea name="notes" rows="2" class="form-control"></textarea>
                    </div>
                    <button class="btn btn-primary w-100" @disabled($assetAccounts->count() < 2)>
                        <i class="bi bi-journal-check"></i> ترحيل التسوية
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<x-admin.data-panel title="آخر التسويات" icon="bi-clock-history" :count="$recentSettlements->total()" class="mt-3">
    <div class="table-responsive">
        <table class="table align-middle mb-0">
            <thead class="bg-light">
                <tr>
                    <th>التاريخ</th>
                    <th>القيد</th>
                    <th>النوع</th>
                    <th class="text-end">مدين</th>
                    <th class="text-end">دائن</th>
                    <th>بواسطة</th>
                </tr>
            </thead>
            <tbody>
                @forelse($recentSettlements as $entry)
                    @php
                        $labels = [
                            'tax_payment' => 'سداد ضريبة',
                            'tips_payout' => 'صرف إكراميات',
                            'payment_clearing_settlement' => 'تسوية بوابة دفع',
                        ];
                    @endphp
                    <tr>
                        <td>{{ $entry->posted_on?->toDateString() }}</td>
                        <td>
                            <a href="{{ route('admin.accounting.journal', ['search' => $entry->entry_no]) }}" class="fw-bold">
                                {{ $entry->entry_no }}
                            </a>
                            <div class="text-muted small">{{ $entry->description }}</div>
                        </td>
                        <td>{{ $labels[$entry->event_type] ?? $entry->event_type }}</td>
                        <td class="text-end">{{ \App\Helpers\Money::formatAccounting($entry->lines->sum('debit')) }}</td>
                        <td class="text-end">{{ \App\Helpers\Money::formatAccounting($entry->lines->sum('credit')) }}</td>
                        <td>{{ $entry->creator?->name ?? '—' }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="text-center text-muted py-4">لا توجد تسويات بعد.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-3">{{ $recentSettlements->links() }}</div>
</x-admin.data-panel>
@endsection
