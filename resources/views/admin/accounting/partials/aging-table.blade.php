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
</x-admin.data-panel>
