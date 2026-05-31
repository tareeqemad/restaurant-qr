@extends('layouts.admin')

@section('title', 'مطابقة الصندوق والبنك')

@section('content')
<x-admin.breadcrumb
    title="مطابقة الصندوق والبنك"
    icon="bi-check2-square"
    subtitle="قارن رصيد دفتر القيود مع كشف البنك أو الجرد الفعلي للصندوق"
    :crumbs="[['label' => 'القيود اليومية', 'url' => route('admin.accounting.journal')]]" />

<div class="row g-3">
    <div class="col-lg-4">
        <div class="card">
            <div class="card-header bg-light"><strong>مطابقة جديدة</strong></div>
            <div class="card-body">
                <form method="GET" class="mb-3">
                    <label class="form-label fw-bold">الحساب</label>
                    <select name="account_id" class="form-select mb-2" onchange="this.form.submit()">
                        @foreach($accounts as $account)
                            <option value="{{ $account->id }}" @selected($selectedAccount && $selectedAccount->id === $account->id)>
                                {{ $account->code }} - {{ $account->name }}
                            </option>
                        @endforeach
                    </select>
                    <label class="form-label fw-bold">تاريخ الكشف</label>
                    <input type="date" name="statement_date" value="{{ $statementDate }}" class="form-control" onchange="this.form.submit()">
                </form>

                @if($selectedAccount)
                    <form method="POST" action="{{ route('admin.accounting.reconciliations.store') }}">
                        @csrf
                        <input type="hidden" name="account_id" value="{{ $selectedAccount->id }}">
                        <input type="hidden" name="statement_date" value="{{ $statementDate }}">

                        <div class="mb-3">
                            <label class="form-label fw-bold">رصيد الدفتر</label>
                            <input type="text" value="{{ number_format($bookBalance, 2) }}" class="form-control" readonly>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold">رصيد الكشف/الجرد الفعلي</label>
                            <input type="number" step="0.01" name="statement_balance" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold">الفترة المحاسبية</label>
                            <input type="text" value="{{ $period?->name ?? 'لا توجد فترة' }}" class="form-control" readonly>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold">ملاحظات</label>
                            <textarea name="notes" rows="3" class="form-control"></textarea>
                        </div>
                        <button class="btn btn-primary w-100"><i class="bi bi-check2-circle"></i> حفظ المطابقة</button>
                    </form>
                @endif
            </div>
        </div>
    </div>

    <div class="col-lg-8">
        <x-admin.data-panel title="سجل المطابقات" icon="bi-clock-history" :count="$reconciliations->total()">
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead class="bg-light">
                        <tr>
                            <th>التاريخ</th>
                            <th>الحساب</th>
                            <th class="text-end">دفتر</th>
                            <th class="text-end">كشف</th>
                            <th class="text-end">فرق</th>
                            <th>بواسطة</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($reconciliations as $row)
                            <tr>
                                <td>{{ $row->statement_date?->toDateString() }}</td>
                                <td><strong>{{ $row->account?->code }}</strong> - {{ $row->account?->name }}</td>
                                <td class="text-end">{{ \App\Helpers\Money::formatAccounting($row->book_balance) }}</td>
                                <td class="text-end">{{ \App\Helpers\Money::formatAccounting($row->statement_balance) }}</td>
                                <td class="text-end fw-bold {{ abs((float) $row->difference) > 0.01 ? 'text-danger' : 'text-success' }}">
                                    {{ \App\Helpers\Money::formatAccounting($row->difference) }}
                                </td>
                                <td>{{ $row->reconciler?->name ?? '—' }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="text-center text-muted py-4">لا توجد مطابقات بعد.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="mt-3">{{ $reconciliations->links() }}</div>
        </x-admin.data-panel>
    </div>
</div>
@endsection
