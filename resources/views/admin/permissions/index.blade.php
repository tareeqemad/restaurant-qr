@extends('layouts.admin')
@section('title', 'إدارة الصلاحيات')

@section('content')
<x-admin.breadcrumb
    title="إدارة الصلاحيات"
    icon="bi-shield-lock-fill"
    subtitle="اختر مستخدماً من القائمة لمنحه أو سحب صلاحيات خارج إطار دوره الافتراضي"/>

{{-- Quick context banner — explains the role/extra/revoked model so
     someone landing here cold isn't lost. --}}
<div class="alert alert-light border d-flex align-items-start gap-3 mb-3"
     style="border-right:4px solid #0f4731!important;">
    <i class="bi bi-info-circle-fill text-primary fs-4 mt-1"></i>
    <div class="small">
        <strong class="d-block mb-1">كيف تعمل هذه الصفحة؟</strong>
        كل مستخدم بياخد صلاحيات افتراضية من <strong>دوره</strong> (كاشير، نادل، مدير…).
        من هون تقدر <strong>تمنحه صلاحيات إضافية</strong> ما عنده الدور أصلاً، أو
        <strong>تلغي صلاحية</strong> الدور بيمنحها — كل تعديل بينحفظ كاستثناء يخص هذا المستخدم فقط
        ولا يؤثر على باقي الموظفين بنفس الدور.
    </div>
</div>

{{-- ────────── User picker card ────────── --}}
<div class="card mb-3">
    <div class="card-body py-3">
        <form method="GET" action="{{ route('admin.permissions.index') }}" id="user-picker-form" class="row g-2 align-items-end">
            <div class="col-md-6">
                <label class="form-label fw-bold mb-1">
                    <i class="bi bi-person-fill"></i> اختر المستخدم
                </label>
                {{-- Native select with a JS-driven search filter underneath.
                     For shops with <100 staff this beats heavy libraries. --}}
                <input type="search" id="user-search" class="form-control form-control-sm mb-2"
                       placeholder="ابحث بالاسم أو اسم المستخدم أو الهاتف…" autocomplete="off">
                <select name="user" id="user-select" class="form-select" onchange="document.getElementById('user-picker-form').submit()">
                    <option value="">— اختر مستخدماً —</option>
                    @foreach($users as $u)
                        @php
                            // Surface role + branch labels in the option text so
                            // the picker doubles as a quick directory.
                            $roleLabel = $u->role ? (\App\Enums\UserRole::tryFrom($u->role)?->label() ?? $u->role) : '—';
                            $primaryBranch = $u->branches->firstWhere('pivot.is_primary', true) ?? $u->branches->first();
                            $branchLabel = $primaryBranch?->name ?? '';
                        @endphp
                        <option value="{{ $u->id }}"
                                data-search="{{ strtolower($u->name.' '.$u->username.' '.($u->phone ?? '').' '.$roleLabel.' '.$branchLabel) }}"
                                @selected($selectedUser?->id === $u->id)>
                            {{ $u->name }} — {{ $roleLabel }}@if($branchLabel) ({{ $branchLabel }})@endif
                        </option>
                    @endforeach
                </select>
                <small class="text-muted">
                    <i class="bi bi-people-fill"></i>
                    إجمالي المستخدمين المتاحين: <strong>{{ $users->count() }}</strong>
                </small>
            </div>

            @if($selectedUser)
                {{-- Selected-user summary card on the right --}}
                <div class="col-md-6">
                    <div class="selected-user-card">
                        <div class="d-flex align-items-center gap-2 mb-1">
                            <div class="user-avatar">
                                {{ mb_substr($selectedUser->name, 0, 1) }}
                            </div>
                            <div>
                                <div class="fw-bold">{{ $selectedUser->name }}</div>
                                <div class="small text-muted">
                                    @ {{ $selectedUser->username }}
                                    · <i class="bi bi-shield-fill"></i> {{ (\App\Enums\UserRole::tryFrom($selectedUser->role)?->label() ?? $selectedUser->role) }}
                                    @if($selectedUser->status !== 'active')
                                        · <span class="badge bg-warning text-dark">{{ $selectedUser->status }}</span>
                                    @endif
                                </div>
                            </div>
                            <a href="{{ route('admin.users.edit', $selectedUser) }}"
                               class="btn btn-sm btn-outline-secondary ms-auto" title="تعديل بيانات المستخدم (الاسم/البريد/الفروع)">
                                <i class="bi bi-pencil"></i> تعديل البيانات
                            </a>
                        </div>
                    </div>
                </div>
            @endif
        </form>
    </div>
</div>

{{-- ────────── Permission tree (only when a user is selected) ────────── --}}
@if($selectedUser)
    @if($isOwnerLevel)
        {{-- Owner-level users (super_admin / partner) bypass the permission
             system entirely — show just the info partial WITHOUT a <form>
             so an accidental Enter press or DevTools poke can't trigger
             a useless sync request. --}}
        <div class="card mb-3">
            <div class="card-body">
                @include('admin.permissions._tree', ['showWrapperCard' => false])
            </div>
        </div>
    @else
        <form method="POST" action="{{ route('admin.permissions.sync', $selectedUser) }}" id="perm-form">
            @csrf @method('PUT')

            <div class="card mb-3">
                <div class="card-body">
                    {{-- The shared partial.  Same markup as the user-edit page.
                         showWrapperCard=false because we're already inside a card. --}}
                    @include('admin.permissions._tree', ['showWrapperCard' => false])
                </div>
            </div>

            {{-- Sticky save bar — far enough from the tree that the user
                 can scroll without losing the action button. --}}
            <div class="perm-save-bar">
                <div class="d-flex align-items-center gap-3">
                    <i class="bi bi-floppy-fill fs-4 text-primary"></i>
                    <div>
                        <strong class="d-block">حفظ التغييرات</strong>
                        <small class="text-muted">سيتم تسجيل أي إضافة/إلغاء كاستثناء على هذا المستخدم فقط.</small>
                    </div>
                    <a href="{{ route('admin.permissions.index') }}" class="btn btn-light ms-auto">إلغاء</a>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-circle-fill"></i> حفظ التغييرات
                    </button>
                </div>
            </div>
        </form>
    @endif
@else
    {{-- Empty state — quick visual guide to the picker --}}
    <div class="card">
        <div class="card-body text-center py-5 text-muted">
            <i class="bi bi-arrow-up-circle-fill display-4 text-primary opacity-50"></i>
            <h5 class="mt-3 mb-1">اختر مستخدماً للبدء</h5>
            <p class="mb-0">استعمل القائمة بالأعلى لاختيار موظف وعرض شجرة صلاحياته.</p>
        </div>
    </div>
@endif

@push('styles')
<style>
    /* ─── User picker visuals ─── */
    .selected-user-card {
        background: linear-gradient(135deg, rgba(15,71,49,.05), rgba(34,197,94,.03));
        border: 1px solid rgba(15,71,49,.12);
        border-radius: 10px; padding: .65rem .85rem;
    }
    .user-avatar {
        width: 44px; height: 44px; border-radius: 50%;
        background: linear-gradient(135deg, #0f4731, #22c55e);
        color: #fff; font-weight: 700; font-size: 1.1rem;
        display: inline-flex; align-items: center; justify-content: center;
        flex-shrink: 0;
    }

    /* ─── Sticky save bar at the bottom of the viewport ─── */
    .perm-save-bar {
        position: sticky; bottom: 12px;
        background: #fff;
        border: 1px solid rgba(15,71,49,.18);
        border-radius: 12px;
        padding: .85rem 1.1rem;
        box-shadow: 0 4px 20px rgba(0,0,0,.08);
        margin-top: 1rem; z-index: 100;
    }
</style>
@endpush

@push('scripts')
<script>
// ═══════════════════ User search filter ═══════════════════
// Filters the <option>s of the user dropdown by typing in the
// search box above it. Arabic-aware normalization keeps "محمد"
// findable whether you type with or without diacritics.
(function () {
    const search = document.getElementById('user-search');
    const select = document.getElementById('user-select');
    if (! search || ! select) return;

    const normalize = (s) => String(s || '').toLowerCase()
        .replace(/[ً-ٰٟ]/g, '')
        .replace(/[إأآا]/g, 'ا')
        .replace(/ة/g, 'ه')
        .replace(/ى/g, 'ي').trim();

    // Cache the options once — they don't change without a reload.
    const opts = Array.from(select.options);

    search.addEventListener('input', () => {
        const needle = normalize(search.value);
        opts.forEach(o => {
            if (! o.value) return;                  // keep the "— اختر —" placeholder
            const haystack = normalize(o.dataset.search || o.textContent);
            o.hidden = needle && ! haystack.includes(needle);
        });

        // Open the native dropdown after filtering so the user sees
        // the narrowed list. (Standard select doesn't have a programmatic
        // "open" — focusing it is the next best thing.)
        if (needle) select.focus();
    });
})();
</script>
@endpush
@endsection
