@extends('layouts.admin')
@section('title', $transfer->number)

@section('content')
<x-admin.breadcrumb
    title="تحويل {{ $transfer->number }}"
    icon="bi-truck"
    subtitle="{{ $transfer->fromBranch->name }} ← → {{ $transfer->toBranch->name }} · {{ $transfer->statusLabel() }}" />

<div class="row g-3">
    <div class="col-lg-8">
        <x-admin.data-panel title="بنود التحويل" icon="bi-list-ul" :count="$transfer->items->count()">
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead class="bg-light">
                        <tr>
                            <th>المكوّن</th>
                            <th class="text-end">الكمية</th>
                            <th>من موقع</th>
                            <th>إلى موقع</th>
                            <th class="text-end">سعر/وحدة</th>
                            <th class="text-end">قيمة السطر</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($transfer->items as $item)
                            <tr>
                                <td class="fw-semibold">{{ $item->ingredient->name }}</td>
                                <td class="text-end">
                                    {{ number_format((float) $item->quantity_base, 4) }}
                                    <small class="text-muted">{{ $item->ingredient->baseUnit?->code }}</small>
                                </td>
                                <td class="fs-13">{{ $item->fromLocation?->name ?? '—' }}</td>
                                <td class="fs-13">{{ $item->toLocation?->name ?? '—' }}</td>
                                <td class="text-end text-muted">{{ number_format((float) $item->unit_cost, 4) }} ₪</td>
                                <td class="text-end fw-semibold text-primary">{{ number_format($item->lineCost(), 2) }} ₪</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot class="bg-light">
                        <tr>
                            <td colspan="5" class="text-end fw-bold">إجمالي قيمة التحويل</td>
                            <td class="text-end fw-bold fs-18 text-primary">
                                {{ number_format($transfer->items->sum(fn($i) => $i->lineCost()), 2) }} ₪
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </x-admin.data-panel>

        @if($transfer->notes || $transfer->cancel_reason)
            <x-admin.data-panel title="ملاحظات" icon="bi-chat-left-text">
                @if($transfer->notes)
                    <div class="mb-2">
                        <strong>ملاحظة الإنشاء:</strong>
                        <p class="mb-0 text-muted">{{ $transfer->notes }}</p>
                    </div>
                @endif
                @if($transfer->cancel_reason)
                    <div>
                        <strong>سبب الإلغاء:</strong>
                        <p class="mb-0 text-danger">{{ $transfer->cancel_reason }}</p>
                    </div>
                @endif
            </x-admin.data-panel>
        @endif
    </div>

    <div class="col-lg-4">
        <x-admin.data-panel title="الحالة" icon="bi-info-circle">
            <ul class="list-unstyled mb-0">
                <li class="d-flex justify-content-between py-2 border-bottom">
                    <span class="text-muted">الحالة الحالية</span>
                    <span class="badge bg-{{ $transfer->statusColor() }}">{{ $transfer->statusLabel() }}</span>
                </li>
                <li class="d-flex justify-content-between py-2 border-bottom">
                    <span class="text-muted">من</span>
                    <strong>{{ $transfer->fromBranch->name }}</strong>
                </li>
                <li class="d-flex justify-content-between py-2 border-bottom">
                    <span class="text-muted">إلى</span>
                    <strong>{{ $transfer->toBranch->name }}</strong>
                </li>
                <li class="d-flex justify-content-between py-2 border-bottom">
                    <span class="text-muted">أنشأه</span>
                    <span>{{ $transfer->creator?->name ?? '—' }}</span>
                </li>
                <li class="d-flex justify-content-between py-2 border-bottom">
                    <span class="text-muted">تاريخ الإنشاء</span>
                    <span class="fs-13">{{ $transfer->created_at->format('Y-m-d H:i') }}</span>
                </li>
                @if($transfer->sent_at)
                    <li class="d-flex justify-content-between py-2 border-bottom">
                        <span class="text-muted">أرسله</span>
                        <span>{{ $transfer->sender?->name ?? '—' }}</span>
                    </li>
                    <li class="d-flex justify-content-between py-2 border-bottom">
                        <span class="text-muted">تاريخ الإرسال</span>
                        <span class="fs-13">{{ $transfer->sent_at->format('Y-m-d H:i') }}</span>
                    </li>
                @endif
                @if($transfer->received_at)
                    <li class="d-flex justify-content-between py-2 border-bottom">
                        <span class="text-muted">استلمه</span>
                        <span>{{ $transfer->receiver?->name ?? '—' }}</span>
                    </li>
                    <li class="d-flex justify-content-between py-2 border-bottom">
                        <span class="text-muted">تاريخ الاستلام</span>
                        <span class="fs-13">{{ $transfer->received_at->format('Y-m-d H:i') }}</span>
                    </li>
                @endif
            </ul>
        </x-admin.data-panel>

        {{-- Action buttons depending on status --}}
        <div class="d-grid gap-2 mt-3">
            @if($transfer->isDraft())
                <form method="POST" action="{{ route('admin.branch-transfers.send', $transfer) }}"
                      onsubmit="return confirm('سيُخصم المخزون من فرع المصدر فوراً. متابعة؟');">
                    @csrf
                    <button class="btn btn-primary btn-lg w-100">
                        <i class="bi bi-send me-1"></i> إرسال (خصم من المصدر)
                    </button>
                </form>
            @endif

            @if($transfer->isInTransit())
                <form method="POST" action="{{ route('admin.branch-transfers.receive', $transfer) }}"
                      onsubmit="return confirm('تأكيد استلام البضاعة في فرعك؟');">
                    @csrf
                    <button class="btn btn-success btn-lg w-100">
                        <i class="bi bi-check-circle-fill me-1"></i> استلام في فرعنا
                    </button>
                </form>
            @endif

            @if($transfer->isDraft() || $transfer->isInTransit())
                <button type="button" class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#cancelModal">
                    <i class="bi bi-x-circle me-1"></i> إلغاء التحويل
                </button>
            @endif

            <a href="{{ route('admin.branch-transfers.index') }}" class="btn btn-light">
                <i class="bi bi-arrow-right"></i> القائمة
            </a>
        </div>
    </div>
</div>

@if($transfer->isDraft() || $transfer->isInTransit())
<div class="modal fade" id="cancelModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="{{ route('admin.branch-transfers.cancel', $transfer) }}">
                @csrf
                <div class="modal-header">
                    <h5>إلغاء التحويل {{ $transfer->number }}</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    @if($transfer->isInTransit())
                        <div class="alert alert-warning">
                            <i class="bi bi-info-circle"></i>
                            هذا التحويل في الطريق. سيتم <strong>إعادة المخزون</strong> لفرع المصدر تلقائياً.
                        </div>
                    @endif
                    <label class="form-label">سبب الإلغاء <span class="text-danger">*</span></label>
                    <textarea name="reason" class="form-control" rows="3" required></textarea>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">تراجع</button>
                    <button class="btn btn-danger">تأكيد الإلغاء</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endif
@endsection
