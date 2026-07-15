{{--
    Cashier "apply discount" panel — reused under both the remote (portal /
    takeaway / delivery) order detail and the in-restaurant session detail.
    Lives inside the Livewire dashboard component, so it shares state with
    its parent (`discountOpen`, `discountType`, etc.) and dispatches against
    the same OrderDiscountService.

    Shows three sections:
      1. List of currently-applied discounts (with × to remove if the user
         has the permission and the invoice is still mutable).
      2. "Add discount" trigger — collapsed by default; expands inline.
      3. The form itself — type/value, category, reason. Submits through
         submitDiscount() which routes to applyToOrder or applyToSession
         based on which selection is active.

    The cap row exists so a cashier sees their ceiling before typing — saves
    a server round-trip to discover "you can't do 30%". `null` cap means
    owner-level, which is uncapped.
--}}

@php
    // $invoice is supplied by the surrounding scope (the dashboard template
    // already binds it whether it's a session invoice or a remote-order
    // invoice). Treat null as "not yet issued" — discount still applies on
    // the underlying Order(s).
    $cap = $this->userDiscountCap;
    $discounts = $this->activeDiscounts;
    $panelInvoice = $invoice ?? null;
    $invoiceLocked = $panelInvoice && in_array($panelInvoice->status, ['paid', 'cancelled', 'unpaid_writeoff'], true);
    $canApply = auth()->user()?->can('apply', \App\Models\OrderDiscount::class);
@endphp

<div class="cx-section cx-discount-section">
    <div class="cx-section-head d-flex align-items-center justify-content-between">
        <strong><i class="bi bi-percent"></i> الخصومات</strong>
        @if($cap)
            <small class="text-muted" style="font-size:.72rem;">
                حد دورك: حتى {{ rtrim(rtrim(number_format((float) $cap['percent'], 2), '0'), '.') }}% أو {{ \App\Helpers\Money::format($cap['fixed']) }}
            </small>
        @else
            <small class="text-success" style="font-size:.72rem;">بدون حد</small>
        @endif
    </div>

    @if($discounts->count())
        <div class="cx-discount-list">
            @foreach($discounts as $d)
                <div class="cx-discount-row" wire:key="cx-disc-{{ $d->id }}">
                    <div class="cx-discount-row__main">
                        <div class="d-flex align-items-center gap-2">
                            <span class="cx-discount-pill">
                                @if($d->type === 'percent')
                                    {{ rtrim(rtrim(number_format((float) $d->value, 2), '0'), '.') }}%
                                @else
                                    {{ \App\Helpers\Money::format($d->value) }}
                                @endif
                            </span>
                            <strong>{{ $d->name_snapshot }}</strong>
                        </div>
                        <small class="text-muted d-block">
                            {{ $d->categoryLabel() }}
                            @if($d->_order_number ?? null)
                                · طلب {{ $d->_order_number }}
                            @endif
                            @if($d->appliedBy)
                                · {{ $d->appliedBy->name }}
                            @endif
                            · {{ $d->created_at?->format('H:i') }}
                        </small>
                        @if(trim((string) $d->reason) !== '')
                            <small class="d-block" style="color:#475569;">
                                <i class="bi bi-chat-quote"></i> {{ $d->reason }}
                            </small>
                        @endif
                    </div>
                    <div class="cx-discount-row__amount">
                        <strong class="text-success">−{{ \App\Helpers\Money::format($d->amount) }}</strong>
                        @if(! $invoiceLocked && auth()->user()?->can('remove', $d))
                            <button type="button"
                                    wire:click="removeDiscount({{ $d->id }})"
                                    wire:confirm="إزالة هذا الخصم؟"
                                    title="إزالة"
                                    class="cx-discount-remove">
                                <i class="bi bi-x-lg"></i>
                            </button>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    @if($invoiceLocked)
        <div class="cx-discount-locked">
            <i class="bi bi-lock-fill"></i>
            الفاتورة مغلقة — لا يمكن تعديل الخصومات.
        </div>
    @elseif($canApply)
        @if(! $discountOpen)
            <button type="button" wire:click="openDiscount" class="cx-btn-discount">
                <i class="bi bi-plus-circle-fill"></i>
                <span>إضافة خصم</span>
                <small class="text-muted">للزبون الدائم · شكوى · موافقة مدير</small>
            </button>
        @else
            <div class="cx-discount-form">
                <div class="cx-discount-types">
                    <button type="button" wire:click="$set('discountType', 'percent')"
                            class="cx-discount-type {{ $discountType === 'percent' ? 'is-active' : '' }}">
                        <i class="bi bi-percent"></i>
                        <span>نسبة %</span>
                    </button>
                    <button type="button" wire:click="$set('discountType', 'fixed')"
                            class="cx-discount-type {{ $discountType === 'fixed' ? 'is-active' : '' }}">
                        <i class="bi bi-cash"></i>
                        <span>مبلغ ثابت</span>
                    </button>
                </div>

                <div class="cx-discount-field">
                    <label>القيمة</label>
                    <div class="cx-discount-value-wrap">
                        <input type="number" step="0.01" min="0.01"
                               wire:model.blur="discountValue"
                               class="form-control"
                               placeholder="{{ $discountType === 'percent' ? 'مثلاً 10' : '0.00' }}">
                        <span class="cx-discount-value-suffix">
                            {{ $discountType === 'percent' ? '%' : \App\Models\Setting::get('currency_symbol', config('restaurant.currency_symbol')) }}
                        </span>
                    </div>
                    @error('discountValue') <small class="text-danger d-block">{{ $message }}</small> @enderror
                </div>

                <div class="cx-discount-field">
                    <label>السبب (التصنيف)</label>
                    <select wire:model="discountCategoryLookupId" class="form-select">
                        @foreach($this->discountCategories as $cat)
                            <option value="{{ $cat->id }}">{{ $cat->label }}</option>
                        @endforeach
                    </select>
                    <small class="text-muted d-block mt-1" style="font-size:.7rem;">
                        <i class="bi bi-info-circle"></i>
                        لإدارة التصنيفات: <a href="{{ route('admin.lookups.index') }}#group=discount_categories" target="_blank">شاشة الثوابت</a>
                    </small>
                </div>

                <div class="cx-discount-field">
                    <label>تفاصيل السبب <span class="text-danger">*</span></label>
                    <textarea wire:model.blur="discountReason"
                              class="form-control" rows="2"
                              placeholder="مثلاً: زبون دائم منذ سنتين / تعويض عن تأخر التوصيل / وافق المدير محمد"></textarea>
                    @error('discountReason') <small class="text-danger d-block">{{ $message }}</small> @enderror
                </div>

                <div class="cx-discount-field">
                    <label>اسم الخصم على الفاتورة (اختياري)</label>
                    <input type="text" wire:model.blur="discountName"
                           class="form-control" maxlength="120"
                           placeholder="افتراضي: خصم {{ $discountType === 'percent' ? '%' : 'مبلغ' }}">
                </div>

                <div class="d-flex gap-2 mt-3">
                    <button type="button" wire:click="closeDiscount" class="btn btn-light flex-grow-1">تراجع</button>
                    <button type="button" wire:click="submitDiscount" wire:loading.attr="disabled"
                            class="btn btn-success" style="flex: 2;">
                        <i class="bi bi-check-circle-fill"></i>
                        <span wire:loading.remove wire:target="submitDiscount">تطبيق الخصم</span>
                        <span wire:loading wire:target="submitDiscount">جاري...</span>
                    </button>
                </div>
            </div>
        @endif
    @endif
</div>

@once
<style>
.cx-discount-section { background: #fafdfa; border-color: #d6eadc; }
.cx-discount-section .cx-section-head { background: linear-gradient(180deg, #ecf6ee, #f7fbf8); }
.cx-discount-list { padding: 6px 12px; display: flex; flex-direction: column; gap: 6px; }
.cx-discount-row {
    display: flex; align-items: flex-start; justify-content: space-between; gap: 10px;
    padding: 8px 10px; background: #fff; border: 1px solid #e6efe9; border-radius: 8px;
}
.cx-discount-row__main { flex: 1; min-width: 0; }
.cx-discount-row__amount { display: flex; align-items: center; gap: 6px; flex-shrink: 0; }
.cx-discount-pill {
    display: inline-block; padding: 2px 8px; border-radius: 999px; font-size: .72rem;
    font-weight: 700; background: #d4f1dd; color: #14532d; min-width: 44px; text-align: center;
}
.cx-discount-remove {
    width: 26px; height: 26px; border-radius: 6px; border: 1px solid #fee2e2;
    background: #fef2f2; color: #b91c1c; cursor: pointer; transition: all .15s;
    display: flex; align-items: center; justify-content: center; font-size: .75rem;
}
.cx-discount-remove:hover { background: #fee2e2; transform: scale(1.05); }
.cx-discount-locked {
    padding: 10px 14px; font-size: .82rem; color: #475569; background: #f8fafc;
    border-top: 1px solid #e2e8f0; display: flex; align-items: center; gap: 8px;
}
.cx-btn-discount {
    width: 100%; margin: 8px 0; padding: 10px 14px; background: #fff;
    border: 1px dashed #b6d6c0; border-radius: 8px; color: #14532d;
    cursor: pointer; transition: all .15s;
    display: flex; align-items: center; gap: 10px; text-align: right;
}
.cx-btn-discount:hover { border-color: #14532d; background: #f0fdf4; }
.cx-btn-discount i { font-size: 1.2rem; }
.cx-btn-discount span { font-weight: 600; flex-shrink: 0; }
.cx-btn-discount small { font-size: .7rem; }
.cx-discount-form { padding: 12px; display: flex; flex-direction: column; gap: 10px; background: #f9fbf9; border-top: 1px solid #e6efe9; }
.cx-discount-types { display: grid; grid-template-columns: 1fr 1fr; gap: 6px; }
.cx-discount-type {
    padding: 10px 8px; background: #fff; border: 1px solid #d6eadc; border-radius: 8px;
    cursor: pointer; transition: all .15s; text-align: center;
    display: flex; flex-direction: column; align-items: center; gap: 4px;
    font-size: .82rem; color: #334155;
}
.cx-discount-type i { font-size: 1.1rem; color: #64748b; }
.cx-discount-type:hover { border-color: #14532d; }
.cx-discount-type.is-active { background: #14532d; color: #fff; border-color: #14532d; }
.cx-discount-type.is-active i { color: #fff; }
.cx-discount-field label { display: block; font-size: .75rem; color: #475569; margin-bottom: 3px; font-weight: 600; }
.cx-discount-value-wrap { position: relative; }
.cx-discount-value-suffix {
    position: absolute; left: 10px; top: 50%; transform: translateY(-50%);
    font-weight: 700; color: #64748b; pointer-events: none;
}
[dir="rtl"] .cx-discount-value-wrap input { padding-left: 32px; }
</style>
@endonce
