@extends('layouts.admin')
@section('title', 'استهلاك سريع للموظفين')

@section('content')
<x-admin.breadcrumb
    title="استهلاك سريع للموظفين"
    icon="bi-cup-straw"
    subtitle="لتسجيل ما أخذه الموظفون أثناء العمل من البار/المطبخ (كولا، مية، صحون سريعة) — بدون طاولة"
    :crumbs="[['label' => 'بدل وجبات الموظفين', 'url' => route('admin.staff-meals.index')]]" />

<div class="alert alert-info d-flex align-items-start gap-3 mb-3">
    <i class="bi bi-info-circle-fill fs-4 mt-1"></i>
    <div>
        <strong class="d-block mb-1">متى تستخدم هذه الصفحة؟</strong>
        لتسجيل استهلاك سريع لموظف (كولا/مية/سندويتش بسرعة) بدون أن يجلس على طاولة وبدون أن يمر بالمطبخ.
        السعر يُحفظ <strong>كما هو في القائمة لحظة التسجيل</strong> — لا يتأثر لاحقاً لو تغيّر سعر القائمة.
        المخزون ينقص فوراً، والمبلغ يُضاف على بدل وجبات الموظف.
    </div>
</div>

@if($employees->isEmpty())
    <div class="alert alert-warning">
        لا يوجد موظفون لديهم بدل وجبات مفعّل.
        اذهب إلى <a href="{{ route('admin.users.index') }}">إدارة المستخدمين</a>
        وعدّل "بدل الوجبات الشهري" للموظف أولاً.
    </div>
@else
    <form action="{{ route('admin.staff-meals.quick_consume.store') }}" method="POST"
          x-data="quickConsumeForm()">
        @csrf

        <div class="row g-3">
            {{-- ── LEFT: Menu picker ──────────────────────────────── --}}
            <div class="col-lg-8">
                <div class="card mb-3">
                    <div class="card-header bg-light">
                        <strong><i class="bi bi-list-ul"></i> اختر الأصناف</strong>
                    </div>
                    <div class="card-body p-0">
                        @forelse($categories as $cat)
                            <div class="border-bottom">
                                <div class="px-3 py-2 bg-light fw-bold">
                                    {{ $cat->name }}
                                    <small class="text-muted">({{ $cat->menuItems->count() }})</small>
                                </div>
                                @foreach($cat->menuItems as $item)
                                    @php
                                        $shortages = $item->stockShortages(1.0);
                                        $inStock   = empty($shortages);
                                    @endphp
                                    <div class="d-flex align-items-center justify-content-between px-3 py-2 border-top {{ $inStock ? '' : 'bg-light opacity-50' }}">
                                        <div class="flex-grow-1">
                                            <strong>{{ $item->name }}</strong>
                                            @if(! $inStock)
                                                <span class="badge bg-warning text-dark">نفد</span>
                                            @endif
                                        </div>
                                        <div class="d-flex align-items-center gap-2">
                                            <span class="fw-bold text-primary">{{ \App\Helpers\Money::format($item->price) }}</span>
                                            @if($inStock)
                                                <button type="button" class="btn btn-sm btn-outline-primary"
                                                        @click="addItem({{ $item->id }}, {{ \Illuminate\Support\Js::from($item->name) }}, {{ (float) $item->price }})">
                                                    <i class="bi bi-plus-lg"></i>
                                                </button>
                                            @else
                                                <button class="btn btn-sm btn-light" disabled>نفد</button>
                                            @endif
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        @empty
                            <div class="text-center py-4 text-muted">لا توجد أصناف متاحة في القائمة.</div>
                        @endforelse
                    </div>
                </div>
            </div>

            {{-- ── RIGHT: Employee + cart + submit ───────────────── --}}
            <div class="col-lg-4">
                <div class="card sticky-top" style="top: 1rem;">
                    <div class="card-header bg-warning">
                        <strong><i class="bi bi-cup-hot-fill"></i> تفاصيل الاستهلاك</strong>
                    </div>
                    <div class="card-body">
                        <label class="form-label fw-bold">
                            الموظف <span class="text-danger">*</span>
                        </label>
                        <select name="user_id" x-model.number="selectedStaff" class="form-select mb-3" required>
                            <option value="">— اختر الموظف —</option>
                            @foreach($employees as $emp)
                                <option value="{{ $emp->id }}">
                                    {{ $emp->name }} (حد: {{ number_format((float) $emp->monthly_meal_allowance, 0) }} ش.إ/شهر)
                                </option>
                            @endforeach
                        </select>

                        <label class="form-label fw-bold">الأصناف المختارة</label>
                        <template x-if="lines.length === 0">
                            <div class="text-center text-muted py-3 small">
                                <i class="bi bi-arrow-left"></i>
                                اضغط <strong>+</strong> بجنب أي صنف بالقائمة
                            </div>
                        </template>
                        <template x-for="(line, idx) in lines" :key="line.key">
                            <div class="d-flex align-items-center gap-2 mb-2 p-2 border rounded">
                                <input type="hidden" :name="`lines[${idx}][menu_item_id]`" :value="line.menu_item_id">
                                <div class="flex-grow-1">
                                    <small class="fw-bold" x-text="line.name"></small>
                                    <div class="small text-muted">
                                        <span x-text="line.unit_price.toFixed(2)"></span> × <span x-text="line.quantity"></span>
                                        = <strong x-text="(line.unit_price * line.quantity).toFixed(2)"></strong> ش.إ
                                    </div>
                                </div>
                                <input type="number" :name="`lines[${idx}][quantity]`"
                                       x-model.number="line.quantity" min="1" max="99"
                                       class="form-control form-control-sm text-center" style="width:60px;">
                                <button type="button" class="btn btn-sm btn-link text-danger p-0"
                                        @click="removeLine(idx)">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </div>
                        </template>

                        <template x-if="lines.length > 0">
                            <div class="border-top mt-3 pt-3">
                                <div class="d-flex justify-content-between fs-5">
                                    <strong>المجموع:</strong>
                                    <strong class="text-primary" x-text="total().toFixed(2) + ' ش.إ'"></strong>
                                </div>
                                <small class="text-muted">بدون رسوم خدمة</small>
                            </div>
                        </template>

                        <div class="mt-3">
                            <label class="form-label small text-muted">ملاحظة (اختياري)</label>
                            <input type="text" name="notes" class="form-control form-control-sm"
                                   placeholder="مثلاً: خلال نوبة الظهر">
                        </div>

                        {{-- Gift toggle — when checked, the order is recorded
                             but the amount is NOT added to the employee's tab.
                             Used for birthdays, performance rewards, etc. --}}
                        <div class="mt-3 p-2 border border-success rounded bg-light">
                            <input type="hidden" name="is_gift" value="0">
                            <div class="form-check form-switch">
                                <input type="checkbox" id="is_gift" name="is_gift" value="1"
                                       class="form-check-input" x-model="isGift">
                                <label for="is_gift" class="form-check-label fw-bold text-success">
                                    <i class="bi bi-gift-fill"></i> وجبة مجانية — هدية للموظف
                                </label>
                            </div>
                            <small class="text-muted d-block mt-1">
                                لو فعّلت هذا الخيار، الطلب يُحفظ كهدية ولا يُحسب على بدل وجبات الموظف.
                            </small>
                            <template x-if="isGift">
                                <input type="text" name="gift_reason" class="form-control form-control-sm mt-2"
                                       placeholder="السبب (اختياري): عيد ميلاد، موظف الشهر، ترحيب…">
                            </template>
                        </div>

                        <button class="btn w-100 mt-3"
                                :class="isGift ? 'btn-success' : 'btn-warning'"
                                :disabled="lines.length === 0 || ! selectedStaff">
                            <i class="bi" :class="isGift ? 'bi-gift-fill' : 'bi-check-circle-fill'"></i>
                            <span x-text="isGift ? 'تسجيل وجبة مجانية' : 'تأكيد الاستهلاك'"></span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </form>
@endif

@push('scripts')
<script>
function quickConsumeForm() {
    return {
        selectedStaff: '',
        lines: [],
        nextKey: 1,
        // Mirrors the "وجبة مجانية" toggle — flips the submit button
        // label/color and exposes the reason input.
        isGift: false,

        addItem(id, name, unitPrice) {
            // If already in cart, just bump the quantity.
            const existing = this.lines.find(l => l.menu_item_id === id);
            if (existing) {
                existing.quantity = Math.min(99, existing.quantity + 1);
                return;
            }
            this.lines.push({
                key: this.nextKey++,
                menu_item_id: id,
                name,
                unit_price: unitPrice,
                quantity: 1,
            });
        },
        removeLine(idx) { this.lines.splice(idx, 1); },
        total() {
            return this.lines.reduce((sum, l) => sum + (l.unit_price * (l.quantity || 1)), 0);
        },
    };
}
</script>
@endpush
@endsection
