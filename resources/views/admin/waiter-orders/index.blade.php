@extends('layouts.admin')
@section('title', 'الطاولات — الجرسون')

@section('content')
<x-admin.breadcrumb
    title="الطاولات"
    icon="bi-grid-3x3-gap-fill"
    subtitle="اضغط على طاولة لبدء أو متابعة طلب — كل العمليات من هنا" />

<style>
    /* ── Filter / search bar ─────────────────────────── */
    .wt-toolbar {
        display: flex; flex-wrap: wrap; gap: .5rem; align-items: center;
        margin-bottom: 1rem;
    }
    .wt-search { flex: 1 1 220px; max-width: 320px; position: relative; }
    .wt-search input {
        width: 100%; padding: .55rem .9rem .55rem 2.2rem;
        border: 1px solid rgba(15,71,49,.18); border-radius: 10px; font-size: 14px;
    }
    .wt-search i {
        position: absolute; inset-inline-start: .75rem; top: 50%;
        transform: translateY(-50%); color: #9ca3af;
    }
    .wt-chips { display: flex; flex-wrap: wrap; gap: .4rem; }
    .wt-chip {
        border: 1px solid rgba(15,71,49,.18); background: #fff;
        border-radius: 999px; padding: .4rem .85rem;
        font-size: 13px; font-weight: 600; color: #2f4f3f;
        cursor: pointer; transition: all .12s ease; user-select: none;
        display: inline-flex; align-items: center; gap: .35rem;
    }
    .wt-chip .n {
        background: rgba(15,71,49,.08); border-radius: 999px;
        padding: 0 .45rem; font-size: 11.5px; min-width: 1.4em; text-align: center;
    }
    .wt-chip:hover { border-color: rgba(15,71,49,.4); }
    .wt-chip.active { background: #0f4731; color: #fff; border-color: #0f4731; }
    .wt-chip.active .n { background: rgba(255,255,255,.22); }
    .wt-chip[data-filter="ready"].active { background: #b91c1c; border-color: #b91c1c; }
    .wt-chip[data-filter="available"].active { background: #047857; border-color: #047857; }

    /* ── Dense table grid ────────────────────────────── */
    .wt-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(118px, 1fr));
        gap: .6rem;
    }
    .wt-card {
        position: relative;
        border: 2px solid var(--st-border, #e2e8f0);
        background: var(--st-bg, #fff);
        border-radius: 12px;
        padding: .7rem .5rem .6rem;
        cursor: pointer;
        transition: transform .1s ease, box-shadow .1s ease;
        text-decoration: none;
        display: flex; flex-direction: column; align-items: center; gap: .15rem;
        min-height: 104px;
    }
    .wt-card:hover { transform: translateY(-2px); box-shadow: 0 6px 16px rgba(15,71,49,.12); }
    .wt-card .num { font-size: 1.7rem; font-weight: 900; line-height: 1; color: #0f4731; }
    .wt-card .nm  { font-size: 11px; color: #6b7280; line-height: 1.1; }
    .wt-card .cap { font-size: 10.5px; color: #9ca3af; }
    .wt-state {
        font-size: 11px; font-weight: 700; border-radius: 999px;
        padding: .1rem .5rem; margin-top: .15rem;
    }
    .wt-card.s-available { --st-border:#10b981; --st-bg:#f0fdf4; }
    .wt-card.s-available .wt-state { background:#d1fae5; color:#047857; }
    .wt-card.s-occupied  { --st-border:#f59e0b; --st-bg:#fffbeb; }
    .wt-card.s-occupied  .wt-state { background:#fef3c7; color:#b45309; }
    .wt-card.s-ready     { --st-border:#ef4444; --st-bg:#fef2f2; }
    .wt-card.s-ready     .wt-state { background:#fee2e2; color:#b91c1c; }
    .wt-card.s-reserved  { --st-border:#3b82f6; --st-bg:#eff6ff; }
    .wt-card.s-reserved  .wt-state { background:#dbeafe; color:#1d4ed8; }
    .wt-card.s-disabled  { --st-border:#e2e8f0; --st-bg:#f8fafc; opacity:.55; cursor:not-allowed; }
    .wt-card.s-disabled  .wt-state { background:#e5e7eb; color:#6b7280; }
    .wt-ready-badge {
        position: absolute; top: -7px; inset-inline-end: -7px;
        background: #b91c1c; color: #fff; font-size: 11px; font-weight: 800;
        border-radius: 999px; min-width: 20px; height: 20px;
        display: flex; align-items: center; justify-content: center;
        box-shadow: 0 2px 5px rgba(185,28,28,.4); padding: 0 5px;
    }
    .wt-empty { text-align:center; padding:3rem 1rem; color:#9ca3af; grid-column:1/-1; }
</style>

<div class="wt-toolbar">
    <div class="wt-search">
        <i class="bi bi-search"></i>
        <input type="text" id="wt-search" placeholder="ابحث برقم أو اسم الطاولة..." autocomplete="off" inputmode="search">
    </div>
    <div class="wt-chips" id="wt-chips">
        <span class="wt-chip active" data-filter="all">الكل <span class="n">{{ $counts['all'] }}</span></span>
        <span class="wt-chip" data-filter="ready">جاهز للتقديم <span class="n">{{ $counts['ready'] }}</span></span>
        <span class="wt-chip" data-filter="occupied">مشغولة <span class="n">{{ $counts['occupied'] }}</span></span>
        <span class="wt-chip" data-filter="available">متاحة <span class="n">{{ $counts['available'] }}</span></span>
        @if($counts['reserved'])
            <span class="wt-chip" data-filter="reserved">محجوزة <span class="n">{{ $counts['reserved'] }}</span></span>
        @endif
    </div>
</div>

<div class="wt-grid" id="wt-grid">
    @forelse($tables as $t)
        @php
            $state = $t->service_state;
            $stateLabel = match ($state) {
                'available' => 'متاحة',
                'occupied'  => 'مشغولة',
                'ready'     => 'جاهز',
                'reserved'  => 'محجوزة',
                'disabled'  => 'معطّلة',
                default     => $state,
            };
            $isDisabled = $state === 'disabled';
        @endphp
        <a class="wt-card s-{{ $state }}"
           data-state="{{ $state }}"
           data-search="{{ $t->number }} {{ $t->name }}"
           @if(! $isDisabled) href="{{ route('admin.waiter-orders.create', $t) }}" @endif>
            @if($t->ready_count > 0)
                <span class="wt-ready-badge" title="{{ $t->ready_count }} طلب جاهز للتقديم">{{ $t->ready_count }}</span>
            @endif
            <span class="num">{{ $t->number }}</span>
            @if($t->name)<span class="nm">{{ $t->name }}</span>@endif
            <span class="cap"><i class="bi bi-people"></i> {{ $t->capacity }}</span>
            <span class="wt-state">{{ $stateLabel }}</span>
        </a>
    @empty
        <div class="wt-empty">
            <i class="bi bi-table fs-1 d-block mb-2"></i>
            لا توجد طاولات مفعّلة. اطلب من المدير إضافة طاولات.
        </div>
    @endforelse
</div>

<div class="wt-empty d-none" id="wt-no-results">
    <i class="bi bi-search fs-1 d-block mb-2"></i>
    ما في طاولة مطابقة.
</div>

@push('scripts')
<script>
(function () {
    const search = document.getElementById('wt-search');
    const chips  = document.getElementById('wt-chips');
    const cards  = Array.from(document.querySelectorAll('#wt-grid .wt-card'));
    const noRes  = document.getElementById('wt-no-results');
    let activeFilter = 'all';

    function apply() {
        const q = (search.value || '').trim().toLowerCase();
        let visible = 0;
        cards.forEach(card => {
            const matchFilter = activeFilter === 'all' || card.dataset.state === activeFilter;
            const matchSearch = ! q || (card.dataset.search || '').toLowerCase().includes(q);
            const show = matchFilter && matchSearch;
            card.style.display = show ? '' : 'none';
            if (show) visible++;
        });
        noRes.classList.toggle('d-none', visible > 0);
    }

    search.addEventListener('input', apply);
    chips.addEventListener('click', (e) => {
        const chip = e.target.closest('.wt-chip');
        if (! chip) return;
        chips.querySelectorAll('.wt-chip').forEach(c => c.classList.remove('active'));
        chip.classList.add('active');
        activeFilter = chip.dataset.filter;
        apply();
    });
})();
</script>
@endpush
@endsection
