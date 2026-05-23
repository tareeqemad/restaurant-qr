@csrf

{{-- ─── Quick guide on creating a user ─────────────────────────────
     Three required steps shown in order. Removed in May 2026 was a
     two-card panel explaining the difference between "system role"
     and "per-branch role override" — the override was retired so
     only one role concept remains, and the panel is now obsolete. --}}
<div class="user-quick-guide mb-3">
    <div class="user-quick-guide__icon">
        <i class="bi bi-lightbulb-fill"></i>
    </div>
    <div class="user-quick-guide__body">
        <strong>كيف تنشئ مستخدماً؟</strong>
        ١) عبّي بياناته الأساسية ←
        ٢) اختر <b>هويّته في النظام</b> (كاشير/مدير/شيف…) ←
        ٣) علّم <b>الفرع/الفروع</b> اللي يعمل فيها.
        المستخدم يدخل تلقائياً للفرع الأساسي، ويرى فقط الفروع المعيَّن إليها.
    </div>
</div>

<div class="row g-3">
    <div class="col-md-6"><label class="form-label">الاسم *</label>
        <input type="text" name="name" value="{{ old('name', $user->name ?? '') }}" class="form-control" required></div>
    <div class="col-md-6"><label class="form-label">الاسم بالإنجليزية</label>
        <input type="text" name="name_en" value="{{ old('name_en', $user->name_en ?? '') }}" class="form-control"></div>
    <div class="col-md-4"><label class="form-label">اسم المستخدم *</label>
        <input type="text" name="username" value="{{ old('username', $user->username ?? '') }}" class="form-control" required></div>
    <div class="col-md-4"><label class="form-label">البريد الإلكتروني</label>
        <input type="email" name="email" value="{{ old('email', $user->email ?? '') }}" class="form-control"></div>
    <div class="col-md-4"><label class="form-label">الهاتف</label>
        <input type="text" name="phone" value="{{ old('phone', $user->phone ?? '') }}" class="form-control"></div>

    <div class="col-md-4">
        <label class="form-label d-flex align-items-center gap-1">
            الدور <span class="text-danger">*</span>
            <small class="text-muted ms-1"
                   title="دور المستخدم في النظام: مدير، كاشير، شيف… يحدد الصلاحيات الافتراضية في كل فروعه.">
                <i class="bi bi-info-circle"></i>
            </small>
        </label>
        {{-- Empty default placeholder so we never accidentally save with the
             first role auto-selected (used to default to "super_admin" in the
             worst case). User MUST consciously pick a role; HTML5 + server-side
             `required` rule both reject submission with no value. --}}
        <select name="role"
                class="form-select @error('role') is-invalid @enderror"
                required>
            <option value="" @selected(old('role', $user->role ?? '') === '')>
                — اختر الدور —
            </option>
            @foreach($roles as $v => $label)
                <option value="{{ $v }}" @selected(old('role', $user->role ?? '') === $v)>
                    {{ $label }}
                </option>
            @endforeach
        </select>
        @error('role')
            <div class="invalid-feedback d-block">
                <i class="bi bi-exclamation-triangle-fill"></i>
                {{ $message }}
            </div>
        @enderror
        <small class="text-muted d-block mt-1" style="font-size:.72rem">
            <i class="bi bi-info-circle"></i>
            مثال: «كاشير» = يقدر يستخدم شاشة الكاشير ويسجل الفواتير في فروعه.
        </small>
    </div>
    <div class="col-md-4"><label class="form-label">المحطة</label>
        <select name="station_id" class="form-select">
            <option value="">— بدون —</option>
            @foreach($stations as $s)
                <option value="{{ $s->id }}" @selected(old('station_id', $user->station_id ?? '')==$s->id)>{{ $s->name }}</option>
            @endforeach
        </select>
    </div>
    <div class="col-md-4"><label class="form-label">الحالة *</label>
        <select name="status" class="form-select" required>
            <option value="active" @selected(old('status', $user->status ?? 'active')==='active')>فعال</option>
            <option value="inactive" @selected(old('status', $user->status ?? '')==='inactive')>غير فعال</option>
            <option value="suspended" @selected(old('status', $user->status ?? '')==='suspended')>معطل</option>
        </select>
    </div>

    <div class="col-md-6"><label class="form-label">كلمة المرور {{ isset($user) ? '(اتركها فارغة لعدم التغيير)' : '*' }}</label>
        <input type="password" name="password" class="form-control" @if(! isset($user)) required @endif></div>
    <div class="col-md-6"><label class="form-label">تأكيد كلمة المرور</label>
        <input type="password" name="password_confirmation" class="form-control"></div>

    {{-- Staff meal allowance — the monthly cap for "وجبات الموظفين".
         Null/blank = no allowance (the employee can't run a tab).
         The dashboard surfaces consumption, over-limit, and the
         carry-over to next month from here. --}}
    <div class="col-md-6">
        <label class="form-label">
            <i class="bi bi-cup-hot-fill text-accent"></i>
            بدل الوجبات الشهري
            <small class="text-muted">(اختياري)</small>
        </label>
        <div class="input-group">
            <input type="number" step="0.01" min="0" max="99999"
                   name="monthly_meal_allowance"
                   value="{{ old('monthly_meal_allowance', $user->monthly_meal_allowance ?? '') }}"
                   placeholder="مثلاً: 500"
                   class="form-control">
            <span class="input-group-text">ش.إ / شهرياً</span>
        </div>
        <small class="text-muted d-block mt-1">
            <i class="bi bi-info-circle"></i>
            الحد الذي يستطيع هذا الموظف استهلاكه شهرياً من المطعم (له ولعائلته).
            اتركه فارغاً = لا يستطيع الأكل على الحساب.
        </small>
    </div>
</div>

{{-- ─── Branch assignments ────────────────────────────────────────────
     A user can belong to multiple branches with a different role per
     branch. Exactly one branch is marked "أساسي" (primary) — that's
     the one selected automatically on login. Super Admins technically
     don't need a branch (BranchScope skips them), but assigning one is
     still recommended so scoped UIs have a sensible default. --}}
@php
    // $oldRoleMap removed — per-branch role overrides were dropped May 2026.
    $current      = isset($user) ? $user->branches->keyBy('id') : collect();
    $oldSelected  = (array) old('branches', $current->keys()->all());
    $oldPrimaryId = (int)   old('primary_branch_id', $current->firstWhere(fn($b) => (bool) $b->pivot->is_primary)?->id ?? ($oldSelected[0] ?? 0));
@endphp

@php
    // Branches are required for everyone except owner-level roles.
    $selectedRole = old('role', $user->role ?? '');
    $isOwnerLevelRole = in_array($selectedRole, \App\Enums\UserRole::ownerRoles(), true);
    $branchesRequired = ! $isOwnerLevelRole;
@endphp

<div class="branch-assign mt-4 @error('branches') has-error @enderror">
    <div class="branch-assign__head">
        <i class="bi bi-buildings"></i>
        <div class="flex-grow-1">
            <div class="branch-assign__title">
                الفروع المُعيَّن إليها
                @if($branchesRequired)
                    <span class="text-danger">*</span>
                @else
                    <small class="text-muted ms-1">(اختياري للأدوار الإدارية العليا)</small>
                @endif
            </div>
            <div class="branch-assign__hint">
                اختر الفروع التي يعمل فيها هذا المستخدم.
                علِّم فرعاً واحداً كـ«أساسي» — وهو الذي يفتح تلقائياً عند الدخول.
                @if($branchesRequired)
                    <strong class="text-danger d-block mt-1">
                        <i class="bi bi-exclamation-circle-fill"></i>
                        اختيار فرع واحد على الأقل إجباري — بدونه لا يستطيع المستخدم الوصول لأي شاشة.
                    </strong>
                @endif
            </div>
        </div>
    </div>

    @error('branches')
        <div class="alert alert-danger d-flex align-items-center gap-2 mb-3"
             style="border-radius: 12px; padding: .8rem 1rem;">
            <i class="bi bi-exclamation-triangle-fill fs-18"></i>
            <span><strong>{{ $message }}</strong></span>
        </div>
    @enderror

    @if($branches->isEmpty())
        <div class="alert alert-warning mb-0">
            <i class="bi bi-exclamation-triangle"></i>
            لا توجد فروع مفعّلة في النظام حالياً.
            <a href="{{ route('admin.branches.create') }}">أنشئ فرعاً أولاً</a>.
        </div>
    @else
        @php
            // $accessibleBranchIds is the allowlist for this admin. Branches
            // outside it render as locked (read-only with a 🔒). The server
            // controller has the same allowlist as defense-in-depth.
            $accessibleIds = array_map('intval', $accessibleBranchIds ?? []);
            $hasLocked = collect($branches)->contains(fn ($b) => ! in_array((int) $b->id, $accessibleIds, true));
        @endphp

        @if($hasLocked)
            <div class="alert alert-info py-2 mb-3 small">
                <i class="bi bi-shield-lock-fill"></i>
                الفروع الموسومة بـ 🔒 خارج نطاق صلاحيتك — لا تستطيع إضافتها أو إزالتها.
                @if(isset($user) && $user->branches->whereNotIn('id', $accessibleIds)->isNotEmpty())
                    تُعرض هنا فقط للعلم؛ تعديلاتك تطبَّق على فروعك أنت.
                @endif
            </div>
        @endif

        <div class="branch-assign__grid">
            @foreach($branches as $branch)
                @php
                    $isAccessible = in_array((int) $branch->id, $accessibleIds, true);
                    $isChecked    = in_array($branch->id, $oldSelected);
                    $isPrimary    = $oldPrimaryId === $branch->id;
                @endphp
                <div class="branch-assign__card {{ $isChecked ? 'is-on' : '' }} {{ $isAccessible ? '' : 'is-locked' }}"
                     data-branch-card="{{ $branch->id }}"
                     @if(! $isAccessible) title="خارج نطاق صلاحيتك" @endif>

                    <label class="branch-assign__row">
                        {{-- Disabled checkboxes don't submit, so an out-of-scope
                             card never sends its branch_id. The server also
                             filters the array (defense in depth). --}}
                        <input type="checkbox"
                               name="branches[]"
                               value="{{ $branch->id }}"
                               class="branch-assign__check"
                               data-branch-id="{{ $branch->id }}"
                               @checked($isChecked)
                               @disabled(! $isAccessible)>
                        <span class="branch-assign__row-body">
                            <span class="branch-assign__name">
                                {{ $branch->name }}
                                @unless($isAccessible)
                                    <i class="bi bi-lock-fill text-muted ms-1" title="خارج نطاق صلاحيتك"></i>
                                @endunless
                            </span>
                            <span class="branch-assign__meta">
                                <code>{{ $branch->code }}</code>
                                @if($branch->city) · {{ $branch->city }} @endif
                            </span>
                        </span>
                    </label>

                    {{-- The per-branch role dropdown was removed in May 2026
                         (the team always relied on the global users.role).
                         Only the "primary branch" radio remains here. --}}
                    <div class="branch-assign__opts">
                        <label class="branch-assign__primary">
                            <input type="radio"
                                   name="primary_branch_id"
                                   value="{{ $branch->id }}"
                                   @checked($isPrimary)
                                   @disabled(! $isAccessible)>
                            <i class="bi bi-star-fill"></i>
                            أساسي <small class="text-muted">(الفرع الذي يفتح عند الدخول)</small>
                        </label>
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</div>

<div class="form-actions">
    <button class="btn btn-primary"><i class="bi bi-save me-1"></i> حفظ</button>
    <a href="{{ route('admin.users.index') }}" class="btn btn-light">إلغاء</a>
</div>

@push('styles')
<style>
    /* ─── Quick guide banner (replaces the old two-card clarifier) ─ */
    .user-quick-guide {
        display: flex; gap: .75rem; align-items: flex-start;
        background: linear-gradient(90deg, #eff6ff 0%, #fff 100%);
        border: 1px solid #bfdbfe;
        border-radius: 12px;
        padding: .75rem 1rem;
        color: #1e3a8a;
        font-size: .88rem;
        line-height: 1.7;
    }
    .user-quick-guide__icon i { color: #2563eb; font-size: 1.3rem; }
    .user-quick-guide__body b { color: #0f172a; }

    /* Legacy roles-help styles kept hidden — the markup is gone but
       removing these declarations cleanly is its own pass. They're
       inert (no element uses these classes any more). */
    .user-roles-help { display: none; }
    .user-roles-help__head {
        display: flex; align-items: center; justify-content: space-between;
        background: linear-gradient(90deg, #eff6ff 0%, #fff 100%);
        padding: .75rem 1rem;
        font-weight: 700; cursor: pointer;
        color: #1e3a8a;
        user-select: none;
    }
    .user-roles-help__head:hover { background: linear-gradient(90deg, #dbeafe 0%, #fff 100%); }
    .user-roles-help__head i.bi-question-circle-fill { color: #2563eb; }
    .user-roles-help__caret { color: #64748b; transition: transform .2s ease; }
    .user-roles-help[open] .user-roles-help__caret { transform: rotate(180deg); }
    .user-roles-help__body { padding: 1rem; border-top: 1px solid #f1f5f9; }
    .user-roles-help__card {
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        border-radius: 10px;
        padding: 1rem;
        height: 100%;
    }
    .user-roles-help__card h6 {
        font-weight: 800;
        color: #0f172a;
        margin-bottom: .55rem;
        display: inline-flex;
        align-items: center;
        gap: .35rem;
    }
    .user-roles-help__card p {
        font-size: .82rem;
        color: #475569;
        margin-bottom: .5rem;
        line-height: 1.7;
    }
    .user-roles-help__card ul {
        list-style: none; padding: 0; margin: 0;
        font-size: .76rem; color: #64748b;
    }
    .user-roles-help__card ul li {
        padding: 2px 0;
        padding-inline-start: 14px;
        position: relative;
    }
    .user-roles-help__card ul li::before {
        content: '•';
        position: absolute;
        inset-inline-start: 0;
        color: #94a3b8;
    }

    /* Error state on the branches section — red ring around the whole
       block so the user can't miss the required-but-empty signal. */
    .branch-assign.has-error {
        outline: 2px solid #fecaca;
        outline-offset: 4px;
        border-radius: 12px;
    }
    .branch-assign.has-error .branch-assign__head {
        background: linear-gradient(135deg, #fee2e2 0%, #fef2f2 100%) !important;
    }

    .branch-assign__head {
        display: flex; align-items: flex-start; gap: 12px;
        padding: .9rem 1rem;
        background: linear-gradient(135deg,
            rgba(var(--primary-rgb), .06) 0%,
            rgba(var(--accent-rgb), .04) 100%);
        border: 1px solid rgba(var(--primary-rgb), .12);
        border-radius: 12px;
        margin-bottom: 1rem;
    }
    .branch-assign__head > i {
        font-size: 1.4rem;
        color: rgb(var(--primary-rgb));
        margin-top: 2px;
    }
    .branch-assign__title {
        font-weight: 800; font-size: .95rem;
        color: rgb(var(--primary-rgb));
        margin-bottom: 2px;
    }
    .branch-assign__hint { font-size: .82rem; color: #6b7280; line-height: 1.65; }

    .branch-assign__grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
        gap: 12px;
    }

    .branch-assign__card {
        background: #fff;
        border: 1.5px solid #e5e7eb;
        border-radius: 12px;
        overflow: hidden;
        transition: border-color .15s, box-shadow .15s;
    }
    .branch-assign__card.is-on {
        border-color: rgb(var(--accent-rgb));
        box-shadow: 0 4px 14px rgba(var(--accent-rgb), .15);
    }
    /* Out-of-scope branch — visually muted, disabled inputs already
       block interaction; this just signals "you can't touch this". */
    .branch-assign__card.is-locked {
        opacity: .55;
        background: repeating-linear-gradient(
            45deg,
            #fafafa,
            #fafafa 8px,
            #f1f5f9 8px,
            #f1f5f9 16px
        );
        cursor: not-allowed;
    }
    .branch-assign__card.is-locked .branch-assign__row,
    .branch-assign__card.is-locked .branch-assign__opts { cursor: not-allowed; }
    .branch-assign__card.is-locked .branch-assign__name i.bi-lock-fill { color: #94a3b8; }

    .branch-assign__row {
        display: flex; align-items: center; gap: 10px;
        padding: .7rem .85rem;
        cursor: pointer; user-select: none;
        background: #fafafa;
        border-bottom: 1px solid #eee;
        margin: 0;
    }
    .branch-assign__row:hover { background: #f3f4f6; }
    .branch-assign__check {
        width: 18px; height: 18px;
        accent-color: rgb(var(--primary-rgb));
        flex-shrink: 0; cursor: pointer;
    }
    .branch-assign__row-body { display: flex; flex-direction: column; flex: 1; min-width: 0; }
    .branch-assign__name { font-weight: 700; font-size: .92rem; color: #1f2937; }
    .branch-assign__meta { font-size: .76rem; color: #6b7280; }
    .branch-assign__meta code {
        background: #f3f4f6; color: #1f2937;
        padding: 1px 5px; border-radius: 4px; font-size: .72rem;
    }

    .branch-assign__opts {
        padding: .75rem .85rem;
        display: grid;
        grid-template-columns: 1fr auto;
        gap: 12px; align-items: end;
    }
    .branch-assign__card:not(.is-on) .branch-assign__opts { display: none; }

    .branch-assign__primary {
        display: inline-flex; align-items: center; gap: 6px;
        padding: 7px 12px;
        border-radius: 8px;
        background: #f9fafb;
        border: 1px solid #e5e7eb;
        font-size: .82rem; font-weight: 600;
        color: #374151;
        cursor: pointer;
        transition: all .12s;
        white-space: nowrap;
    }
    .branch-assign__primary:hover { border-color: rgb(var(--accent-rgb)); }
    .branch-assign__primary input { accent-color: rgb(var(--accent-rgb)); }
    .branch-assign__primary i { color: #d1d5db; transition: color .12s; }
    .branch-assign__primary:has(input:checked) {
        background: rgba(var(--accent-rgb), .1);
        border-color: rgb(var(--accent-rgb));
        color: rgb(var(--primary-rgb));
    }
    .branch-assign__primary:has(input:checked) i { color: rgb(var(--accent-rgb)); }
</style>
@endpush

@push('scripts')
<script>
    /**
     * Branch-assign UX: when the user toggles a branch checkbox, reveal
     * the role/primary selector for that row and (if newly checked and
     * no other row is primary) auto-select it as primary so the form is
     * never submitted with a checked branch + zero primary.
     */
    document.addEventListener('DOMContentLoaded', () => {
        const checks = document.querySelectorAll('.branch-assign__check');
        const ensurePrimary = () => {
            const checkedCards = Array.from(document.querySelectorAll('.branch-assign__card.is-on'));
            if (checkedCards.length === 0) return;
            const anyPrimary = checkedCards.some(c => c.querySelector('input[name="primary_branch_id"]:checked'));
            if (!anyPrimary) {
                checkedCards[0].querySelector('input[name="primary_branch_id"]').checked = true;
            }
        };

        checks.forEach(cb => {
            cb.addEventListener('change', () => {
                const card = cb.closest('.branch-assign__card');
                card.classList.toggle('is-on', cb.checked);

                if (! cb.checked) {
                    // Don't leave a primary radio on a now-deselected branch.
                    const radio = card.querySelector('input[name="primary_branch_id"]');
                    if (radio) radio.checked = false;
                }
                ensurePrimary();
            });
        });
    });
</script>
@endpush
