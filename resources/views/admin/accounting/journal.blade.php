@extends('layouts.admin')

@section('title', 'دفتر القيود')

@section('content')
<x-admin.breadcrumb
    title="دفتر القيود"
    icon="bi-journal-text"
    subtitle="مراجعة العمليات المحاسبية المسجلة تلقائياً"
    :crumbs="[['label' => 'المحاسبة', 'url' => route('admin.accounting.index')]]">
    @if(auth()->user()?->hasPermission('chart_of_accounts.create'))
        <x-slot:actions>
            <a href="{{ route('admin.accounting.manual-entry.create') }}" class="btn btn-primary">
                <i class="bi bi-plus-lg"></i> قيد يدوي
            </a>
        </x-slot:actions>
    @endif
</x-admin.breadcrumb>

@php
    $filtersActive = request()->hasAny(['from', 'to', 'event_type', 'search']);
@endphp
<details class="card mb-3" @if($filtersActive) open @endif>
    <summary class="card-header accounting-filter-summary">
        <span><i class="bi bi-funnel me-2"></i>بحث وتصفية</span>
        @if($filtersActive)
            <span class="badge bg-primary">مفعّلة</span>
        @else
            <small>اختياري</small>
        @endif
    </summary>
    <div class="card-body">
        <form class="row g-2 align-items-end" method="GET">
            <div class="col-md-2">
                <label class="form-label small text-muted fw-bold">من</label>
                <input type="date" name="from" value="{{ $from }}" class="form-control">
            </div>
            <div class="col-md-2">
                <label class="form-label small text-muted fw-bold">إلى</label>
                <input type="date" name="to" value="{{ $to }}" class="form-control">
            </div>
            <div class="col-md-3">
                <label class="form-label small text-muted fw-bold">نوع العملية</label>
                <select name="event_type" class="form-select">
                    <option value="">الكل</option>
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
                <button class="btn btn-primary"><i class="bi bi-search"></i> عرض</button>
            </div>
        </form>
    </div>
</details>

<x-admin.data-panel title="القيود" icon="bi-journal-text" :count="$entries->total()">
    @if($entries->isEmpty())
        <x-admin.empty-state
            icon="bi-journal-text"
            title="لا توجد قيود"
            message="لا توجد قيود محاسبية ضمن الفترة المختارة." />
    @else
        <div class="table-responsive">
            <table class="table align-middle accounting-journal-table mb-0">
                <thead class="bg-light">
                    <tr>
                        <th>رقم القيد</th>
                        <th>التاريخ</th>
                        <th>العملية</th>
                        <th class="text-end">القيمة</th>
                        <th class="text-end">إجراء</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($entries as $entry)
                        @php
                            $debit = (float) $entry->lines->sum(fn ($line) => (float) $line->debit);
                            $eventLabel = $eventLabels[$entry->event_type] ?? str_replace('_', ' ', (string) $entry->event_type);
                            $isReversed = in_array((int) $entry->id, $reversedEntryIds ?? [], true);
                            $isClosingEntry = in_array($entry->event_type, [
                                'period_closing', 'fiscal_year_closing',
                                'period_closing_reversal', 'fiscal_year_closing_reversal',
                            ], true);
                        @endphp
                        <tr>
                            <td class="fw-bold text-primary">{{ $entry->entry_no }}</td>
                            <td>{{ optional($entry->posted_on)->format('Y-m-d') }}</td>
                            <td>
                                <div class="fw-bold">{{ $entry->description }}</div>
                                <div class="d-flex flex-wrap gap-1 mt-1">
                                    <span class="badge bg-primary-transparent text-primary">{{ $eventLabel }}</span>
                                    @if($entry->branch?->name)
                                        <span class="badge bg-light text-dark border">{{ $entry->branch->name }}</span>
                                    @endif
                                    @if($isReversed)
                                        <span class="badge bg-warning-transparent text-warning">معكوس</span>
                                    @endif
                                </div>
                                <details class="journal-details mt-2">
                                    <summary>عرض التفاصيل</summary>
                                    <div class="journal-lines mt-2">
                                        @foreach($entry->lines->sortBy('line_no') as $line)
                                            <div class="journal-line">
                                                <span><strong>{{ $line->account?->code }}</strong> — {{ $line->account?->name }}</span>
                                                <span class="text-muted">مدين {{ \App\Helpers\Money::formatAccounting($line->debit) }} · دائن {{ \App\Helpers\Money::formatAccounting($line->credit) }}</span>
                                            </div>
                                        @endforeach
                                    </div>
                                </details>
                            </td>
                            <td class="text-end fw-bold">{{ \App\Helpers\Money::formatAccounting($debit) }}</td>
                            <td class="text-end">
                                @if(auth()->user()?->hasPermission('chart_of_accounts.create') && ! $isReversed && ! $isClosingEntry)
                                    <a href="{{ route('admin.accounting.journal.adjust.create', $entry) }}" class="btn btn-sm btn-outline-warning">
                                        <i class="bi bi-arrow-counterclockwise"></i> تصحيح
                                    </a>
                                @elseif($isClosingEntry)
                                    <span class="badge bg-light text-muted border"><i class="bi bi-lock-fill"></i> إقفال</span>
                                @else
                                    <span class="text-muted">—</span>
                                @endif
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
.accounting-filter-summary {
    display: flex;
    align-items: center;
    justify-content: space-between;
    cursor: pointer;
    font-weight: 800;
    list-style: none;
}
.accounting-filter-summary::-webkit-details-marker { display: none; }
.accounting-filter-summary small { color: #64748b; font-weight: 500; }
.accounting-journal-table th,
.accounting-journal-table td { white-space: nowrap; }
.accounting-journal-table td:nth-child(3) { min-width: 320px; white-space: normal; }
.journal-details summary { color: var(--primary); cursor: pointer; font-size: .82rem; font-weight: 700; }
.journal-lines {
    display: grid;
    gap: .4rem;
    padding: .65rem;
    border-radius: 8px;
    background: #f8fafc;
}
.journal-line {
    display: flex;
    justify-content: space-between;
    gap: 1rem;
    font-size: .82rem;
}
@media (max-width: 767.98px) {
    .journal-line { flex-direction: column; gap: .15rem; }
}
</style>
@endpush
@endsection
