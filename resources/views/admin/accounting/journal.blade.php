@extends('layouts.admin')

@section('title', 'القيود اليومية')

@section('content')
<x-admin.breadcrumb
    title="القيود اليومية"
    icon="bi-journal-text"
    subtitle="سجل الترحيل المحاسبي لكل فاتورة ودفعة ومصروف ومورد"
    :crumbs="[['label' => 'الحسابات', 'url' => route('admin.cashier.index')]]"
/>

<x-admin.data-panel title="القيود اليومية" icon="bi-journal-text" :count="$entries->total()">
    <x-slot:filters>
        <form class="row g-2 align-items-end" method="GET">
            <div class="col-md-2">
                <label class="form-label small text-muted fw-bold">من تاريخ</label>
                <input type="date" name="from" value="{{ $from }}" class="form-control">
            </div>
            <div class="col-md-2">
                <label class="form-label small text-muted fw-bold">إلى تاريخ</label>
                <input type="date" name="to" value="{{ $to }}" class="form-control">
            </div>
            <div class="col-md-3">
                <label class="form-label small text-muted fw-bold">نوع القيد</label>
                <select name="event_type" class="form-select">
                    <option value="">كل القيود</option>
                    @foreach($eventTypes as $eventType)
                        <option value="{{ $eventType }}" @selected($selectedEvent === $eventType)>
                            {{ $eventLabels[$eventType] ?? str_replace('_', ' ', $eventType) }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label small text-muted fw-bold">بحث</label>
                <input type="search" name="search" value="{{ $search }}" class="form-control" placeholder="رقم القيد أو الوصف">
            </div>
            <div class="col-md-2 d-grid">
                <button class="btn btn-primary">
                    <i class="bi bi-search"></i>
                    استعلام
                </button>
            </div>
        </form>
    </x-slot:filters>

    @if($entries->isEmpty())
        <x-admin.empty-state
            icon="bi-journal-text"
            title="لا توجد قيود"
            message="لا توجد قيود محاسبية ضمن الفلاتر المختارة." />
    @else
        <div class="table-responsive">
            <table class="table align-middle accounting-journal-table mb-0">
                <thead class="bg-light">
                    <tr>
                        <th>القيد</th>
                        <th>التاريخ</th>
                        <th>الفرع</th>
                        <th>النوع</th>
                        <th>الوصف</th>
                        <th class="text-end">مدين</th>
                        <th class="text-end">دائن</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($entries as $entry)
                        @php
                            $debit = (float) $entry->lines->sum(fn ($line) => (float) $line->debit);
                            $credit = (float) $entry->lines->sum(fn ($line) => (float) $line->credit);
                            $eventLabel = $eventLabels[$entry->event_type] ?? str_replace('_', ' ', (string) $entry->event_type);
                        @endphp
                        <tr>
                            <td class="fw-bold text-primary">{{ $entry->entry_no }}</td>
                            <td>{{ optional($entry->posted_on)->format('Y-m-d') }}</td>
                            <td>{{ $entry->branch?->name ?? 'كل الفروع' }}</td>
                            <td><span class="badge bg-primary-transparent text-primary">{{ $eventLabel }}</span></td>
                            <td>
                                <div class="fw-bold">{{ $entry->description }}</div>
                                <small class="text-muted">{{ class_basename($entry->source_type) }} #{{ $entry->source_id }}</small>
                            </td>
                            <td class="text-end fw-bold">{{ \App\Helpers\Money::format($debit) }}</td>
                            <td class="text-end fw-bold">{{ \App\Helpers\Money::format($credit) }}</td>
                        </tr>
                        <tr class="accounting-lines-row">
                            <td colspan="7">
                                <div class="journal-lines-grid">
                                    @foreach($entry->lines->sortBy('line_no') as $line)
                                        <div class="journal-line-item">
                                            <div class="journal-line-account">
                                                <span>{{ $line->account?->code }}</span>
                                                <strong>{{ $line->account?->name }}</strong>
                                            </div>
                                            <div class="journal-line-description">{{ $line->description ?: $entry->description }}</div>
                                            <div class="journal-line-amounts">
                                                <span>مدين: {{ \App\Helpers\Money::format($line->debit) }}</span>
                                                <span>دائن: {{ \App\Helpers\Money::format($line->credit) }}</span>
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <x-slot:footer>
            {{ $entries->links() }}
        </x-slot:footer>
    @endif
</x-admin.data-panel>

@push('styles')
<style>
.accounting-journal-table th,
.accounting-journal-table td {
    white-space: nowrap;
}
.accounting-lines-row td {
    background: rgba(var(--primary-rgb), .025);
    padding-top: .75rem;
    padding-bottom: .75rem;
}
.journal-lines-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
    gap: .5rem;
}
.journal-line-item {
    border: 1px solid rgba(var(--primary-rgb), .12);
    border-radius: 8px;
    background: #fff;
    padding: .75rem;
    display: grid;
    gap: .4rem;
}
.journal-line-account {
    display: flex;
    align-items: center;
    gap: .5rem;
}
.journal-line-account span {
    color: var(--primary);
    font-weight: 900;
}
.journal-line-account strong {
    color: #111827;
}
.journal-line-description,
.journal-line-amounts {
    color: #64748b;
    font-size: .82rem;
}
.journal-line-amounts {
    display: flex;
    justify-content: space-between;
    gap: .75rem;
}
</style>
@endpush
@endsection
