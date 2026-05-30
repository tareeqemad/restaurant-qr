{{-- ═══════════════════════════════════════════════════════════════════
     PERMISSION TREE — reusable partial.

     Used from TWO places:
       1) admin.users.edit (inline below the user-edit form fields)
       2) admin.permissions.index (standalone management page)

     Required input variables:
       $user            — App\Models\User (the user whose perms we render)
       $permissionTree  — collection of grouped permissions (Permissions::tree(…))
       $rolePermIds     — array of permission ids granted by the user's role
       $userPermIds     — array of permission ids currently effective for this user
       $isOwnerLevel    — bool, true for super_admin/owner (skips tree, shows alert)

     Optional input variables:
       $showWrapperCard — bool, default true; wrap in the .user-perms card.
                          Set to false when the parent page already wraps in a card.

     Form-name contract:
       Every <input type="checkbox"> is named `permissions[]` so a normal
       form submit posts the checked ids. The backend's syncUserPermissions()
       diffs them against the role's defaults to compute grants/revokes.
═══════════════════════════════════════════════════════════════════ --}}

@if($isOwnerLevel ?? false)
    {{-- Owner-level users always get every permission via the isOwnerLevel()
         bypass in User::hasPermission(). Showing 119 ticked-and-locked
         checkboxes would just clutter the page. --}}
    <div class="alert alert-info d-flex align-items-center gap-2">
        <i class="bi bi-shield-lock-fill fs-4"></i>
        <div>
            هذا المستخدم بدور <strong>{{ $user->role }}</strong> — يملك كل الصلاحيات تلقائياً
            دون الحاجة لشجرة استثناءات.
        </div>
    </div>
@else
    @php
        $rolePerms    = collect($rolePermIds ?? []);
        $effective    = collect(old('permissions', $userPermIds ?? []))->map(fn($v) => (int) $v);
        $effectiveSet = $effective->flip();      // O(1) lookup
        $rolePermsSet = $rolePerms->flip();
        $showWrapper  = $showWrapperCard ?? true;
    @endphp

    <div class="user-perms {{ $showWrapper ? '' : 'user-perms--bare' }}" x-data="userPermissions({{ $effective->count() }})" x-init="init()">
        {{-- Header (optional — hidden when the parent page has its own breadcrumb) --}}
        @if($showWrapper)
            <div class="branch-assign__head">
                <i class="bi bi-shield-lock-fill"></i>
                <div class="flex-grow-1">
                    <div class="branch-assign__title">
                        شجرة صلاحيات هذا المستخدم
                        <span class="badge bg-light text-dark border ms-2" x-text="count + ' مفعّلة'"></span>
                    </div>
                    <div class="branch-assign__hint">
                        الصلاحيات المفعّلة تلقائياً من دور <strong>{{ $user->role ?? '—' }}</strong>
                        معلَّمة بشارة «من الدور». تعديلها هنا يُسجَّل كاستثناء على هذا المستخدم فقط:
                        <ul class="mb-0 mt-1" style="padding-inline-start: 1.2rem;">
                            <li>✅ <strong>منح إضافي</strong> = صلاحية ما عنده الدور أصلاً، وأنت بتمنحه ياها</li>
                            <li>🚫 <strong>إلغاء من الدور</strong> = صلاحية الدور بيمنحها، وأنت بتمنعها عنه</li>
                        </ul>
                    </div>
                </div>
            </div>
        @endif

        {{-- ────────── Toolbar (GFC pattern) ────────── --}}
        <div class="perm-toolbar {{ $showWrapper ? 'mt-3' : '' }}">
            <div class="perm-toolbar-title">
                <i class="bi bi-shield-lock-fill"></i>
                إدارة الصلاحيات
            </div>
            <div class="perm-search" :class="{ 'has-value': query.length > 0 }">
                <input type="search" x-ref="search"
                       @input="query = $event.target.value; filter()"
                       placeholder="ابحث في الصلاحيات…" autocomplete="off">
                <i class="bi bi-search"></i>
                <button type="button" class="perm-search-clear"
                        @click="$refs.search.value=''; query=''; filter()" aria-label="مسح">
                    <i class="bi bi-x-circle-fill"></i>
                </button>
            </div>
            <div class="perm-toolbar-actions">
                <button type="button" @click="expandAll()" title="فتح كل المجموعات">
                    <i class="bi bi-arrows-expand"></i> توسيع الكل
                </button>
                <button type="button" @click="collapseAll()" title="طيّ كل المجموعات">
                    <i class="bi bi-arrows-collapse"></i> طيّ الكل
                </button>
                <button type="button" @click="resetToRole()" title="أعد ضبط الصلاحيات لتطابق الدور الافتراضي">
                    <i class="bi bi-arrow-counterclockwise"></i> إعادة للدور
                </button>
            </div>
        </div>

        {{-- Summary bar — counts deviations from role default at a glance. --}}
        <div class="perm-summary">
            <div class="perm-summary-item">
                <span class="dot dot-role"></span>
                <span>من الدور:</span> <strong id="perm-count-role">0</strong>
            </div>
            <div class="perm-summary-item">
                <span class="dot dot-extra"></span>
                <span>منح إضافي:</span> <strong id="perm-count-extra">0</strong>
            </div>
            <div class="perm-summary-item">
                <span class="dot dot-revoked"></span>
                <span>ملغاة:</span> <strong id="perm-count-revoked">0</strong>
            </div>
        </div>

        {{-- ────────── The tree ────────── --}}
        <ul class="ptree" id="user-perm-tree">
            @foreach($permissionTree as $group)
                @php
                    $groupIds  = collect($group['permissions'])->pluck('id')->all();
                    $checkedIn = collect($groupIds)->filter(fn ($id) => $effectiveSet->has($id))->count();
                    $totalIn   = count($groupIds);
                    $allChecked = $checkedIn === $totalIn && $checkedIn > 0;
                @endphp
                <li class="parent_li {{ $allChecked ? 'is-all-checked' : '' }}"
                    data-group="{{ $group['key'] }}" data-group-label="{{ $group['label'] }}">
                    <span data-key="{{ $group['key'] }}" class="ptree-group-row">
                        <i class="bi bi-dash-square ptree-toggle"></i>
                        <input type="checkbox" class="group-toggle form-check-input"
                               @if($allChecked) checked @endif
                               @click.stop="toggleGroup($event, '{{ $group['key'] }}')">
                        <span class="ptree-group-icon"><i class="bi {{ $group['icon'] }}"></i></span>
                        <span class="ptree-group-label">{{ $group['label'] }}</span>
                        <span class="ptree-group-count">
                            <span class="group-selected-count">{{ $checkedIn }}</span> / {{ $totalIn }}
                        </span>
                    </span>
                    <ul>
                        @foreach($group['permissions'] as $p)
                            @php
                                $fromRole  = $rolePermsSet->has($p['id']);
                                $isChecked = $effectiveSet->has($p['id']);
                                $extra     = $isChecked && ! $fromRole;
                                $revoked   = ! $isChecked && $fromRole;
                            @endphp
                            <li class="ptree-perm {{ $extra ? 'is-extra' : '' }} {{ $revoked ? 'is-revoked' : '' }}"
                                data-label="{{ $p['label'] }}" data-name="{{ $p['name'] }}">
                                <span>
                                    <i class="bi bi-circle-fill ptree-leaf-dot"></i>
                                    <input type="checkbox" class="perm-check form-check-input"
                                           data-group="{{ $group['key'] }}"
                                           data-from-role="{{ $fromRole ? '1' : '0' }}"
                                           id="perm-cb-{{ $p['id'] }}"
                                           name="permissions[]" value="{{ $p['id'] }}"
                                           @checked($isChecked)>
                                    <label for="perm-cb-{{ $p['id'] }}" class="ptree-perm-label">{{ $p['label'] }}</label>
                                    @if($fromRole)
                                        <span class="perm-badge perm-badge--role" title="ممنوحة بشكل افتراضي من دور المستخدم">
                                            <i class="bi bi-shield-fill-check"></i> من الدور
                                        </span>
                                    @endif
                                    <span class="perm-badge perm-badge--extra" style="{{ $extra ? '' : 'display:none;' }}"
                                          title="منح إضافي لهذا المستخدم خارج دوره">
                                        <i class="bi bi-plus-circle-fill"></i> إضافة
                                    </span>
                                    <span class="perm-badge perm-badge--revoked" style="{{ $revoked ? '' : 'display:none;' }}"
                                          title="ملغاة استثناءً من دور المستخدم">
                                        <i class="bi bi-slash-circle-fill"></i> ملغاة
                                    </span>
                                </span>
                            </li>
                        @endforeach
                    </ul>
                </li>
            @endforeach
        </ul>

        <div class="perm-no-results" id="user-perm-no-results" style="display:none;">
            <i class="bi bi-search"></i>
            <p class="mt-2 mb-0">لا توجد صلاحية مطابقة للبحث</p>
        </div>
    </div>

    @once
        @push('styles')
        <style>
            /* ═══════════════════ Permission-tree (GFC style, RTL-aware) ═══════════════════
               Pushed once-only because both edit-form and standalone page
               include this partial — but the styles only need to load a
               single time per page (handled by the @@once wrapper above). */
            .user-perms {
                background: #f8fafc;
                border: 1px solid rgba(0,0,0,.06);
                border-radius: 12px;
                padding: 1rem;
            }
            /* On the standalone page the parent card already provides the
               wrapper, so suppress the duplicate border/background. */
            .user-perms.user-perms--bare {
                background: transparent;
                border: 0;
                padding: 0;
            }
            .user-perms .branch-assign__head { margin-bottom: 0; }

            /* ─── Toolbar ─── */
            .user-perms .perm-toolbar {
                display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center;
                gap: 12px; padding: .7rem .9rem;
                background: linear-gradient(135deg, rgba(15,71,49,.06) 0%, rgba(34,197,94,.04) 100%);
                border: 1px solid rgba(15,71,49,.12);
                border-radius: 10px; margin-bottom: .75rem;
            }
            .user-perms .perm-toolbar-title { font-weight: 800; display: flex; align-items: center; gap: 8px; color: #0f4731; }
            .user-perms .perm-search { position: relative; flex: 1 1 240px; max-width: 320px; }
            .user-perms .perm-search input {
                width: 100%; padding: 6px 36px 6px 10px;
                border: 1px solid rgba(15,71,49,.18); border-radius: 8px;
                font-size: .85rem; background: #fff; font-family: inherit;
            }
            .user-perms .perm-search .bi-search {
                position: absolute; inset-inline-end: 10px; top: 50%;
                transform: translateY(-50%); color: #9ca3af; pointer-events: none;
            }
            .user-perms .perm-search-clear {
                position: absolute; inset-inline-end: 8px; top: 50%;
                transform: translateY(-50%); border: 0; background: transparent;
                color: #9ca3af; cursor: pointer; display: none; padding: 4px;
            }
            .user-perms .perm-search.has-value .bi-search { display: none; }
            .user-perms .perm-search.has-value .perm-search-clear { display: block; }
            .user-perms .perm-toolbar-actions { display: flex; gap: 6px; flex-wrap: wrap; }
            .user-perms .perm-toolbar-actions button {
                border: 1px solid rgba(15,71,49,.18); background: #fff;
                padding: 4px 10px; border-radius: 7px; font-size: .76rem; font-weight: 700;
                color: #0f4731; cursor: pointer; transition: all .12s;
            }
            .user-perms .perm-toolbar-actions button:hover { background: #0f4731; color: #fff; }

            /* ─── Summary bar ─── */
            .user-perms .perm-summary {
                display: flex; flex-wrap: wrap; gap: 16px;
                padding: .55rem .9rem; margin-bottom: .9rem;
                background: #fff; border: 1px solid rgba(15,71,49,.10);
                border-radius: 10px; font-size: .82rem;
            }
            .user-perms .perm-summary-item { display: inline-flex; align-items: center; gap: 6px; }
            .user-perms .perm-summary-item .dot {
                width: 10px; height: 10px; border-radius: 50%; display: inline-block;
            }
            .user-perms .perm-summary-item .dot-role    { background: #0f4731; }
            .user-perms .perm-summary-item .dot-extra   { background: #10b981; }
            .user-perms .perm-summary-item .dot-revoked { background: #ef4444; }

            /* ═══════════════════ The tree (ptree namespace) ═══════════════════ */
            .ptree { list-style: none; padding: 0; margin: 0; font-size: .9rem; line-height: 1.7; }
            .ptree ul {
                list-style: none; margin: 0;
                padding-inline-start: 28px;
                position: relative;
            }
            .ptree ul::before {
                content: '';
                position: absolute; top: 6px; bottom: 14px;
                inset-inline-start: 12px;
                border-inline-start: 1.5px dashed rgba(15,71,49,.25);
            }
            .ptree li { padding: 3px 0; position: relative; }
            .ptree ul > li::before {
                content: '';
                position: absolute;
                top: 18px;
                inset-inline-start: -16px;
                width: 16px; height: 1px;
                border-top: 1.5px dashed rgba(15,71,49,.25);
            }
            .ptree li > span {
                display: inline-flex; align-items: center; gap: 8px;
                padding: 5px 10px;
                border-radius: 7px;
                background: #fff;
                position: relative; z-index: 1;
                max-width: 100%;
            }
            .ptree .ptree-toggle {
                cursor: pointer;
                color: #16a34a; font-size: 1rem;
                transition: transform .15s, color .15s;
            }
            .ptree .ptree-toggle:hover { color: #0f4731; transform: scale(1.15); }
            .ptree li.parent_li.is-collapsed > ul { display: none; }

            .ptree > li.parent_li > span.ptree-group-row {
                background: linear-gradient(90deg, rgba(15,71,49,.08), rgba(15,71,49,.02));
                font-weight: 700;
                border-inline-start: 3px solid #16a34a;
                padding: 7px 12px;
                min-width: 360px;
            }
            .ptree > li.parent_li.is-all-checked > span.ptree-group-row {
                background: linear-gradient(90deg, rgba(34,197,94,.18), rgba(34,197,94,.04));
                border-inline-start-color: #22c55e;
            }
            .ptree .ptree-group-icon {
                width: 28px; height: 28px; border-radius: 6px;
                background: linear-gradient(135deg, #0f4731, #22c55e);
                color: #fff; display: inline-flex; align-items: center; justify-content: center;
                font-size: .85rem; flex-shrink: 0;
            }
            .ptree .ptree-group-label { font-weight: 800; font-size: .92rem; color: #1f2937; }
            .ptree .ptree-group-count {
                font-size: .72rem; font-weight: 700;
                background: rgba(0,0,0,.05); color: #6b7280;
                padding: 2px 9px; border-radius: 99px;
                margin-inline-start: auto;
            }
            .ptree > li.parent_li.is-all-checked .ptree-group-count {
                background: #22c55e; color: #fff;
            }

            .ptree li.ptree-perm > span { padding-block: 4px; }
            .ptree .ptree-leaf-dot {
                font-size: .35rem; color: #cbd5e1;
                margin: 0 4px;
            }
            .ptree .ptree-perm-label {
                font-size: .85rem; color: #374151;
                font-weight: 500; flex: 1;
                margin-bottom: 0; cursor: pointer;
            }
            .ptree li.ptree-perm.is-extra > span { background: rgba(16,185,129,.06); }
            .ptree li.ptree-perm.is-revoked > span { background: rgba(239,68,68,.06); }
            .ptree li.ptree-perm.is-revoked .ptree-perm-label { text-decoration: line-through; opacity: .65; }

            .ptree .form-check-input {
                margin: 0; cursor: pointer;
                width: 17px; height: 17px;
                accent-color: #0f4731;
            }
            .ptree .form-check-input:indeterminate { background-color: #facc15; border-color: #facc15; }

            .perm-badge {
                font-size: .66rem; font-weight: 700;
                padding: 2px 8px; border-radius: 99px;
                display: inline-flex; align-items: center; gap: 4px;
                white-space: nowrap;
            }
            .perm-badge--role    { background: rgba(15,71,49,.10);   color: #0f4731; }
            .perm-badge--extra   { background: rgba(16,185,129,.14); color: #047857; }
            .perm-badge--revoked { background: rgba(239,68,68,.14);  color: #b91c1c; }

            .ptree li.is-filtered-out { display: none; }

            .user-perms .perm-no-results {
                padding: 1.5rem; text-align: center; color: #9ca3af;
                background: #fff; border: 1px dashed #d1d5db; border-radius: 10px;
            }

            @media (max-width: 640px) {
                .ptree > li.parent_li > span.ptree-group-row { min-width: 0; flex-wrap: wrap; }
            }
        </style>
        @endpush

        @push('scripts')
        <script src="https://cdn.jsdelivr.net/npm/alpinejs@3.14.1/dist/cdn.min.js" defer></script>
        <script>
        function userPermissions(initialCount) {
            return {
                count: initialCount,
                query: '',

                init() {
                    const tree = document.getElementById('user-perm-tree');
                    if (! tree) return;

                    // Expand/collapse via the leading icon on the group row.
                    tree.addEventListener('click', (e) => {
                        const tog = e.target.closest('.ptree-toggle');
                        if (! tog) return;
                        e.stopPropagation();
                        const li = tog.closest('li.parent_li');
                        if (! li) return;
                        li.classList.toggle('is-collapsed');
                        if (tog.classList.contains('bi-dash-square')) {
                            tog.classList.replace('bi-dash-square', 'bi-plus-square');
                        } else {
                            tog.classList.replace('bi-plus-square', 'bi-dash-square');
                        }
                    });

                    document.querySelectorAll('.user-perms .perm-check').forEach(cb => {
                        cb.addEventListener('change', () => {
                            this.refreshRowState(cb);
                            this.syncGroupState(cb.dataset.group);
                            this.recount();
                        });
                    });

                    document.querySelectorAll('.ptree > li.parent_li').forEach(g =>
                        this.syncGroupState(g.dataset.group));
                    this.recount();
                },

                refreshRowState(cb) {
                    const row = cb.closest('li.ptree-perm');
                    if (! row) return;
                    const fromRole  = cb.dataset.fromRole === '1';
                    const isExtra   = cb.checked && ! fromRole;
                    const isRevoked = ! cb.checked && fromRole;
                    row.classList.toggle('is-extra',   isExtra);
                    row.classList.toggle('is-revoked', isRevoked);

                    const extra   = row.querySelector('.perm-badge--extra');
                    const revoked = row.querySelector('.perm-badge--revoked');
                    if (extra)   extra.style.display   = isExtra   ? '' : 'none';
                    if (revoked) revoked.style.display = isRevoked ? '' : 'none';
                },

                normalize(s) {
                    try {
                        if (! s) return '';
                        return String(s).toLowerCase()
                            .replace(/[ً-ٰٟ]/g, '')
                            .replace(/[إأآا]/g, 'ا')
                            .replace(/ة/g, 'ه')
                            .replace(/ى/g, 'ي').trim();
                    } catch (e) { return String(s || '').toLowerCase().trim(); }
                },

                filter() {
                    const needle = this.normalize(this.query);
                    let anyVisible = false;
                    document.querySelectorAll('.ptree > li.parent_li').forEach(group => {
                        const groupLabel = this.normalize(group.dataset.groupLabel || '');
                        const groupMatches = !! needle && groupLabel.includes(needle);
                        let rowsVisible = 0;
                        group.querySelectorAll('li.ptree-perm').forEach(row => {
                            const rowText = this.normalize((row.dataset.label || '') + ' ' + (row.dataset.name || ''));
                            const matches = ! needle || groupMatches || rowText.includes(needle);
                            row.classList.toggle('is-filtered-out', ! matches);
                            if (matches) rowsVisible++;
                        });
                        const hideGroup = !! needle && rowsVisible === 0;
                        group.classList.toggle('is-filtered-out', hideGroup);
                        if (! hideGroup) anyVisible = true;
                        if (needle) {
                            group.classList.remove('is-collapsed');
                            const tog = group.querySelector(':scope > span > .ptree-toggle');
                            if (tog && tog.classList.contains('bi-plus-square')) {
                                tog.classList.replace('bi-plus-square', 'bi-dash-square');
                            }
                        }
                    });
                    const empty = document.getElementById('user-perm-no-results');
                    if (empty) empty.style.display = (!! needle && ! anyVisible) ? '' : 'none';
                },

                recount() {
                    let total = 0, role = 0, extra = 0, revoked = 0;
                    document.querySelectorAll('.user-perms .perm-check').forEach(cb => {
                        const fromRole = cb.dataset.fromRole === '1';
                        if (cb.checked) {
                            total++;
                            if (fromRole) role++;
                            else extra++;
                        } else if (fromRole) {
                            revoked++;
                        }
                    });
                    this.count = total;
                    const r = document.getElementById('perm-count-role');
                    const e = document.getElementById('perm-count-extra');
                    const v = document.getElementById('perm-count-revoked');
                    if (r) r.textContent = role;
                    if (e) e.textContent = extra;
                    if (v) v.textContent = revoked;
                },

                toggleGroup(ev, key) {
                    const checked = ev.target.checked;
                    document.querySelectorAll(`.ptree > li.parent_li[data-group="${key}"] .perm-check`)
                        .forEach(cb => {
                            cb.checked = checked;
                            this.refreshRowState(cb);
                        });
                    this.syncGroupState(key);
                    this.recount();
                },

                syncGroupState(key) {
                    const group = document.querySelector(`.ptree > li.parent_li[data-group="${key}"]`);
                    if (! group) return;
                    const boxes   = group.querySelectorAll('.perm-check');
                    const checked = group.querySelectorAll('.perm-check:checked');
                    const master  = group.querySelector('.group-toggle');
                    const counter = group.querySelector('.group-selected-count');
                    const allChecked = checked.length === boxes.length && boxes.length > 0;
                    if (master) {
                        master.checked = allChecked;
                        master.indeterminate = checked.length > 0 && ! allChecked;
                    }
                    if (counter) counter.textContent = checked.length;
                    group.classList.toggle('is-all-checked', allChecked);
                },

                resetToRole() {
                    document.querySelectorAll('.user-perms .perm-check').forEach(cb => {
                        cb.checked = cb.dataset.fromRole === '1';
                        this.refreshRowState(cb);
                    });
                    document.querySelectorAll('.ptree > li.parent_li').forEach(g =>
                        this.syncGroupState(g.dataset.group));
                    this.recount();
                },

                expandAll() {
                    document.querySelectorAll('.ptree > li.parent_li').forEach(g => {
                        g.classList.remove('is-collapsed');
                        const tog = g.querySelector(':scope > span > .ptree-toggle');
                        if (tog && tog.classList.contains('bi-plus-square')) {
                            tog.classList.replace('bi-plus-square', 'bi-dash-square');
                        }
                    });
                },
                collapseAll() {
                    document.querySelectorAll('.ptree > li.parent_li').forEach(g => {
                        g.classList.add('is-collapsed');
                        const tog = g.querySelector(':scope > span > .ptree-toggle');
                        if (tog && tog.classList.contains('bi-dash-square')) {
                            tog.classList.replace('bi-dash-square', 'bi-plus-square');
                        }
                    });
                },
            };
        }
        </script>
        @endpush
    @endonce
@endif
