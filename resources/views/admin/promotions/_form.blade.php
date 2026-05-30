@csrf
@php
    $p = $promotion ?? null;
    $oldDays = old('days_of_week', $p?->days_of_week ?? []);
    $oldType = old('type', $p?->type ?? 'percent');
    $oldTarget = old('target_type', $p?->target_type ?? 'menu_item');
@endphp

<div class="card mb-3">
    <div class="card-header bg-light">
        <strong><i class="bi bi-info-circle"></i> الأساسيات</strong>
    </div>
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-8">
                <label class="form-label fw-bold">اسم العرض *</label>
                <input type="text" name="name" value="{{ old('name', $p?->name) }}"
                       class="form-control" required maxlength="200"
                       placeholder="مثلاً: عرض إفطار رمضان، خصم Happy Hour حلويات">
            </div>
            <div class="col-md-4">
                <label class="form-label fw-bold">الأولوية</label>
                <input type="number" name="priority" value="{{ old('priority', $p?->priority ?? 0) }}"
                       class="form-control" min="0" max="65535">
                <small class="text-muted">عند تطابق عدة عروض، الأعلى أولوية يفوز.</small>
            </div>
            <div class="col-12">
                <label class="form-label">وصف داخلي (اختياري)</label>
                <textarea name="description" rows="2" maxlength="500"
                          class="form-control">{{ old('description', $p?->description) }}</textarea>
            </div>
            <div class="col-md-6">
                <label class="form-label fw-bold">الفرع</label>
                <select name="branch_id" class="form-select">
                    <option value="">— كل الفروع (سلسلة) —</option>
                    @foreach($branches as $b)
                        <option value="{{ $b->id }}" @selected(old('branch_id', $p?->branch_id) == $b->id)>{{ $b->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-6 d-flex align-items-end">
                <div class="form-check form-switch fs-5">
                    <input type="hidden" name="active" value="0">
                    <input type="checkbox" id="active" name="active" value="1" class="form-check-input"
                           @checked(old('active', $p?->active ?? true))>
                    <label for="active" class="form-check-label fw-bold">العرض نشط</label>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="card mb-3" x-data="{ type: '{{ $oldType }}' }">
    <div class="card-header bg-light">
        <strong><i class="bi bi-percent"></i> نوع الخصم وقيمته</strong>
    </div>
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-6">
                <label class="form-label fw-bold">نوع الخصم *</label>
                <select name="type" x-model="type" class="form-select" required>
                    <option value="percent">نسبة مئوية (مثلاً 20%)</option>
                    <option value="sale_price">سعر عرض ثابت (مثلاً 25 بدل 30)</option>
                    <option value="fixed_off">خصم مبلغ ثابت (مثلاً −5 ش.إ)</option>
                </select>
            </div>
            <div class="col-md-6">
                <label class="form-label fw-bold">القيمة *</label>
                <div class="input-group">
                    <input type="number" name="value" value="{{ old('value', $p?->value) }}"
                           step="0.01" min="0" class="form-control" required>
                    <span class="input-group-text" x-show="type === 'percent'">%</span>
                    <span class="input-group-text" x-show="type !== 'percent'">ش.إ</span>
                </div>
                <small class="text-muted">
                    <span x-show="type === 'percent'">0 إلى 100. مثلاً: 20 = خصم 20%.</span>
                    <span x-show="type === 'sale_price'">السعر الجديد للصنف (يتجاوز سعر القائمة).</span>
                    <span x-show="type === 'fixed_off'">يُخصم من السعر الأصلي (الحد الأدنى صفر).</span>
                </small>
            </div>

            <div class="col-md-6">
                <label class="form-label">الحد الأدنى للفاتورة (اختياري)</label>
                <div class="input-group">
                    <input type="number" name="min_subtotal" value="{{ old('min_subtotal', $p?->min_subtotal) }}"
                           step="0.01" min="0" class="form-control" placeholder="50">
                    <span class="input-group-text">ش.إ</span>
                </div>
                <small class="text-muted">
                    العرض ما يطبّق إلا لو إجمالي الفاتورة وصل لهذا المبلغ. اتركه فارغاً = بدون حد أدنى.
                </small>
            </div>
            <div class="col-md-6">
                <label class="form-label">حد أقصى لعدد الطلبات (اختياري)</label>
                <div class="input-group">
                    <input type="number" name="usage_limit" value="{{ old('usage_limit', $p?->usage_limit) }}"
                           min="1" step="1" class="form-control" placeholder="100">
                    <span class="input-group-text">طلب</span>
                </div>
                <small class="text-muted">
                    «أول 100 زبون فقط». لو فاضي = استخدام غير محدود.
                    @if($p && $p->usage_count > 0)
                        <strong class="text-info d-block">
                            مستهلك حتى الآن: {{ $p->usage_count }}@if($p->usage_limit) / {{ $p->usage_limit }}@endif
                        </strong>
                    @endif
                </small>
            </div>
        </div>
    </div>
</div>

{{-- ── Audience filter (#15 birthday) ── --}}
<div class="card mb-3">
    <div class="card-header bg-light">
        <strong><i class="bi bi-person-heart"></i> الجمهور المستهدف</strong>
    </div>
    <div class="card-body">
        <select name="audience" class="form-select">
            @php $oldAud = old('audience', $p?->audience ?? 'everyone'); @endphp
            <option value="everyone"        @selected($oldAud === 'everyone')>الكل (الافتراضي)</option>
            <option value="birthday_month"  @selected($oldAud === 'birthday_month')>زبائن أعياد الميلاد فقط (يحتاج زبون مسجَّل بتاريخ ميلاد)</option>
        </select>
        <small class="text-muted d-block mt-1">
            <i class="bi bi-info-circle"></i>
            عرض عيد الميلاد يطبّق فقط إذا كان الزبون مسجلاً في النظام وتاريخ ميلاده في نفس شهر الطلب.
            الضيوف (غير المسجلين) لا يستفيدون.
        </small>
    </div>
</div>

{{-- ── #14 BXGY (Buy N Get M) ── --}}
<div class="card mb-3">
    <div class="card-header bg-light">
        <strong><i class="bi bi-gift"></i> عرض «اشترِ N خذ M مجاناً» (اختياري)</strong>
    </div>
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-6">
                <label class="form-label">اشترِ عدد</label>
                <input type="number" name="bxgy_buy_qty" value="{{ old('bxgy_buy_qty', $p?->bxgy_buy_qty) }}"
                       min="1" step="1" class="form-control" placeholder="2">
            </div>
            <div class="col-md-6">
                <label class="form-label">احصل على عدد مجاناً</label>
                <input type="number" name="bxgy_get_qty" value="{{ old('bxgy_get_qty', $p?->bxgy_get_qty) }}"
                       min="1" step="1" class="form-control" placeholder="1">
            </div>
            <div class="col-12">
                <small class="text-muted">
                    <i class="bi bi-info-circle"></i>
                    مثلاً: «اشترِ 2 خذ الثالث مجاناً». لو الفاتورة فيها 6 من نفس الصنف، الزبون يدفع 4 ويأخذ 2 مجاناً.
                    النظام يحتسب <strong>الأرخص</strong> من الأصناف المؤهلة كمجاني (أفضل خصم للزبون).
                    اتركهما فارغين لتعطيل هذه الميزة.
                </small>
            </div>
        </div>
    </div>
</div>

{{-- ── #16 Free modifier (cheese with burger) ── --}}
<div class="card mb-3">
    <div class="card-header bg-light">
        <strong><i class="bi bi-plus-square"></i> إضافات مجانية مع العرض (اختياري)</strong>
    </div>
    <div class="card-body">
        @php
            $oldFree = old('free_modifier_ids', $p?->free_modifier_ids ?? []);
            $oldFree = array_map('intval', is_array($oldFree) ? $oldFree : []);
        @endphp
        <select name="free_modifier_ids[]" class="form-select" multiple size="6">
            @foreach($modifiers ?? [] as $m)
                <option value="{{ $m->id }}" @selected(in_array((int) $m->id, $oldFree, true))>
                    {{ $m->name }}
                    @if($m->group) — {{ $m->group->name }} @endif
                    (+{{ number_format((float) $m->price_delta, 2) }} ش.إ)
                </option>
            @endforeach
        </select>
        <small class="text-muted d-block mt-1">
            <i class="bi bi-info-circle"></i>
            مثل «التشيز مجاناً مع البرغر». الإضافات المختارة هنا تظهر بسعر 0 لو الزبون اختارها مع الصنف المعروض.
            اضغط Ctrl للاختيار المتعدد.
        </small>
    </div>
</div>

<div class="card mb-3" x-data="{ target: '{{ $oldTarget }}' }">
    <div class="card-header bg-light">
        <strong><i class="bi bi-bullseye"></i> نطاق التطبيق</strong>
    </div>
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-4">
                <label class="form-label fw-bold">على *</label>
                <select name="target_type" x-model="target" class="form-select" required>
                    <option value="menu_item">صنف واحد محدد</option>
                    <option value="category">فئة كاملة من المنيو</option>
                </select>
            </div>
            <div class="col-md-8">
                <label class="form-label fw-bold">اختر *</label>
                <select name="target_id" class="form-select" required x-show="target === 'menu_item'">
                    <option value="">— اختر صنفاً —</option>
                    @foreach($menuItems as $mi)
                        <option value="{{ $mi->id }}" @selected(old('target_id', $p?->target_id) == $mi->id && $oldTarget === 'menu_item')>
                            {{ $mi->name }}
                            @if($mi->category) — {{ $mi->category->name }} @endif
                            ({{ number_format((float) $mi->price, 2) }} ش.إ)
                        </option>
                    @endforeach
                </select>
                <select name="target_id" class="form-select" required x-show="target === 'category'" x-cloak>
                    <option value="">— اختر فئة —</option>
                    @foreach($categories as $c)
                        <option value="{{ $c->id }}" @selected(old('target_id', $p?->target_id) == $c->id && $oldTarget === 'category')>{{ $c->name }}</option>
                    @endforeach
                </select>
            </div>

            {{-- Category exclusions — only visible when target_type=category.
                 Lets the admin say "all desserts 20% off EXCEPT الكنافة"
                 without crafting a competing higher-priority item promo. --}}
            <div class="col-12" x-show="target === 'category'" x-cloak>
                <label class="form-label">استثناءات (اختياري)</label>
                <select name="excluded_item_ids[]" class="form-select" multiple size="5">
                    @php $excluded = old('excluded_item_ids', $p?->excluded_item_ids ?? []); $excluded = array_map('intval', is_array($excluded) ? $excluded : []); @endphp
                    @foreach($menuItems as $mi)
                        <option value="{{ $mi->id }}" @selected(in_array((int) $mi->id, $excluded, true))>
                            {{ $mi->name }}
                            @if($mi->category) — {{ $mi->category->name }} @endif
                        </option>
                    @endforeach
                </select>
                <small class="text-muted">
                    الأصناف المختارة هنا تستثنى من خصم الفئة. مفيد للأصناف الغالية ضمن فئة منخفضة السعر.
                    اضغط Ctrl لاختيار عدة أصناف.
                </small>
            </div>
        </div>
    </div>
</div>

<div class="card mb-3">
    <div class="card-header bg-light">
        <strong><i class="bi bi-broadcast"></i> منصات التطبيق (اختياري)</strong>
    </div>
    <div class="card-body">
        @php
            $oldChannels = old('channels', $p?->channels ?? []);
            $oldChannels = array_map('strval', is_array($oldChannels) ? $oldChannels : []);
            $channelOptions = [
                'qr'        => ['QR Code (الزبون)',    'bi-qr-code'],
                'cashier'   => ['الكاشير',            'bi-shop'],
                'waiter'    => ['النادل',             'bi-person-workspace'],
                'phone'     => ['طلبات الهاتف',       'bi-telephone'],
                'delivery'  => ['التوصيل الخارجي',     'bi-bicycle'],
                'other'     => ['أخرى',                'bi-three-dots'],
            ];
        @endphp
        <div class="row g-2">
            @foreach($channelOptions as $code => [$name, $icon])
                <div class="col-md-4">
                    <label class="form-check border rounded p-2 w-100 d-flex align-items-center gap-2 mb-0"
                           style="cursor:pointer;">
                        <input type="checkbox" name="channels[]" value="{{ $code }}"
                               class="form-check-input m-0"
                               @checked(in_array($code, $oldChannels, true))>
                        <i class="bi {{ $icon }} text-primary"></i>
                        <span class="form-check-label fw-bold small">{{ $name }}</span>
                    </label>
                </div>
            @endforeach
        </div>
        <small class="text-muted d-block mt-2">
            <i class="bi bi-info-circle"></i>
            اتركها كلها فارغة = العرض يطبق على كل المنصات. حدّد منصة أو أكثر للحصر.
        </small>
    </div>
</div>

<div class="card mb-3">
    <div class="card-header bg-light">
        <strong><i class="bi bi-calendar-event"></i> الجدولة (اتركها كلها فارغة لعرض دائم)</strong>
    </div>
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-6">
                <label class="form-label">يبدأ من</label>
                <input type="datetime-local" name="starts_at"
                       value="{{ old('starts_at', $p?->starts_at?->format('Y-m-d\TH:i')) }}"
                       class="form-control">
                <small class="text-muted">اتركه فارغاً للبدء الفوري.</small>
            </div>
            <div class="col-md-6">
                <label class="form-label">ينتهي في</label>
                <input type="datetime-local" name="ends_at"
                       value="{{ old('ends_at', $p?->ends_at?->format('Y-m-d\TH:i')) }}"
                       class="form-control">
                <small class="text-muted">اتركه فارغاً لعرض بدون نهاية.</small>
            </div>

            <div class="col-12"><hr class="my-2"><h6 class="text-muted">Happy Hour (اختياري)</h6></div>
            <div class="col-md-3">
                <label class="form-label">من ساعة</label>
                @php $tf = $p?->time_from; if ($tf instanceof \Carbon\Carbon) $tf = $tf->format('H:i'); else $tf = $tf ? substr((string) $tf, 0, 5) : ''; @endphp
                <input type="time" name="time_from" value="{{ old('time_from', $tf) }}" class="form-control">
            </div>
            <div class="col-md-3">
                <label class="form-label">إلى ساعة</label>
                @php $tt = $p?->time_to; if ($tt instanceof \Carbon\Carbon) $tt = $tt->format('H:i'); else $tt = $tt ? substr((string) $tt, 0, 5) : ''; @endphp
                <input type="time" name="time_to" value="{{ old('time_to', $tt) }}" class="form-control">
            </div>
            <div class="col-md-6">
                <label class="form-label">أيام الأسبوع</label>
                <div class="d-flex flex-wrap gap-2">
                    @foreach([0=>'الأحد', 1=>'الإثنين', 2=>'الثلاثاء', 3=>'الأربعاء', 4=>'الخميس', 5=>'الجمعة', 6=>'السبت'] as $i => $name)
                        <label class="form-check form-check-inline">
                            <input type="checkbox" name="days_of_week[]" value="{{ $i }}"
                                   class="form-check-input"
                                   @checked(in_array((int) $i, array_map('intval', $oldDays ?: []), true))>
                            <span class="form-check-label small">{{ $name }}</span>
                        </label>
                    @endforeach
                </div>
                <small class="text-muted">اتركها كلها فارغة = كل أيام الأسبوع.</small>
            </div>
        </div>
    </div>
</div>

<div class="d-flex justify-content-end gap-2">
    <a href="{{ route('admin.promotions.index') }}" class="btn btn-light">إلغاء</a>
    <button class="btn btn-primary"><i class="bi bi-check-circle-fill"></i> حفظ</button>
</div>

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/alpinejs@3.14.1/dist/cdn.min.js" defer></script>
<style>[x-cloak]{display:none!important}</style>
@endpush
