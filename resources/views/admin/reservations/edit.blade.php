@extends('layouts.admin')
@section('title', "حجز {$reservation->reference}")

@section('content')
<x-admin.breadcrumb :title="'حجز '.$reservation->reference" icon="bi-calendar-event"
    :crumbs="[['label' => 'الحجوزات', 'url' => route('admin.reservations.index')]]" />

<div class="row g-3">
    {{-- ─── Reservation summary ──────────────────────────────── --}}
    <div class="col-lg-7">
        <x-admin.data-panel title="تفاصيل الحجز" icon="bi-info-circle">
            <div class="row g-3">
                <div class="col-md-6">
                    <small class="text-muted">رقم الحجز</small>
                    <div class="fw-bold"><code>{{ $reservation->reference }}</code></div>
                </div>
                <div class="col-md-6">
                    <small class="text-muted">الحالة</small>
                    <div><span class="badge bg-{{ $reservation->status->color() }}">{{ $reservation->status->label() }}</span></div>
                </div>
                <div class="col-md-6">
                    <small class="text-muted">الموعد</small>
                    <div class="fw-bold">{{ $reservation->reserved_for->format('Y/m/d H:i') }}</div>
                </div>
                <div class="col-md-6">
                    <small class="text-muted">عدد الضيوف</small>
                    <div class="fw-bold">{{ $reservation->party_size }} شخص</div>
                </div>
                @if($reservation->customer_notes)
                    <div class="col-12">
                        <small class="text-muted">ملاحظات الزبون</small>
                        <div>{{ $reservation->customer_notes }}</div>
                    </div>
                @endif
                @if($reservation->confirmed_at)
                    <div class="col-md-6">
                        <small class="text-muted">تأكيد</small>
                        <div>{{ $reservation->confirmed_at->format('Y/m/d H:i') }} — {{ $reservation->confirmedBy?->name ?? 'تلقائي' }}</div>
                    </div>
                @endif
                @if($reservation->cancelled_at)
                    <div class="col-md-6">
                        <small class="text-muted">إلغاء</small>
                        <div>{{ $reservation->cancelled_at->format('Y/m/d H:i') }} — {{ $reservation->cancelledBy?->name ?? '—' }}</div>
                        @if($reservation->cancelled_reason)
                            <small class="text-muted">{{ $reservation->cancelled_reason }}</small>
                        @endif
                    </div>
                @endif
            </div>
        </x-admin.data-panel>

        {{-- Editable fields --}}
        <div class="mt-3">
            <x-admin.data-panel title="إعدادات الحجز" icon="bi-pencil-square">
                <form method="POST" action="{{ route('admin.reservations.update', $reservation) }}">
                    @csrf @method('PUT')
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">الطاولة المخصَّصة</label>
                            <select name="table_id" class="form-select">
                                <option value="">— لاحقاً —</option>
                                @foreach($tables as $t)
                                    <option value="{{ $t->id }}" @selected($reservation->table_id == $t->id)>
                                        طاولة {{ $t->number }} ({{ $t->capacity }} أشخاص)
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">المدة (دقائق)</label>
                            <input type="number" name="duration_minutes" class="form-control"
                                   value="{{ $reservation->duration_minutes }}" min="15" max="600">
                        </div>
                        <div class="col-12">
                            <label class="form-label">ملاحظات داخلية (للموظفين فقط)</label>
                            <textarea name="internal_notes" class="form-control" rows="2"
                                      placeholder="مثلاً: زبون VIP، يفضّل الطاولة الزاوية…">{{ $reservation->internal_notes }}</textarea>
                        </div>
                    </div>
                    <div class="d-flex justify-content-end gap-2 mt-3">
                        <a href="{{ route('admin.reservations.index') }}" class="btn btn-light">رجوع</a>
                        <button class="btn btn-primary"><i class="bi bi-save me-1"></i> حفظ</button>
                    </div>
                </form>
            </x-admin.data-panel>
        </div>
    </div>

    {{-- ─── Customer + actions ───────────────────────────────── --}}
    <div class="col-lg-5">
        <x-admin.data-panel title="الزبون" icon="bi-person">
            <div class="d-flex align-items-center gap-3 mb-2">
                <div style="width:48px; height:48px; border-radius:12px; background: rgba(46,125,50,.1); color: rgb(var(--primary-rgb)); display:inline-flex; align-items:center; justify-content:center; font-weight:800; font-size:1.2rem;">
                    {{ mb_substr($reservation->customer->name ?? '?', 0, 1, 'UTF-8') }}
                </div>
                <div>
                    <div class="fw-bold">{{ $reservation->customer->name ?? '—' }}</div>
                    <div class="text-muted small" dir="ltr">{{ $reservation->customer->phone ?? '' }}</div>
                    @if($reservation->customer?->email)
                        <div class="text-muted small" dir="ltr">{{ $reservation->customer->email }}</div>
                    @endif
                </div>
            </div>
        </x-admin.data-panel>

        @unless($reservation->status->isFinal())
            <div class="mt-3">
                <x-admin.data-panel title="إجراءات" icon="bi-lightning">
                    <div class="d-grid gap-2">
                        @if($reservation->status === \App\Enums\ReservationStatus::Pending)
                            <form action="{{ route('admin.reservations.confirm', $reservation) }}" method="POST">
                                @csrf
                                <button class="btn btn-success w-100">
                                    <i class="bi bi-check-circle"></i> تأكيد الحجز
                                </button>
                            </form>
                        @endif

                        @if($reservation->status === \App\Enums\ReservationStatus::Confirmed)
                            <form action="{{ route('admin.reservations.seat', $reservation) }}" method="POST">
                                @csrf
                                <button class="btn btn-info text-white w-100">
                                    <i class="bi bi-people"></i> تسجيل الجلوس
                                </button>
                            </form>
                            <form action="{{ route('admin.reservations.no-show', $reservation) }}"
                                  method="POST"
                                  onsubmit="return confirm('تسجيل عدم الحضور لهذا الحجز؟');">
                                @csrf
                                <button class="btn btn-outline-dark w-100">
                                    <i class="bi bi-person-x"></i> لم يحضر
                                </button>
                            </form>
                        @endif

                        @if($reservation->status === \App\Enums\ReservationStatus::Seated)
                            <form action="{{ route('admin.reservations.complete', $reservation) }}" method="POST">
                                @csrf
                                <button class="btn btn-secondary w-100">
                                    <i class="bi bi-check2-all"></i> إنهاء الحجز
                                </button>
                            </form>
                        @endif

                        <form action="{{ route('admin.reservations.cancel', $reservation) }}"
                              method="POST"
                              onsubmit="return confirm('إلغاء هذا الحجز؟');">
                            @csrf
                            <input type="text" name="cancelled_reason" class="form-control mb-2"
                                   placeholder="سبب الإلغاء (اختياري)">
                            <button class="btn btn-outline-danger w-100">
                                <i class="bi bi-x-circle"></i> إلغاء الحجز
                            </button>
                        </form>
                    </div>
                </x-admin.data-panel>
            </div>
        @endunless
    </div>
</div>
@endsection
