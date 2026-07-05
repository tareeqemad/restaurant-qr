<x-admin.data-panel :title="$title" :icon="$icon" :count="$rows->count()">
    <div class="row g-2 mb-3">
        <div class="col-6 col-md">
            <div class="small text-muted fw-bold">0-30</div>
            <strong>{{ \App\Helpers\Money::formatAccounting($totals['current']) }}</strong>
        </div>
        <div class="col-6 col-md">
            <div class="small text-muted fw-bold">31-60</div>
            <strong>{{ \App\Helpers\Money::formatAccounting($totals['31_60']) }}</strong>
        </div>
        <div class="col-6 col-md">
            <div class="small text-muted fw-bold">61-90</div>
            <strong>{{ \App\Helpers\Money::formatAccounting($totals['61_90']) }}</strong>
        </div>
        <div class="col-6 col-md">
            <div class="small text-muted fw-bold">90+</div>
            <strong>{{ \App\Helpers\Money::formatAccounting($totals['over_90']) }}</strong>
        </div>
        <div class="col-12 col-md">
            <div class="small text-muted fw-bold">المجموع</div>
            <strong>{{ \App\Helpers\Money::formatAccounting($totals['total']) }}</strong>
        </div>
    </div>

    <div class="table-responsive">
        <table class="table align-middle mb-0">
            <thead class="bg-light">
                <tr>
                    <th>الرقم</th>
                    <th>الطرف</th>
                    <th>التاريخ</th>
                    <th class="text-end">الأيام</th>
                    <th class="text-end">الرصيد</th>
                </tr>
            </thead>
            <tbody>
                @forelse($rows as $row)
                    <tr>
                        <td class="fw-bold">{{ $row['number'] }}</td>
                        <td>{{ $row['party'] }}</td>
                        <td>{{ $row['date'] }}</td>
                        <td class="text-end">{{ $row['days'] }}</td>
                        <td class="text-end fw-bold">{{ \App\Helpers\Money::formatAccounting($row['amount']) }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="text-center text-muted py-4">لا توجد أرصدة مفتوحة.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @isset($ledgerBalance)
        {{-- Tie-out to the general ledger: invoice-level aging + any AR/AP that
             has no invoice behind it (opening balances / manual entries) must
             equal the account's ledger balance. --}}
        <div class="mt-3 pt-3 border-top small">
            <div class="d-flex justify-content-between">
                <span class="text-muted">إجمالي الأعمار (من الفواتير)</span>
                <strong>{{ \App\Helpers\Money::formatAccounting($totals['total']) }}</strong>
            </div>
            @if(abs($unassigned) > 0.01)
                <div class="d-flex justify-content-between text-warning mt-1">
                    <span><i class="bi bi-exclamation-triangle-fill"></i> أرصدة بلا فواتير (افتتاحية / قيود يدوية)</span>
                    <strong>{{ \App\Helpers\Money::formatAccounting($unassigned) }}</strong>
                </div>
            @endif
            <div class="d-flex justify-content-between border-top mt-1 pt-1 fw-bold">
                <span>{{ $ledgerLabel ?? 'رصيد الحساب في الدفتر' }}</span>
                <span>{{ \App\Helpers\Money::formatAccounting($ledgerBalance) }}</span>
            </div>
            @if(abs($unassigned) > 0.01)
                <div class="text-muted mt-1" style="font-size:.8rem">
                    <i class="bi bi-info-circle"></i>
                    الفرق ذممٌ في الدفتر بلا فاتورة مقابلة (أرصدة افتتاحية أو قيود يدوية) — إظهاره يجعل التقرير مطابقاً لميزان المراجعة.
                </div>
            @endif
        </div>
    @endisset
</x-admin.data-panel>
