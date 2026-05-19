@extends('layouts.admin')
@section('title', 'العميل · '.$customer->name)

@php
    $hue = ($customer->id * 47) % 360;
    $initial = mb_substr(trim($customer->name) ?: '؟', 0, 1, 'UTF-8');
@endphp

@section('content')
<x-admin.breadcrumb :title="$customer->name" icon="bi-person-circle"
    :crumbs="[['label' => 'العملاء', 'url' => route('admin.customers.index')]]" />

<div class="row g-3">
    {{-- ─── Profile card ─────────────────────────────────────────── --}}
    <div class="col-lg-4">
        <div class="cust-profile">
            <div class="cust-profile__head" style="--hue: {{ $hue }};">
                <div class="cust-profile__avatar">{{ $initial }}</div>
                <h4 class="cust-profile__name">{{ $customer->name }}</h4>
                @if($customer->isBlocked())
                    <span class="badge bg-danger mt-2"><i class="bi bi-slash-circle"></i> محظور</span>
                @else
                    <span class="badge bg-success mt-2">نشط</span>
                @endif
            </div>

            <div class="cust-profile__body">
                @if($customer->isBlocked() && $customer->blocked_reason)
                    <div class="alert alert-danger py-2 small mb-3">
                        <strong>سبب الحظر:</strong> {{ $customer->blocked_reason }}
                    </div>
                @endif

                <dl class="cust-info">
                    <dt><i class="bi bi-telephone-fill"></i> الهاتف</dt>
                    <dd dir="ltr"><a href="tel:{{ $customer->phone }}">{{ $customer->phone }}</a></dd>

                    @if($customer->email)
                        <dt><i class="bi bi-envelope-fill"></i> الإيميل</dt>
                        <dd dir="ltr"><a href="mailto:{{ $customer->email }}">{{ $customer->email }}</a></dd>
                    @endif

                    @if($customer->gender)
                        <dt><i class="bi bi-person"></i> الجنس</dt>
                        <dd>
                            @switch($customer->gender)
                                @case('male')   ذكر @break
                                @case('female') أنثى @break
                                @default غير محدد
                            @endswitch
                        </dd>
                    @endif

                    @if($customer->birthday)
                        <dt><i class="bi bi-cake2-fill"></i> تاريخ الميلاد</dt>
                        <dd>{{ $customer->birthday->format('Y/m/d') }}</dd>
                    @endif

                    @if($customer->defaultBranch)
                        <dt><i class="bi bi-building"></i> الفرع المفضّل</dt>
                        <dd><x-admin.branch-tag :branch="$customer->defaultBranch" /></dd>
                    @endif

                    <dt><i class="bi bi-calendar-plus"></i> تاريخ التسجيل</dt>
                    <dd>
                        {{ $customer->created_at->format('Y/m/d') }}
                        <small class="text-muted d-block">{{ $customer->created_at->diffForHumans() }}</small>
                    </dd>

                    @if($customer->last_login_at)
                        <dt><i class="bi bi-box-arrow-in-right"></i> آخر دخول</dt>
                        <dd>
                            {{ $customer->last_login_at->format('Y/m/d H:i') }}
                            <small class="text-muted d-block">{{ $customer->last_login_at->diffForHumans() }}</small>
                        </dd>
                    @endif
                </dl>

                <div class="d-flex gap-2 mt-3">
                    @can('update', $customer)
                        <button class="btn btn-sm btn-light flex-grow-1" data-bs-toggle="modal" data-bs-target="#custEditModal">
                            <i class="bi bi-pencil"></i> تعديل
                        </button>
                    @endcan
                    @can('block', $customer)
                        @if($customer->isBlocked())
                            <form action="{{ route('admin.customers.unblock', $customer) }}" method="POST" class="flex-grow-1"
                                  onsubmit="return confirm('إلغاء الحظر؟');">
                                @csrf
                                <button class="btn btn-sm btn-outline-success w-100">
                                    <i class="bi bi-arrow-counterclockwise"></i> إلغاء الحظر
                                </button>
                            </form>
                        @else
                            <button class="btn btn-sm btn-outline-warning flex-grow-1"
                                    data-bs-toggle="modal" data-bs-target="#custBlockModal">
                                <i class="bi bi-slash-circle"></i> حظر
                            </button>
                        @endif
                    @endcan
                </div>
            </div>
        </div>
    </div>

    {{-- ─── Right column: branches + history ─────────────────────── --}}
    <div class="col-lg-8">
        {{-- Debt summary panel — surfaces an open balance before anything
             else so cashier/manager opening this page sees the action item.
             Hidden entirely when the customer has zero outstanding to keep
             the page calm for the 95% case. --}}
        @php $cdebt = $customer->outstandingDebt(); @endphp
        @if($cdebt > 0.001 || $customer->credit_limit !== null)
            <div class="card mb-3 {{ $cdebt > 0 ? 'border-danger' : '' }}" style="border-radius:14px;">
                <div class="card-body d-flex align-items-center justify-content-between">
                    <div>
                        <h6 class="mb-1">
                            <i class="bi bi-wallet2 text-danger"></i> الديون المفتوحة
                        </h6>
                        @if($cdebt > 0.001)
                            <div class="fs-4 fw-bold text-danger">{{ \App\Helpers\Money::format($cdebt) }}</div>
                            <small class="text-muted">عبر {{ $customer->openDebtInvoices()->count() }} فاتورة</small>
                        @else
                            <span class="text-success">لا ديون مفتوحة 👌</span>
                        @endif
                        @if($customer->credit_limit !== null)
                            <div class="mt-1">
                                <small class="text-muted">الحد الائتماني:</small>
                                <strong>{{ \App\Helpers\Money::format((float) $customer->credit_limit) }}</strong>
                            </div>
                        @endif
                    </div>
                    <a href="{{ route('admin.customers.debts.show', $customer) }}"
                       class="btn btn-{{ $cdebt > 0 ? 'danger' : 'outline-secondary' }}">
                        <i class="bi bi-wallet"></i> سجل الديون
                    </a>
                </div>
            </div>
        @endif

        {{-- Branches visited --}}
        <div class="card mb-3" style="border-radius: 14px;">
            <div class="card-header" style="border-bottom: 1px solid #f3f4f6;">
                <h6 class="mb-0"><i class="bi bi-geo-alt-fill"></i> الفروع التي زارها</h6>
            </div>
            <div class="card-body">
                @if($byBranch->isEmpty())
                    <p class="text-muted small mb-0">لا توجد زيارات مسجّلة بعد.</p>
                @else
                    <div class="d-flex flex-wrap gap-2">
                        @foreach($byBranch as $row)
                            @if($row->branch)
                                <div class="cust-branch-stat">
                                    <x-admin.branch-tag :branch="$row->branch" />
                                    <span class="cust-branch-stat__count">{{ $row->visits }} {{ $row->visits === 1 ? 'زيارة' : 'زيارات' }}</span>
                                </div>
                            @endif
                        @endforeach
                    </div>
                @endif
            </div>
        </div>

        {{-- Reservations history --}}
        <x-admin.data-panel title="سجل الحجوزات" :count="$reservations->count()" icon="bi-calendar-check">
            <div class="table-responsive">
                <table class="table align-middle">
                    <thead class="bg-light">
                        <tr>
                            <th>المرجع</th>
                            <th>الفرع</th>
                            <th>الموعد</th>
                            <th>عدد الأشخاص</th>
                            <th>الحالة</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($reservations as $r)
                            <tr>
                                <td><code>{{ $r->reference }}</code></td>
                                <td><x-admin.branch-tag :branch="$r->branch" /></td>
                                <td>
                                    {{ $r->reserved_for->format('Y/m/d H:i') }}
                                    <small class="text-muted d-block">{{ $r->reserved_for->diffForHumans() }}</small>
                                </td>
                                <td>{{ $r->party_size }}</td>
                                <td>
                                    <span class="badge bg-secondary-transparent">{{ $r->status }}</span>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="text-center text-muted py-3">لا توجد حجوزات بعد.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-admin.data-panel>

        {{-- Reviews history --}}
        @if($reviews->isNotEmpty())
            <div class="mt-3">
                <x-admin.data-panel title="التقييمات" :count="$reviews->count()" icon="bi-star-fill">
                    <div class="cust-reviews">
                        @foreach($reviews as $rev)
                            <div class="cust-review">
                                <div class="cust-review__head">
                                    <div class="cust-review__rating">
                                        @for($i = 1; $i <= 5; $i++)
                                            <i class="bi bi-star{{ $i <= $rev->rating ? '-fill' : '' }}"></i>
                                        @endfor
                                    </div>
                                    <x-admin.branch-tag :branch="$rev->branch" />
                                    <small class="text-muted ms-auto">{{ $rev->created_at->diffForHumans() }}</small>
                                </div>
                                @if($rev->comment)
                                    <p class="cust-review__body">{{ $rev->comment }}</p>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </x-admin.data-panel>
            </div>
        @endif
    </div>
</div>

{{-- ─── Edit modal ───────────────────────────────────────────────── --}}
@can('update', $customer)
<div class="modal fade" id="custEditModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <form method="POST" action="{{ route('admin.customers.update', $customer) }}" class="modal-content">
            @csrf @method('PUT')
            <div class="modal-header">
                <h6 class="modal-title"><i class="bi bi-pencil-square"></i> تعديل بيانات العميل</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">الاسم *</label>
                        <input name="name" value="{{ old('name', $customer->name) }}" class="form-control" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">الهاتف *</label>
                        <input name="phone" value="{{ old('phone', $customer->phone) }}" class="form-control" required dir="ltr">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">الإيميل</label>
                        <input name="email" type="email" value="{{ old('email', $customer->email) }}" class="form-control" dir="ltr">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">الجنس</label>
                        <select name="gender" class="form-select">
                            <option value="unspecified">غير محدد</option>
                            <option value="male"   @selected($customer->gender==='male')>ذكر</option>
                            <option value="female" @selected($customer->gender==='female')>أنثى</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">تاريخ الميلاد</label>
                        <input type="date" name="birthday" value="{{ old('birthday', optional($customer->birthday)->format('Y-m-d')) }}" class="form-control">
                    </div>
                    <div class="col-md-12">
                        <label class="form-label">الفرع المفضّل</label>
                        <select name="default_branch_id" class="form-select">
                            <option value="">— غير محدد —</option>
                            @foreach(\App\Models\Branch::where('is_active',true)->orderBy('display_order')->get() as $b)
                                <option value="{{ $b->id }}" @selected($customer->default_branch_id===$b->id)>{{ $b->name }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-primary"><i class="bi bi-save"></i> حفظ</button>
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">إلغاء</button>
            </div>
        </form>
    </div>
</div>
@endcan

{{-- ─── Block modal ──────────────────────────────────────────────── --}}
@can('block', $customer)
<div class="modal fade" id="custBlockModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" action="{{ route('admin.customers.block', $customer) }}" class="modal-content">
            @csrf
            <div class="modal-header bg-warning-transparent">
                <h6 class="modal-title"><i class="bi bi-slash-circle"></i> حظر العميل</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <label class="form-label">سبب الحظر <span class="text-danger">*</span></label>
                <textarea name="reason" class="form-control" rows="3" required maxlength="255"
                          placeholder="اشرح سبب حظر هذا العميل…"></textarea>
            </div>
            <div class="modal-footer">
                <button class="btn btn-warning"><i class="bi bi-slash-circle"></i> احظر</button>
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">إلغاء</button>
            </div>
        </form>
    </div>
</div>
@endcan

@push('styles')
<style>
    .cust-profile {
        background: #fff;
        border: 1px solid #f3f4f6;
        border-radius: 14px;
        overflow: hidden;
        box-shadow: 0 2px 8px rgba(0, 0, 0, .04);
    }
    .cust-profile__head {
        padding: 24px 16px;
        text-align: center;
        background: linear-gradient(135deg,
            hsla(var(--hue, 150), 50%, 96%, 1) 0%,
            hsla(var(--hue, 150), 50%, 92%, 1) 100%);
    }
    .cust-profile__avatar {
        width: 84px; height: 84px;
        margin: 0 auto 12px;
        border-radius: 50%;
        background: linear-gradient(135deg,
            hsl(var(--hue, 150) 55% 50%) 0%,
            hsl(var(--hue, 150) 60% 36%) 100%);
        color: #fff;
        font-size: 2.4rem;
        font-weight: 800;
        display: flex; align-items: center; justify-content: center;
        box-shadow: 0 4px 12px hsla(var(--hue, 150), 50%, 30%, .25);
    }
    .cust-profile__name {
        font-weight: 800;
        color: #111827;
        margin: 0;
    }
    .cust-profile__body { padding: 16px; }
    .cust-info {
        margin: 0;
        display: grid;
        grid-template-columns: 110px 1fr;
        gap: 8px 12px;
        font-size: .85rem;
    }
    .cust-info dt {
        color: #6b7280;
        font-weight: 600;
        white-space: nowrap;
    }
    .cust-info dt i { color: #9ca3af; font-size: .78rem; margin-inline-end: 4px; }
    .cust-info dd { margin: 0; color: #111827; font-weight: 600; }
    .cust-info dd a { color: #111827; text-decoration: none; }
    .cust-info dd a:hover { color: rgb(var(--primary-rgb)); }

    .cust-branch-stat {
        display: inline-flex; align-items: center; gap: 8px;
        padding: 6px 10px;
        background: #f9fafb;
        border: 1px solid #f3f4f6;
        border-radius: 8px;
    }
    .cust-branch-stat__count {
        font-size: .78rem;
        font-weight: 700;
        color: #6b7280;
        background: #fff;
        padding: 2px 8px;
        border-radius: 99px;
        border: 1px solid #e5e7eb;
    }

    .cust-reviews { display: flex; flex-direction: column; gap: 12px; }
    .cust-review {
        padding: 12px;
        background: #fafafa;
        border: 1px solid #f3f4f6;
        border-radius: 10px;
    }
    .cust-review__head {
        display: flex; align-items: center; gap: 10px;
        margin-bottom: 8px;
    }
    .cust-review__rating { color: #f59e0b; font-size: .9rem; }
    .cust-review__body { margin: 0; color: #374151; font-size: .88rem; line-height: 1.6; }
</style>
@endpush
@endsection
