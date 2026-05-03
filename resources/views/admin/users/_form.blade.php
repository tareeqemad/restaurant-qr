@csrf
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

    <div class="col-md-4"><label class="form-label">الدور *</label>
        <select name="role" class="form-select" required>
            @foreach($roles as $v => $label)
                <option value="{{ $v }}" @selected(old('role', $user->role ?? '')===$v)>{{ $label }}</option>
            @endforeach
        </select>
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
</div>

{{-- ─── Branch assignments ────────────────────────────────────────────
     A user can belong to multiple branches with a different role per
     branch. Exactly one branch is marked "أساسي" (primary) — that's
     the one selected automatically on login. Super Admins technically
     don't need a branch (BranchScope skips them), but assigning one is
     still recommended so scoped UIs have a sensible default. --}}
@php
    $current        = isset($user) ? $user->branches->keyBy('id') : collect();
    $oldSelected    = (array) old('branches', $current->keys()->all());
    $oldRoleMap     = (array) old('branch_roles', $current->mapWithKeys(fn($b) => [$b->id => $b->pivot->role_id])->all());
    $oldPrimaryId   = (int)   old('primary_branch_id', $current->firstWhere(fn($b) => (bool) $b->pivot->is_primary)?->id ?? ($oldSelected[0] ?? 0));
@endphp

<div class="branch-assign mt-4">
    <div class="branch-assign__head">
        <i class="bi bi-buildings"></i>
        <div>
            <div class="branch-assign__title">الفروع المُعيَّن إليها</div>
            <div class="branch-assign__hint">
                اختر الفروع التي يعمل فيها هذا المستخدم، وحدِّد الدور الخاص بكل فرع.
                علِّم فرعاً واحداً كـ«أساسي» — وهو الذي يفتح تلقائياً عند الدخول.
            </div>
        </div>
    </div>

    @if($branches->isEmpty())
        <div class="alert alert-warning mb-0">
            <i class="bi bi-exclamation-triangle"></i>
            لا توجد فروع مفعّلة في النظام حالياً.
            <a href="{{ route('admin.branches.create') }}">أنشئ فرعاً أولاً</a>.
        </div>
    @else
        <div class="branch-assign__grid">
            @foreach($branches as $branch)
                @php
                    $isChecked  = in_array($branch->id, $oldSelected);
                    $rowRoleId  = $oldRoleMap[$branch->id] ?? null;
                    $isPrimary  = $oldPrimaryId === $branch->id;
                @endphp
                <div class="branch-assign__card {{ $isChecked ? 'is-on' : '' }}"
                     data-branch-card="{{ $branch->id }}">

                    <label class="branch-assign__row">
                        <input type="checkbox"
                               name="branches[]"
                               value="{{ $branch->id }}"
                               class="branch-assign__check"
                               data-branch-id="{{ $branch->id }}"
                               @checked($isChecked)>
                        <span class="branch-assign__row-body">
                            <span class="branch-assign__name">{{ $branch->name }}</span>
                            <span class="branch-assign__meta">
                                <code>{{ $branch->code }}</code>
                                @if($branch->city) · {{ $branch->city }} @endif
                            </span>
                        </span>
                    </label>

                    <div class="branch-assign__opts">
                        <div>
                            <label class="form-label small mb-1">الدور في الفرع</label>
                            <select name="branch_roles[{{ $branch->id }}]"
                                    class="form-select form-select-sm">
                                <option value="">— اختر دور —</option>
                                @foreach($branchRoles as $role)
                                    <option value="{{ $role->id }}"
                                            @selected($rowRoleId == $role->id)>
                                        {{ $role->label }}{{ $role->branch_id ? '' : ' (قالب عام)' }}
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        <label class="branch-assign__primary">
                            <input type="radio"
                                   name="primary_branch_id"
                                   value="{{ $branch->id }}"
                                   @checked($isPrimary)>
                            <i class="bi bi-star-fill"></i>
                            أساسي
                        </label>
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</div>

<div class="d-flex gap-2 mt-4">
    <button class="btn btn-primary"><i class="bi bi-save me-1"></i> حفظ</button>
    <a href="{{ route('admin.users.index') }}" class="btn btn-light">إلغاء</a>
</div>

@push('styles')
<style>
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
