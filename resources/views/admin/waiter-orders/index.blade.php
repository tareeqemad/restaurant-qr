@extends('layouts.admin')
@section('title', 'إنشاء طلب — الجرسون')

@section('content')
<x-admin.breadcrumb
    title="إنشاء طلب على طاولة"
    icon="bi-clipboard-plus"
    subtitle="اختر الطاولة لتبدأ بإدخال الطلبات يدوياً — لزبائن بدون QR / جوال" />

<div class="alert alert-info d-flex align-items-start gap-3 mb-3">
    <i class="bi bi-info-circle-fill fs-4 mt-1"></i>
    <div>
        اختر طاولة لتبدأ بإضافة الأصناف. إذا الطاولة لها جلسة نشطة، راح تتم إضافة الطلب لها.
        إذا فاضية، النظام يفتح جلسة جديدة تلقائياً.
    </div>
</div>

<div class="row g-3">
    @forelse($tables as $t)
        @php
            $hasSession = (bool) $t->activeSession;
            $statusLabel = match ($t->status) {
                'available' => ['متاحة', 'success', 'bi-check-circle'],
                'occupied'  => ['مشغولة', 'warning', 'bi-people'],
                'reserved'  => ['محجوزة', 'info', 'bi-calendar-check'],
                'disabled'  => ['معطّلة', 'secondary', 'bi-slash-circle'],
                default     => [$t->status, 'secondary', 'bi-question-circle'],
            };
            $disabled = $t->status === 'disabled';
        @endphp
        <div class="col-md-4 col-lg-3">
            <div class="card h-100 {{ $disabled ? 'opacity-50' : '' }}"
                 style="border-radius: 14px; border: 2px solid {{ $hasSession ? '#f59e0b' : ($t->status === 'available' ? '#10b981' : '#e2e8f0') }};">
                <div class="card-body d-flex flex-column align-items-center text-center p-3">
                    <div style="font-size: 2.5rem; line-height: 1; font-weight: 900; color: #0f4731;">
                        {{ $t->number }}
                    </div>
                    @if($t->name)
                        <small class="text-muted">{{ $t->name }}</small>
                    @endif
                    <small class="text-muted mb-2">سعة: {{ $t->capacity }}</small>

                    <span class="badge bg-{{ $statusLabel[1] }} mb-2">
                        <i class="bi {{ $statusLabel[2] }}"></i>
                        {{ $statusLabel[0] }}
                    </span>

                    @if($hasSession)
                        <div class="small text-muted mb-2">
                            <i class="bi bi-clock-history"></i>
                            جلسة مفتوحة منذ {{ $t->activeSession->opened_at?->diffForHumans() }}
                        </div>
                    @endif

                    @if($disabled)
                        <button class="btn btn-light btn-sm w-100" disabled>غير قابلة للطلب</button>
                    @else
                        <a href="{{ route('admin.waiter-orders.create', $t) }}"
                           class="btn btn-{{ $hasSession ? 'warning' : 'primary' }} btn-sm w-100">
                            <i class="bi bi-plus-circle"></i>
                            {{ $hasSession ? 'إضافة طلب' : 'بدء جلسة + طلب' }}
                        </a>
                    @endif
                </div>
            </div>
        </div>
    @empty
        <div class="col-12">
            <div class="text-center py-5 text-muted">
                <i class="bi bi-table fs-1 d-block mb-2"></i>
                لا توجد طاولات مفعّلة. اطلب من المدير إضافة طاولات أولاً.
            </div>
        </div>
    @endforelse
</div>
@endsection
