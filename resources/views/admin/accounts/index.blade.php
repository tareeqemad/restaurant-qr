@extends('layouts.admin')
@section('title', 'شجرة الحسابات')

@php
    // Build the full chart tree in one shot, grouped by parent.
    // The renderTree closure recurses purely from this in-memory map →
    // zero DB queries during render even with 200+ accounts.
    $byParent = $allAccounts->groupBy(fn ($a) => $a->parent_account_id ?? 0);

    // Type labels for the synthetic top-level wrappers we draw above the
    // real roots. Keeps the tree visually grouped by type without
    // requiring fake parent rows in the DB.
    $typeMeta = [
        'asset'          => ['label' => 'الأصول',           'icon' => 'bi-bank2'],
        'liability'      => ['label' => 'الالتزامات',       'icon' => 'bi-file-earmark-x'],
        'equity'         => ['label' => 'حقوق الملكية',     'icon' => 'bi-shield-check'],
        'revenue'        => ['label' => 'الإيرادات',        'icon' => 'bi-graph-up-arrow'],
        'contra_revenue' => ['label' => 'مقابلات الإيراد',  'icon' => 'bi-arrow-down-right'],
        'expense'        => ['label' => 'المصاريف',         'icon' => 'bi-graph-down'],
    ];
@endphp

@section('content')
<x-admin.breadcrumb
    title="شجرة الحسابات"
    icon="bi-diagram-3-fill"
    subtitle="إعداد نادر للحسابات التي يستخدمها النظام"
    :crumbs="[['label' => 'المحاسبة', 'url' => route('admin.accounting.index')]]" />

{{-- ────────── Toolbar (GFC pattern) ────────── --}}
<div class="card mb-3 acc-toolbar">
    <div class="card-body py-2 d-flex flex-wrap align-items-center gap-2">
        {{-- Permission gates use hasPermission() directly because the
             policy's update/delete methods require an Account instance,
             which we don't have at the toolbar level. --}}
        @if(auth()->user()?->hasPermission('chart_of_accounts.create'))
            <button type="button" class="btn btn-sm btn-success" id="btn-acc-create">
                <i class="bi bi-plus-circle-fill"></i> جديد
            </button>
        @endif
        @if(auth()->user()?->hasPermission('chart_of_accounts.update'))
            <button type="button" class="btn btn-sm btn-primary" id="btn-acc-edit" disabled>
                <i class="bi bi-pencil-square"></i> تحرير
            </button>
            <button type="button" class="btn btn-sm btn-warning" id="btn-acc-toggle" disabled>
                <i class="bi bi-power"></i> <span id="btn-acc-toggle-label">تعطيل/تشغيل</span>
            </button>
        @endif
        @if(auth()->user()?->hasPermission('chart_of_accounts.delete'))
            <button type="button" class="btn btn-sm btn-danger" id="btn-acc-delete" disabled>
                <i class="bi bi-trash-fill"></i> حذف
            </button>
        @endif

        <div class="vr mx-1"></div>

        <button type="button" class="btn btn-sm btn-outline-secondary" id="btn-acc-expand-all">
            <i class="bi bi-arrows-expand"></i> توسيع الكل
        </button>
        <button type="button" class="btn btn-sm btn-outline-secondary" id="btn-acc-collapse-all">
            <i class="bi bi-arrows-collapse"></i> طيّ الكل
        </button>

        @php $inactiveCount = $allAccounts->where('is_active', false)->count(); @endphp
        @if($inactiveCount)
            <div class="form-check form-switch mb-0 ms-1" title="الحسابات المعطّلة مخفية افتراضياً لتبسيط الشجرة. القيود التلقائية تظل تعمل معها.">
                <input class="form-check-input" type="checkbox" id="acc-show-inactive">
                <label class="form-check-label small text-muted" for="acc-show-inactive">
                    إظهار المعطّلة ({{ $inactiveCount }})
                </label>
            </div>
        @endif

        <div class="input-group input-group-sm ms-auto" style="max-width:260px;">
            <span class="input-group-text"><i class="bi bi-search"></i></span>
            <input type="search" id="acc-tree-search" class="form-control"
                   placeholder="ابحث بالكود أو الاسم…">
        </div>
    </div>

    <div class="card-body py-2 border-top small text-muted d-flex justify-content-between">
        <div>
            <i class="bi bi-info-circle"></i>
            <strong>اضغط</strong> على حساب لتحديده، ثم استخدم أزرار التحرير/الحذف بالأعلى.
            <strong>اضغط</strong> أيقونة <i class="bi bi-dash-square text-success"></i> /
            <i class="bi bi-plus-square text-success"></i> لتطوي أو تفتح الفروع.
        </div>
        <div>
            <strong>{{ $allAccounts->where('is_active', true)->count() }}</strong> نشط
            @if($allAccounts->where('is_active', false)->count())
                · <strong>{{ $allAccounts->where('is_active', false)->count() }}</strong> معطّل
            @endif
            · <strong>{{ $allAccounts->where('is_system', true)->count() }}</strong> نظامي
        </div>
    </div>
</div>

{{-- Flash message slot for AJAX feedback (success/error toasts) --}}
<div id="acc-flash" class="mb-2"></div>

{{-- ────────── The tree itself ────────── --}}
<div class="card">
    <div class="card-body p-2">
        <ul class="tree" id="accounts-tree">
            @foreach($typeMeta as $type => $meta)
                @php
                    $typeRoots   = $byParent->get(0, collect())->where('type', $type);
                    $activeCount = $typeRoots->where('is_active', true)->count();
                    $totalCount  = $typeRoots->count();
                @endphp
                @if($typeRoots->isNotEmpty())
                    {{-- Synthetic type wrapper (not an account itself).
                         Marked data-type="header" so the JS skips it for
                         account-level actions like select/edit/delete. --}}
                    <li class="parent_li type-header" data-id="header-{{ $type }}">
                        <span data-type="header"
                              data-count-active="{{ $activeCount }}"
                              data-count-total="{{ $totalCount }}">
                            <i class="bi bi-dash-square"></i>
                            <i class="bi {{ $meta['icon'] }} text-primary mx-1"></i>
                            <strong>{{ $meta['label'] }}</strong>
                            <span class="badge bg-secondary mx-1 type-count">{{ $activeCount }}</span>
                        </span>
                        <ul>
                            @foreach($typeRoots->sortBy('code') as $root)
                                @include('admin.accounts._tree-node', [
                                    'account'   => $root,
                                    'byParent'  => $byParent,
                                ])
                            @endforeach
                        </ul>
                    </li>
                @endif
            @endforeach
        </ul>
    </div>
</div>

{{-- ────────── Single modal: doubles for create + edit ────────── --}}
<div class="modal fade" id="accountModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <form class="modal-content" id="account-form" method="POST">
            @csrf
            <input type="hidden" name="_method_field" id="acc-method" value="POST">
            <input type="hidden" name="parent_account_id" id="acc-parent-id">

            <div class="modal-header bg-light">
                <h5 class="modal-title">
                    <i class="bi bi-diagram-3-fill text-primary"></i>
                    <span id="acc-modal-title">بيانات الحساب</span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>

            <div class="modal-body">
                {{-- System lock notice — only shown when editing a protected account --}}
                <div class="alert alert-warning d-flex align-items-start gap-2 mb-3" id="acc-system-notice" style="display:none!important;">
                    <i class="bi bi-shield-lock-fill fs-4 mt-1"></i>
                    <div class="small">
                        <strong class="d-block">حساب نظامي محمي</strong>
                        النظام يعتمد على كود هذا الحساب في القيود التلقائية.
                        تقدر تعدّل <strong>الاسم والوصف</strong> فقط — الكود والنوع وطبيعة الرصيد مقفولة.
                    </div>
                </div>

                {{-- Parent breadcrumb — shown when adding a child --}}
                <div class="alert alert-success d-flex align-items-center gap-2 mb-3" id="acc-parent-notice" style="display:none!important;">
                    <i class="bi bi-diagram-3-fill"></i>
                    <div>
                        <strong>تحت الحساب الأب:</strong>
                        <code id="acc-parent-label"></code>
                    </div>
                </div>

                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label fw-bold">الكود <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <input type="text" name="code" id="acc-code" class="form-control" required maxlength="32"
                                   placeholder="مثلاً: 5101">
                            {{-- Unlock button: shown only when the code was auto-derived
                                 from a parent. Clicking it lets power users override the
                                 suggested code (rare — but needed for legacy code migrations). --}}
                            <button type="button" class="btn btn-outline-secondary" id="acc-code-unlock"
                                    style="display:none;" title="الكود مقترح من الأب لضمان التسلسل — اضغط للتعديل اليدوي">
                                <i class="bi bi-lock-fill text-success"></i>
                            </button>
                        </div>
                        <small class="text-muted" id="acc-code-hint">رقم فريد. مقفول للحسابات النظامية.</small>
                    </div>
                    <div class="col-md-8">
                        <label class="form-label fw-bold">الاسم <span class="text-danger">*</span></label>
                        <input type="text" name="name" id="acc-name" class="form-control" required maxlength="191"
                               placeholder="مثلاً: مصاريف صيانة">
                    </div>

                    <div class="col-md-4">
                        <label class="form-label fw-bold">النوع <span class="text-danger">*</span></label>
                        <select name="type" id="acc-type" class="form-select" required>
                            <option value="asset">أصل</option>
                            <option value="liability">التزام</option>
                            <option value="equity">حقوق ملكية</option>
                            <option value="revenue">إيراد</option>
                            <option value="contra_revenue">مقابل إيراد</option>
                            <option value="expense">مصروف</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-bold">طبيعة الرصيد <span class="text-danger">*</span></label>
                        <select name="normal_balance" id="acc-normal-balance" class="form-select" required>
                            <option value="debit">مدين</option>
                            <option value="credit">دائن</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">ترتيب العرض</label>
                        <input type="number" name="display_order" id="acc-display-order" class="form-control"
                               min="0" max="65535" value="0">
                    </div>

                    <div class="col-12">
                        <label class="form-label">الوصف (اختياري)</label>
                        <textarea name="description" id="acc-description" rows="2" class="form-control"
                                  placeholder="شرح مختصر متى يستعمل هذا الحساب"></textarea>
                    </div>

                    <div class="col-12" id="acc-active-wrapper">
                        <div class="form-check form-switch fs-5">
                            <input type="hidden" name="is_active" value="0">
                            <input type="checkbox" id="acc-is-active" name="is_active" value="1"
                                   class="form-check-input" checked>
                            <label for="acc-is-active" class="form-check-label fw-bold">الحساب نشط</label>
                        </div>
                    </div>
                </div>

                <div id="acc-errors" class="alert alert-danger mt-3" style="display:none;"></div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">إغلاق</button>
                <button type="submit" class="btn btn-primary" id="acc-submit-btn">
                    <i class="bi bi-check-circle-fill"></i> حفظ
                </button>
            </div>
        </form>
    </div>
</div>

@push('styles')
<style>
    /* ═══════════════════ Tree (gfc-inspired, RTL-aware) ═══════════════════
       The hierarchy is built from THREE visual layers:
         1) Color-banded "type header" rows (الأصول/الالتزامات/…) — distinct band on the right
         2) Solid vertical guide line on each <ul> + dashed horizontal connector per <li>
         3) Depth-aware indentation that grows per level
       All borders use the *inline-start* logical property so they sit on the
       RIGHT side under RTL — matching the visual flow of Arabic text. */

    .tree {
        list-style: none; padding: 0; margin: 0;
        font-size: .92rem; line-height: 1.7;
    }

    /* Every <ul> inside the tree → guide rail + indented padding */
    .tree ul {
        list-style: none;
        margin: 0;
        padding-inline-start: 28px;        /* gap between guide and span */
        position: relative;
    }
    /* Vertical guide line: starts at the top of the ul, stops just past
       the last child's connector so we don't dangle past the last node. */
    .tree ul::before {
        content: '';
        position: absolute;
        top: 6px; bottom: 18px;
        inset-inline-start: 12px;
        border-inline-start: 1.5px dashed rgba(15, 71, 49, .28);
    }
    /* Inside type-header → make the guide more prominent (it's a section root) */
    .tree li.type-header > ul::before {
        border-inline-start: 2px solid rgba(15, 71, 49, .22);
    }

    /* Each <li> ─ position relative so we can draw a connector to it */
    .tree li {
        padding: 3px 0;
        position: relative;
    }
    /* Horizontal connector from the guide rail → the span of THIS li */
    .tree ul > li::before {
        content: '';
        position: absolute;
        top: 18px;                          /* aligns with span vertical center */
        inset-inline-start: -16px;          /* reaches from rail to span */
        width: 16px; height: 1px;
        border-top: 1.5px dashed rgba(15, 71, 49, .28);
    }
    /* Inside type-header: solid connectors for accounts directly under a section */
    .tree li.type-header > ul > li::before { border-top-style: solid; }

    /* Type-header rows DON'T need a connector (they're the section roots) */
    .tree > li.type-header::before,
    .tree > li.type-header > span::before { display: none; }

    /* The span (clickable row) — sits on top of the lines, with white bg
       so the connector visually "stops" at the span edge (looks cleaner). */
    .tree li > span {
        display: inline-flex; align-items: center; gap: 6px;
        padding: 5px 12px;
        border-radius: 8px;
        cursor: pointer; user-select: none;
        background: #fff;
        position: relative;
        z-index: 1;
        transition: background .15s, box-shadow .15s, transform .12s;
    }
    .tree li > span:hover {
        background: rgba(15, 71, 49, .06);
    }
    .tree li > span.selected {
        background: rgba(15, 71, 49, .14);
        font-weight: 700; color: #0f4731;
        box-shadow: 0 0 0 2px rgba(15, 71, 49, .35);
    }

    /* Toggle icon: bigger, color-shifts on hover */
    .tree li > span > i:first-child {
        cursor: pointer;
        color: #16a34a; font-size: 1.05rem;
        transition: transform .15s, color .15s;
    }
    .tree li > span > i:first-child:hover {
        color: #0f4731; transform: scale(1.18);
    }
    .tree li.parent_li.is-collapsed > ul { display: none; }

    /* ═══════════════════ Type-header section (level-0) ═══════════════════ */
    .tree > li.type-header {
        margin-top: 10px;
        padding: 0;
    }
    .tree > li.type-header:first-child { margin-top: 0; }
    .tree > li.type-header > span {
        background: linear-gradient(90deg, rgba(15, 71, 49, .10), rgba(15, 71, 49, .02));
        font-weight: 800;
        font-size: 1rem;
        padding: 9px 16px;
        border-inline-start: 4px solid #16a34a;
        border-radius: 6px;
        width: 100%;
        max-width: 380px;
    }
    .tree > li.type-header > span:hover {
        background: linear-gradient(90deg, rgba(15, 71, 49, .15), rgba(15, 71, 49, .04));
    }
    .tree > li.type-header > span.selected {
        box-shadow: none;
        background: linear-gradient(90deg, rgba(15, 71, 49, .15), rgba(15, 71, 49, .04));
    }

    /* ═══════════════════ Level-2+ depth subtle accent on the rail ═══════════════════
       Every nested ul gets a slightly more muted line color so eyes can
       follow depth: header (solid green) → L1 (dashed strong) → L2+ (dashed light). */
    .tree ul ul::before    { border-inline-start-color: rgba(15, 71, 49, .20); }
    .tree ul ul ul::before { border-inline-start-color: rgba(15, 71, 49, .14); }

    /* ═══════════════════ Pieces inside the row (chip + name + badges) ═══════════════════ */
    .acc-code-chip {
        font-family: 'Courier New', monospace;
        font-weight: 700; color: #0f4731;
        background: rgba(15, 71, 49, .08);
        padding: 2px 8px; border-radius: 4px;
        font-size: .8rem;
        min-width: 48px; text-align: center;
    }
    .acc-name-text { color: #1f2937; font-weight: 500; }
    .acc-meta-text { font-size: .72rem; color: #94a3b8; }
    .acc-badge-sys {
        font-size: .62rem; font-weight: 700;
        background: rgba(99, 102, 241, .12); color: #4338ca;
        padding: 2px 7px; border-radius: 99px;
    }
    .acc-badge-off {
        font-size: .62rem; font-weight: 700;
        background: rgba(220, 38, 38, .10); color: #b91c1c;
        padding: 2px 7px; border-radius: 99px;
    }

    /* Selected node also tints its sub-branch rail (helps eyes track) */
    .tree li > span.selected + ul::before { border-inline-start-color: #0f4731; }

    /* Visual cue: a parent that has children gets a tiny chevron after the code */
    .tree li.parent_li > span > .acc-name-text::after {
        content: '';
        display: inline-block;
        width: 5px; height: 5px;
        border-top: 1.5px solid #94a3b8;
        border-inline-end: 1.5px solid #94a3b8;
        transform: rotate(-45deg);
        margin-inline-start: 8px;
        opacity: .55;
        vertical-align: middle;
    }

    /* Search filter */
    .tree li.is-filtered-out { display: none; }

    /* Hide disabled accounts by default so the chart stays lean for a
       small setup. The "إظهار المعطّلة" switch removes this class. */
    .tree.hide-inactive li.acc-inactive { display: none; }

    /* The toolbar card spacing */
    .acc-toolbar .input-group-text { background: #f8fafc; }
</style>
@endpush

@push('scripts')
<script>
(function () {
    // ═══════════════════ TREE CORE (gfc.tree port) ═══════════════════
    const tree = document.getElementById('accounts-tree');
    if (! tree) return;

    // Disabled accounts are hidden by default → lean chart. The toggle below
    // reveals them (they still post/appear in the trial balance either way).
    tree.classList.add('hide-inactive');
    document.getElementById('acc-show-inactive')?.addEventListener('change', (e) => {
        const showInactive = e.target.checked;
        tree.classList.toggle('hide-inactive', ! showInactive);
        // Keep the per-type count badges honest as rows appear/disappear.
        tree.querySelectorAll('li.type-header > span[data-type="header"]').forEach(h => {
            const badge = h.querySelector('.type-count');
            if (badge) badge.textContent = showInactive ? h.dataset.countTotal : h.dataset.countActive;
        });
    });

    let selected = null;       // currently-selected <span> element

    // Mark every <li> that has children with .parent_li so the toggle
    // icon logic knows whether to draw a triangle. Also collapse all
    // non-root levels by default so the chart opens compact.
    tree.querySelectorAll('li').forEach(li => {
        if (li.querySelector(':scope > ul > li')) {
            li.classList.add('parent_li');
            // Collapse every level beyond the type-header level by default
            // — type headers (li.type-header) stay expanded so the user
            // sees the 6 sections; their children collapse.
            if (! li.classList.contains('type-header')) {
                li.classList.add('is-collapsed');
            }
        }
    });

    // Toggle expand/collapse on the leading icon
    tree.addEventListener('click', (e) => {
        const icon = e.target.closest('li > span > i:first-child');
        if (! icon) return;
        const li = icon.closest('li');
        if (! li || ! li.classList.contains('parent_li')) return;
        e.stopPropagation();
        li.classList.toggle('is-collapsed');
        // Swap the +/- icon
        if (icon.classList.contains('bi-dash-square')) {
            icon.classList.replace('bi-dash-square', 'bi-plus-square');
        } else if (icon.classList.contains('bi-plus-square')) {
            icon.classList.replace('bi-plus-square', 'bi-dash-square');
        }
    });

    // Click span (anywhere except the icon) → select
    tree.addEventListener('click', (e) => {
        if (e.target.closest('li > span > i:first-child')) return;
        const span = e.target.closest('li > span');
        if (! span) return;
        // Don't select type headers (they're just visual wrappers).
        if (span.dataset.type === 'header') return;

        if (selected) selected.classList.remove('selected');
        span.classList.add('selected');
        selected = span;
        updateToolbarState();
    });

    // ═══════════════════ TOOLBAR ═══════════════════
    const btnCreate   = document.getElementById('btn-acc-create');
    const btnEdit     = document.getElementById('btn-acc-edit');
    const btnDelete   = document.getElementById('btn-acc-delete');
    const btnToggle   = document.getElementById('btn-acc-toggle');
    const btnToggleLbl= document.getElementById('btn-acc-toggle-label');
    const btnExpandAll= document.getElementById('btn-acc-expand-all');
    const btnCollapseAll = document.getElementById('btn-acc-collapse-all');

    function updateToolbarState() {
        const hasSelection = !!selected;
        if (btnEdit)   btnEdit.disabled   = ! hasSelection;
        if (btnDelete) btnDelete.disabled = ! hasSelection;
        if (btnToggle) btnToggle.disabled = ! hasSelection;

        if (hasSelection && btnToggleLbl) {
            // Read active state from the span's data attribute
            const isActive = selected.dataset.active === '1';
            btnToggleLbl.textContent = isActive ? 'تعطيل' : 'تشغيل';
        }
    }

    btnExpandAll?.addEventListener('click', () => {
        tree.querySelectorAll('li.parent_li').forEach(li => {
            li.classList.remove('is-collapsed');
            const icon = li.querySelector(':scope > span > i:first-child');
            if (icon) icon.classList.replace('bi-plus-square', 'bi-dash-square');
        });
    });
    btnCollapseAll?.addEventListener('click', () => {
        tree.querySelectorAll('li.parent_li').forEach(li => {
            if (li.classList.contains('type-header')) return;   // keep type headers open
            li.classList.add('is-collapsed');
            const icon = li.querySelector(':scope > span > i:first-child');
            if (icon) icon.classList.replace('bi-dash-square', 'bi-plus-square');
        });
    });

    // ═══════════════════ MODAL (single form, dual purpose) ═══════════════════
    const modalEl = document.getElementById('accountModal');
    const modal   = new bootstrap.Modal(modalEl);
    const form    = document.getElementById('account-form');
    const errBox  = document.getElementById('acc-errors');
    const sysNotice = document.getElementById('acc-system-notice');
    const parentNotice = document.getElementById('acc-parent-notice');
    const parentLabel  = document.getElementById('acc-parent-label');
    const modalTitle   = document.getElementById('acc-modal-title');
    const submitBtn    = document.getElementById('acc-submit-btn');

    // Cache of base form-field elements for quick lock/unlock toggling.
    const fields = {
        code:           document.getElementById('acc-code'),
        name:           document.getElementById('acc-name'),
        type:           document.getElementById('acc-type'),
        normal_balance: document.getElementById('acc-normal-balance'),
        description:    document.getElementById('acc-description'),
        display_order:  document.getElementById('acc-display-order'),
        is_active:      document.getElementById('acc-is-active'),
        parent_account_id: document.getElementById('acc-parent-id'),
        _method:        document.getElementById('acc-method'),
    };

    function resetForm() {
        form.reset();
        errBox.style.display = 'none';
        errBox.innerHTML = '';
        // Re-enable every form field that the system-lock may have
        // disabled on a previous edit cycle.
        Object.values(fields).forEach(f => {
            if (! f) return;
            const tag = f.tagName;
            if (tag === 'INPUT' || tag === 'SELECT' || tag === 'TEXTAREA') {
                f.disabled = false;
            }
        });
        // Clear any visual hints left over from a previous open
        // (auto-suggested green tick, unlock warning, lock state, etc.).
        if (fields.code) {
            fields.code.classList.remove('is-valid', 'border-warning');
            fields.code.readOnly = false;
            fields.code.title = '';
        }
        const unlockBtn = document.getElementById('acc-code-unlock');
        if (unlockBtn) unlockBtn.style.display = 'none';
        const codeHint = document.getElementById('acc-code-hint');
        if (codeHint) codeHint.innerHTML = 'رقم فريد. مقفول للحسابات النظامية.';
        document.getElementById('acc-active-wrapper').style.display = '';
        sysNotice.style.display = 'none';
        parentNotice.style.display = 'none';
        fields._method.value = 'POST';
        fields.parent_account_id.value = '';
    }

    // ── Smart child-code suggestion ──────────────────────────────────
    // When adding a child under a selected parent, infer the next free
    // code by extending the parent's prefix. Three behaviours rolled in:
    //   1) If the parent already has children, pick max(child suffix) + 1
    //      and preserve the suffix WIDTH so 1101→1102 stays 4 chars and
    //      11001→11002 stays 5.
    //   2) If the parent has no children, append "1" — so 1000 → 10001,
    //      1100 → 11001. This makes the hierarchy readable from the code
    //      alone (the canonical accounting convention).
    //   3) If the result collides with ANY existing code in the chart
    //      (could happen if a stray sibling beat us to that slot), bump
    //      forward until we hit a free slot. Bounded to 999 iterations
    //      so a malformed chart can't lock the browser.
    function collectAllCodes() {
        return new Set(
            Array.from(tree.querySelectorAll('li > span[data-code]'))
                .map(s => s.dataset.code)
                .filter(Boolean)
        );
    }
    function suggestChildCode(parentSpan) {
        const parentCode = parentSpan.dataset.code || '';
        if (! parentCode || ! /^\d+$/.test(parentCode)) return '';   // non-numeric parent: skip suggestion

        const parentLi = parentSpan.closest('li');
        const childSpans = parentLi
            ? parentLi.querySelectorAll(':scope > ul > li > span[data-code]')
            : [];
        const childCodes = Array.from(childSpans).map(s => s.dataset.code);

        let suffixWidth, nextNum;
        if (childCodes.length === 0) {
            suffixWidth = 1;
            nextNum = 1;
        } else {
            // Read the suffix from the FIRST child to lock width.
            const firstSuffix = childCodes[0].substring(parentCode.length);
            suffixWidth = Math.max(1, firstSuffix.length);
            const numericSuffixes = childCodes
                .map(c => c.substring(parentCode.length))
                .filter(s => /^\d+$/.test(s))
                .map(Number);
            nextNum = (numericSuffixes.length ? Math.max(...numericSuffixes) : 0) + 1;
        }

        const allCodes = collectAllCodes();
        for (let i = 0; i < 999; i++) {
            const candidate = parentCode + String(nextNum + i).padStart(suffixWidth, '0');
            if (! allCodes.has(candidate)) return candidate;
        }
        return '';   // give up silently — let the user type
    }

    // ── CREATE: open empty modal (pre-fill parent if a node is selected) ──
    btnCreate?.addEventListener('click', () => {
        resetForm();
        modalTitle.textContent = 'إضافة حساب جديد';
        submitBtn.innerHTML = '<i class="bi bi-plus-circle-fill"></i> إنشاء';
        form.action = "{{ route('admin.accounts.store') }}";

        // If a node is selected, treat it as the parent so the new
        // account becomes its child (replicates GFC's behaviour).
        if (selected) {
            const accountId = selected.dataset.id;
            const accountType = selected.dataset.type;
            const accountBalance = selected.dataset.balance;
            const accountLabel = (selected.dataset.code || '') + ' — ' + (selected.dataset.name || '');
            fields.parent_account_id.value = accountId;
            fields.type.value = accountType;
            fields.normal_balance.value = accountBalance;
            parentNotice.style.display = '';
            parentLabel.textContent = accountLabel;

            // Pre-fill the code field with a sensible suggestion AND
            // lock it. Locking enforces hierarchy: a child of 1100 will
            // always start with "1100…" — so the chart stays scannable
            // by code alone. A small unlock button is exposed for the
            // legitimate but rare case of importing legacy codes.
            const suggestedCode = suggestChildCode(selected);
            if (suggestedCode) {
                fields.code.value = suggestedCode;
                fields.code.classList.add('is-valid');
                fields.code.readOnly = true;
                fields.code.title = `الكود مرتبط بكود الأب (${selected.dataset.code}) لضمان التسلسل. اضغط القفل بجانب الحقل للتعديل اليدوي.`;
                document.getElementById('acc-code-unlock').style.display = '';
                document.getElementById('acc-code-hint').innerHTML =
                    `<i class="bi bi-shield-lock-fill text-success"></i> الكود مقفول لضمان التسلسل الهرمي تحت <strong>${selected.dataset.code}</strong>.`;
            }
        }
        modal.show();
        // Auto-focus the NAME field (since code is auto-filled and
        // typically the name is the next thing the user needs to type).
        setTimeout(() => fields.name?.focus(), 250);
    });

    // ── Unlock button: lets power users override the auto-suggested code
    //    (rare case — e.g. migrating from a legacy chart where the
    //    suggested 11001 conflicts with an external system's numbering). ──
    document.getElementById('acc-code-unlock')?.addEventListener('click', function () {
        fields.code.readOnly = false;
        fields.code.classList.remove('is-valid');
        fields.code.classList.add('border-warning');
        this.style.display = 'none';
        document.getElementById('acc-code-hint').innerHTML =
            '<i class="bi bi-exclamation-triangle-fill text-warning"></i> تم فك القفل — تنبه: قد لا يتطابق الكود مع تسلسل الأب.';
        fields.code.focus();
        fields.code.select();
    });

    // ── EDIT: fetch JSON, pre-fill, open ──
    btnEdit?.addEventListener('click', async () => {
        if (! selected) return;
        const id = selected.dataset.id;
        resetForm();
        try {
            const res = await fetch(`{{ url('admin/accounts') }}/${id}/json`, { headers: { 'Accept': 'application/json' }});
            if (! res.ok) throw new Error('فشل تحميل بيانات الحساب');
            const data = await res.json();

            modalTitle.textContent = 'تعديل حساب: ' + data.code + ' — ' + data.name;
            submitBtn.innerHTML = '<i class="bi bi-check-circle-fill"></i> حفظ التعديل';
            form.action = `{{ url('admin/accounts') }}/${id}`;
            fields._method.value = 'PATCH';

            fields.code.value = data.code;
            fields.name.value = data.name;
            fields.type.value = data.type;
            fields.normal_balance.value = data.normal_balance;
            fields.description.value = data.description || '';
            fields.display_order.value = data.display_order;
            fields.is_active.checked = !! data.is_active;
            fields.parent_account_id.value = data.parent_account_id || '';

            // System lock — fields stay editable for name/description, lock rest
            if (data.is_system) {
                sysNotice.style.display = '';
                fields.code.disabled = true;
                fields.type.disabled = true;
                fields.normal_balance.disabled = true;
                document.getElementById('acc-active-wrapper').style.display = 'none';
            }
            if (data.parent_label) {
                parentNotice.style.display = '';
                parentLabel.textContent = data.parent_label;
            }
            modal.show();
        } catch (err) {
            showFlash('error', err.message);
        }
    });

    // ── DELETE: confirm + ajax ──
    btnDelete?.addEventListener('click', async () => {
        if (! selected) return;
        if (! confirm('هل تريد فعلاً حذف هذا الحساب؟')) return;
        const id = selected.dataset.id;
        try {
            const res = await fetch(`{{ url('admin/accounts') }}/${id}`, {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': '{{ csrf_token() }}',
                    'X-HTTP-Method-Override': 'DELETE',
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });
            const data = await res.json();
            if (! res.ok) { showFlash('error', data.message || 'فشل الحذف'); return; }
            // Remove from DOM (faster than reload + UX continuity)
            selected.closest('li').remove();
            selected = null;
            updateToolbarState();
            showFlash('success', data.message);
        } catch (err) {
            showFlash('error', err.message);
        }
    });

    // ── TOGGLE active/inactive ──
    btnToggle?.addEventListener('click', async () => {
        if (! selected) return;
        const id = selected.dataset.id;
        try {
            const fd = new FormData();
            fd.append('_token', '{{ csrf_token() }}');
            fd.append('_method', 'PATCH');
            const res = await fetch(`{{ url('admin/accounts') }}/${id}/toggle`, {
                method: 'POST', body: fd,
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            });
            // The toggle endpoint redirects in non-ajax — accept either status.
            if (res.redirected || res.ok) {
                window.location.reload();
            } else {
                showFlash('error', 'تعذر تغيير الحالة. تأكد أنه ليس حساباً نظامياً.');
            }
        } catch (err) { showFlash('error', err.message); }
    });

    // ── FORM SUBMIT (create OR update via AJAX) ──
    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        errBox.style.display = 'none';
        errBox.innerHTML = '';

        const fd = new FormData(form);
        if (fields._method.value === 'PATCH') fd.append('_method', 'PATCH');

        try {
            const res = await fetch(form.action, {
                method: 'POST',
                body: fd,
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            });
            const data = await res.json();
            if (! res.ok) {
                // Validation errors
                const msgs = data.errors ? Object.values(data.errors).flat() : [data.message || 'فشل الحفظ'];
                errBox.innerHTML = msgs.map(m => `<div>• ${m}</div>`).join('');
                errBox.style.display = '';
                return;
            }
            modal.hide();
            showFlash('success', data.message);
            // Easiest reliable refresh = reload (re-renders tree with new node in place).
            setTimeout(() => window.location.reload(), 500);
        } catch (err) {
            errBox.innerHTML = '<div>تعذّر الاتصال بالخادم.</div>';
            errBox.style.display = '';
        }
    });

    // ═══════════════════ SEARCH (Arabic-aware) ═══════════════════
    function normalize(s) {
        if (! s) return '';
        return String(s).toLowerCase()
            .replace(/[ً-ٰٟ]/g, '')
            .replace(/[إأآا]/g, 'ا')
            .replace(/ة/g, 'ه')
            .replace(/ى/g, 'ي').trim();
    }
    document.getElementById('acc-tree-search')?.addEventListener('input', (e) => {
        const needle = normalize(e.target.value);
        tree.querySelectorAll('li').forEach(li => {
            const span = li.querySelector(':scope > span');
            if (! span) return;
            const text = normalize(span.textContent);
            li.dataset.selfMatch = (! needle || text.includes(needle)) ? '1' : '0';
        });
        // Bottom-up: a node is visible if itself matches OR any descendant matches.
        Array.from(tree.querySelectorAll('li')).reverse().forEach(li => {
            const selfMatch  = li.dataset.selfMatch === '1';
            const childMatch = !! li.querySelector('li:not(.is-filtered-out)');
            const visible    = ! needle || selfMatch || childMatch;
            li.classList.toggle('is-filtered-out', ! visible);
            if (needle && visible) li.classList.remove('is-collapsed');   // auto-expand to show matches
        });
    });

    // ═══════════════════ FLASH ═══════════════════
    function showFlash(type, message) {
        const flash = document.getElementById('acc-flash');
        const klass = type === 'success' ? 'alert-success' : 'alert-danger';
        const icon  = type === 'success' ? 'bi-check-circle-fill' : 'bi-exclamation-triangle-fill';
        flash.innerHTML = `
            <div class="alert ${klass} d-flex align-items-center gap-2 mb-0" role="alert">
                <i class="bi ${icon}"></i>
                <div>${message}</div>
                <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"></button>
            </div>`;
        setTimeout(() => { flash.innerHTML = ''; }, 4500);
    }
})();
</script>
@endpush
@endsection
