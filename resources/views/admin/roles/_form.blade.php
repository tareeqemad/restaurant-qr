@csrf
@php
    $r = $role ?? null;
    $selected = $r ? $r->permissions->pluck('id')->toArray() : [];
    $selected = old('permissions', $selected);
    $tree = \App\Helpers\Permissions::tree($permissions);
@endphp

@push('styles')
<style>
    /* ═══════════════ Role form ═══════════════ */
    .role-meta { background: #f8fafc; border: 1px solid rgba(0,0,0,.06); border-radius: 12px; padding: 1rem; }
    .role-meta label { font-weight: 700; font-size: .85rem; color: #374151; margin-bottom: .4rem; }

    /* ═══════════════ Permissions tree ═══════════════ */
    .perm-toolbar {
        display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center;
        gap: 12px; padding: .85rem 1rem;
        background: linear-gradient(135deg, rgba(var(--primary-rgb),.06) 0%, rgba(var(--accent-rgb),.04) 100%);
        border: 1px solid rgba(var(--primary-rgb),.12);
        border-radius: 12px; margin-bottom: 1rem;
    }
    .perm-toolbar-title { font-weight: 800; display: flex; align-items: center; gap: 8px; color: rgb(var(--primary-rgb)); }

    /* Search bar */
    .perm-search {
        position: relative;
        flex: 1 1 260px;
        max-width: 360px;
    }
    .perm-search input {
        width: 100%;
        padding: 7px 38px 7px 12px;
        border: 1px solid rgba(var(--primary-rgb),.18);
        border-radius: 10px;
        font-size: .85rem;
        background: #fff;
        font-family: inherit;
        transition: border-color .12s, box-shadow .12s;
    }
    .perm-search input:focus {
        outline: none;
        border-color: rgb(var(--accent-rgb));
        box-shadow: 0 0 0 3px rgba(var(--accent-rgb),.18);
    }
    .perm-search .bi-search {
        position: absolute;
        inset-inline-end: 12px;
        top: 50%;
        transform: translateY(-50%);
        color: #9ca3af;
        pointer-events: none;
        font-size: .9rem;
    }
    .perm-search-clear {
        position: absolute;
        inset-inline-end: 10px;
        top: 50%;
        transform: translateY(-50%);
        border: 0; background: transparent;
        color: #9ca3af;
        cursor: pointer;
        display: none;
        padding: 4px;
        line-height: 1;
    }
    .perm-search.has-value .bi-search { display: none; }
    .perm-search.has-value .perm-search-clear { display: block; }
    .perm-search.has-value .perm-search-clear:hover { color: #ef4444; }

    /* When filtering, non-matching rows + empty groups hide smoothly */
    .perm-row.is-filtered-out { display: none; }
    .perm-group.is-filtered-out { display: none; }
    .perm-row mark {
        background: rgba(var(--accent-rgb),.25);
        color: inherit;
        padding: 0 2px;
        border-radius: 3px;
    }
    .perm-no-results {
        grid-column: 1 / -1;
        padding: 2rem 1rem;
        text-align: center;
        color: #9ca3af;
        background: #f8fafc;
        border: 1px dashed #d1d5db;
        border-radius: 12px;
    }
    .perm-no-results i { font-size: 2rem; opacity: .4; }
    .perm-toolbar-counter {
        background: rgb(var(--primary-rgb)); color: #fff;
        border-radius: 99px; padding: 2px 10px; font-size: .75rem; font-weight: 800;
        font-variant-numeric: tabular-nums;
    }
    .perm-toolbar-actions { display: flex; gap: 6px; }
    .perm-toolbar-actions button {
        border: 1px solid rgba(var(--primary-rgb),.18); background: #fff;
        padding: 4px 12px; border-radius: 8px; font-size: .78rem; font-weight: 700;
        color: rgb(var(--primary-rgb)); cursor: pointer; transition: all .12s;
    }
    .perm-toolbar-actions button:hover { background: rgb(var(--primary-rgb)); color: #fff; }

    .perm-tree { display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 12px; }

    .perm-group {
        background: #fff;
        border: 1.5px solid rgba(var(--primary-rgb),.12);
        border-radius: 12px;
        overflow: hidden;
        transition: border-color .15s, box-shadow .15s;
    }
    .perm-group:hover { border-color: rgba(var(--accent-rgb),.45); box-shadow: 0 4px 14px rgba(var(--primary-rgb),.06); }
    .perm-group.is-all-checked { border-color: rgb(var(--accent-rgb)); background: rgba(var(--accent-rgb),.03); }

    .perm-group-head {
        display: flex; align-items: center; gap: 10px;
        padding: .7rem .9rem;
        background: rgba(var(--primary-rgb),.04);
        border-bottom: 1px solid rgba(var(--primary-rgb),.08);
        cursor: pointer; user-select: none;
    }
    .perm-group-head:hover { background: rgba(var(--primary-rgb),.07); }
    .perm-group-head .chev { transition: transform .15s; color: #9ca3af; margin-inline-start: auto; }
    .perm-group.is-collapsed .chev { transform: rotate(-90deg); }
    .perm-group.is-collapsed .perm-group-body { display: none; }

    .perm-group-icon {
        width: 32px; height: 32px; border-radius: 8px;
        background: linear-gradient(135deg, rgb(var(--primary-rgb)) 0%, rgb(var(--accent-rgb)) 100%);
        color: #fff; display: inline-flex; align-items: center; justify-content: center;
        font-size: .95rem; flex-shrink: 0;
    }
    .perm-group-title { font-weight: 800; font-size: .92rem; color: #1f2937; }
    .perm-group-count {
        font-size: .7rem; font-weight: 700; color: #6b7280;
        background: rgba(0,0,0,.04); border-radius: 99px; padding: 1px 8px;
        font-variant-numeric: tabular-nums;
    }
    .perm-group.is-all-checked .perm-group-count { background: rgb(var(--accent-rgb)); color: #fff; }

    .perm-group-body { padding: .5rem .35rem; }
    .perm-row {
        display: flex; align-items: center; gap: 10px;
        padding: .45rem .6rem; border-radius: 8px;
        cursor: pointer; transition: background .12s;
    }
    .perm-row:hover { background: rgba(var(--primary-rgb),.04); }
    .perm-row input[type="checkbox"] {
        width: 17px; height: 17px; accent-color: rgb(var(--primary-rgb));
        cursor: pointer; flex-shrink: 0;
    }
    .perm-row-label { font-size: .86rem; color: #374151; font-weight: 500; }
    .perm-row input:checked ~ .perm-row-label { color: rgb(var(--primary-rgb)); font-weight: 700; }

    /* "Select all in group" checkbox on the header */
    .perm-group-head input[type="checkbox"] {
        width: 17px; height: 17px; accent-color: rgb(var(--accent-rgb));
        cursor: pointer;
    }

    .perm-save-bar {
        position: sticky; bottom: 0;
        background: #fff; border-top: 1px solid #e5e7eb;
        margin: 1.25rem -1rem -1rem; padding: .85rem 1rem;
        display: flex; gap: 10px; justify-content: flex-end;
        z-index: 2;
    }

    @media (max-width: 640px) {
        .perm-tree { grid-template-columns: 1fr; }
    }
</style>
@endpush

{{-- ═══════════════════ Meta (name / label / order) ═══════════════════ --}}
<div class="role-meta mb-3">
    <div class="row g-3">
        <div class="col-md-4">
            <label>اسم المعرّف (بالإنجليزية) *</label>
            <input name="name" value="{{ old('name', $r?->name) }}" class="form-control" required
                   placeholder="مثلاً: cashier_supervisor">
            <small class="text-muted">معرّف داخلي — أحرف إنجليزية وشرطات سفلية فقط.</small>
        </div>
        <div class="col-md-4">
            <label>الاسم الظاهر *</label>
            <input name="label" value="{{ old('label', $r?->label) }}" class="form-control" required
                   placeholder="مثلاً: مشرف كاشير">
        </div>
        <div class="col-md-4">
            <label>ترتيب العرض</label>
            <input type="number" name="display_order" value="{{ old('display_order', $r?->display_order ?? 0) }}" class="form-control">
        </div>
        <div class="col-12">
            <label>الوصف</label>
            <textarea name="description" class="form-control" rows="2"
                      placeholder="وصف مختصر للدور ومن يستخدمه — اختياري">{{ old('description', $r?->description) }}</textarea>
        </div>
    </div>
</div>

{{-- ═══════════════════ Permissions tree ═══════════════════ --}}
<div x-data="rolePermissions({{ count($selected) }})" x-init="init()">

    {{-- Toolbar: counter + search + global actions --}}
    <div class="perm-toolbar">
        <div class="perm-toolbar-title">
            <i class="bi bi-shield-check"></i>
            الصلاحيات
            <span class="perm-toolbar-counter" x-text="count + ' مختارة'"></span>
        </div>

        <div class="perm-search" :class="{ 'has-value': query.length > 0 }">
            {{-- Direct @input binding + $event.target.value — no race with
                 x-model + debounce that was silently swallowing keystrokes. --}}
            <input type="search"
                   x-ref="search"
                   @input="query = $event.target.value; filter()"
                   placeholder="ابحث في الصلاحيات…"
                   autocomplete="off">
            <i class="bi bi-search"></i>
            <button type="button" class="perm-search-clear"
                    @click="$refs.search.value=''; query=''; filter()"
                    aria-label="مسح البحث">
                <i class="bi bi-x-circle-fill"></i>
            </button>
        </div>

        <div class="perm-toolbar-actions">
            <button type="button" @click="checkAll(true)"><i class="bi bi-check-all"></i> تحديد الكل</button>
            <button type="button" @click="checkAll(false)"><i class="bi bi-x-lg"></i> إلغاء الكل</button>
            <button type="button" @click="toggleCollapse()">
                <i class="bi bi-arrows-collapse"></i>
                <span x-text="allCollapsed ? 'عرض الكل' : 'طيّ الكل'"></span>
            </button>
        </div>
    </div>

    {{-- Tree of groups --}}
    <div class="perm-tree">
        @foreach($tree as $group)
            @php
                $groupIds     = collect($group['permissions'])->pluck('id')->all();
                $allInGroup   = count($groupIds) > 0 && count(array_intersect($groupIds, $selected)) === count($groupIds);
            @endphp
            <div class="perm-group {{ $allInGroup ? 'is-all-checked' : '' }}"
                 data-group="{{ $group['key'] }}"
                 data-group-label="{{ $group['label'] }}"
                 data-group-ids="{{ implode(',', $groupIds) }}">

                <label class="perm-group-head" @click.stop="">
                    <span class="perm-group-icon"><i class="bi {{ $group['icon'] }}"></i></span>
                    <span>
                        <div class="perm-group-title">{{ $group['label'] }}</div>
                    </span>
                    <span class="perm-group-count">
                        <span class="group-selected-count">{{ count(array_intersect($groupIds, $selected)) }}</span>
                        /
                        {{ count($group['permissions']) }}
                    </span>
                    <input type="checkbox" class="group-toggle"
                           @click.stop="toggleGroup($event, '{{ $group['key'] }}')"
                           @if($allInGroup) checked @endif>
                    <i class="bi bi-chevron-down chev"
                       @click.stop="collapseGroup($event.currentTarget.closest('.perm-group'))"></i>
                </label>

                <div class="perm-group-body">
                    @foreach($group['permissions'] as $p)
                        <label class="perm-row" data-label="{{ $p['label'] }}" data-name="{{ $p['name'] }}">
                            <input type="checkbox"
                                   class="perm-check"
                                   data-group="{{ $group['key'] }}"
                                   name="permissions[]"
                                   value="{{ $p['id'] }}"
                                   @checked(in_array($p['id'], $selected))>
                            <span class="perm-row-label">{{ $p['label'] }}</span>
                        </label>
                    @endforeach
                </div>
            </div>
        @endforeach

        {{-- Shown only when the search filter matches nothing. --}}
        <div class="perm-no-results" id="perm-no-results" style="display:none;">
            <i class="bi bi-search"></i>
            <p class="mt-2 mb-0">لا توجد صلاحية مطابقة للبحث</p>
        </div>
    </div>

    {{-- Save bar --}}
    <div class="perm-save-bar">
        <a href="{{ route('admin.roles.index') }}" class="btn btn-light">إلغاء</a>
        <button class="btn btn-primary">
            <i class="bi bi-check-circle-fill"></i> حفظ
        </button>
    </div>
</div>

@push('scripts')
{{-- Admin layout doesn't ship Alpine by default (only Livewire pages bundle it).
     Load it once here so @click / @input / x-data all actually run. `defer`
     so it waits for the DOM before initialising. --}}
<script src="https://cdn.jsdelivr.net/npm/alpinejs@3.14.1/dist/cdn.min.js" defer></script>
<script>
function rolePermissions(initialCount) {
    return {
        count: initialCount,
        allCollapsed: false,
        query: '',

        init() {
            document.querySelectorAll('.perm-check').forEach(cb => {
                cb.addEventListener('change', () => {
                    this.syncGroupState(cb.dataset.group);
                    this.recount();
                });
            });
            document.querySelectorAll('.perm-group').forEach(g => this.syncGroupState(g.dataset.group));
        },

        /** Normalize Arabic text for search: lowercase + strip tashkeel +
            unify alef/yeh/taa forms. So "المنيو" matches "منيو", "منيُو" etc.
            Wrapped in try/catch — if a regex ever fails on exotic input we
            still want the filter to function. */
        normalize(s) {
            try {
                if (! s) return '';
                return String(s)
                    .toLowerCase()
                    .replace(/[\u064B-\u065F\u0670]/g, '')   // tashkeel
                    .replace(/[إأآا]/g, 'ا')                  // unify alef
                    .replace(/ة/g, 'ه')                       // taa marbouta
                    .replace(/ى/g, 'ي')                       // alef maksura
                    .trim();
            } catch (e) {
                return String(s || '').toLowerCase().trim();
            }
        },

        /** Live filter — hides rows/groups that don't match the search.
            No innerHTML highlight (was unreliable with Arabic normalization
            length changes). Uses classList.toggle only — safe and fast. */
        filter() {
            const needle = this.normalize(this.query);
            let anyVisible = false;

            document.querySelectorAll('.perm-group').forEach(group => {
                const groupLabel = this.normalize(group.dataset.groupLabel || '');
                const groupMatches = !!needle && groupLabel.includes(needle);
                let rowsVisible = 0;

                group.querySelectorAll('.perm-row').forEach(row => {
                    const rowText  = this.normalize((row.dataset.label || '') + ' ' + (row.dataset.name || ''));
                    const matches  = ! needle || groupMatches || rowText.includes(needle);
                    row.classList.toggle('is-filtered-out', ! matches);
                    if (matches) rowsVisible++;
                });

                const hideGroup = !!needle && rowsVisible === 0;
                group.classList.toggle('is-filtered-out', hideGroup);
                if (! hideGroup) anyVisible = true;

                // Auto-expand groups while filtering so matches are visible
                if (needle) group.classList.remove('is-collapsed');
            });

            const empty = document.getElementById('perm-no-results');
            if (empty) empty.style.display = (!!needle && ! anyVisible) ? '' : 'none';
        },

        recount() {
            this.count = document.querySelectorAll('.perm-check:checked').length;
        },

        /** Toggle every checkbox in a group from the header's master checkbox. */
        toggleGroup(ev, groupKey) {
            const checked = ev.target.checked;
            document.querySelectorAll(`.perm-check[data-group="${groupKey}"]`)
                .forEach(cb => { cb.checked = checked; });
            this.syncGroupState(groupKey);
            this.recount();
        },

        /** Keep the group's master checkbox + "is-all-checked" class + counter in sync. */
        syncGroupState(groupKey) {
            const group = document.querySelector(`.perm-group[data-group="${groupKey}"]`);
            if (!group) return;
            const boxes = group.querySelectorAll('.perm-check');
            const checked = group.querySelectorAll('.perm-check:checked');
            const master = group.querySelector('.group-toggle');
            const counter = group.querySelector('.group-selected-count');

            if (master) {
                master.checked = checked.length === boxes.length && boxes.length > 0;
                master.indeterminate = checked.length > 0 && checked.length < boxes.length;
            }
            if (counter) counter.textContent = checked.length;
            group.classList.toggle('is-all-checked', checked.length === boxes.length && boxes.length > 0);
        },

        checkAll(value) {
            document.querySelectorAll('.perm-check').forEach(cb => { cb.checked = value; });
            document.querySelectorAll('.perm-group').forEach(g => this.syncGroupState(g.dataset.group));
            this.recount();
        },

        collapseGroup(groupEl) {
            groupEl.classList.toggle('is-collapsed');
        },

        toggleCollapse() {
            this.allCollapsed = !this.allCollapsed;
            document.querySelectorAll('.perm-group').forEach(g => {
                g.classList.toggle('is-collapsed', this.allCollapsed);
            });
        },
    };
}
</script>
@endpush
