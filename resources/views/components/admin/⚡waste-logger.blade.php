<?php

use App\Enums\WasteReason;
use App\Helpers\Qty;
use App\Helpers\UnitConverter;
use App\Models\ActivityLog;
use App\Models\Ingredient;
use App\Models\IngredientBatch;
use App\Models\IngredientStock;
use App\Models\Lookup;
use App\Models\StorageLocation;
use App\Models\Unit;
use App\Services\InventoryService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Waste Logger (Livewire seed + Alpine reactive UI — fast variant)
 *
 * Performance design:
 *   - Server seeds the lists (ingredients, units, reasons) once at mount.
 *   - Alpine owns ALL interactive state: ingredient pick, qty typing,
 *     batch pick, cost preview math, validation flags. Everything except
 *     the per-ingredient batch lookup is instant.
 *   - One round-trip per ingredient pick: `loadBatches(ingId)` returns
 *     the FIFO list. Stays cached in Alpine until the user picks a
 *     different ingredient.
 *   - Submit is the only round-trip on commit.
 */
new class extends Component
{
    public function mount(?int $preselectedIngredientId = null): void
    {
        // The Alpine init reads the preselect from the prop on the wrapper div
    }

    /**
     * All tracked ingredients with their per-location stocks attached as
     * a `stocks` map ({location_id: quantity}). Alpine filters the
     * dropdown client-side based on `locationId`, so location changes
     * are instant and need ZERO server round-trip.
     *
     * Why client-side filtering:
     *   - Avoids `$wire.method()` complications and console errors when
     *     payloads are mid-request.
     *   - 100 ingredients × 5 locations ≈ 4 KB — trivial.
     *   - Matches the rest of this component's "Alpine owns everything
     *     except final commit" architecture.
     */
    #[Computed(persist: false)]
    public function ingredients(): array
    {
        $ingredients = Ingredient::with('baseUnit:id,code,unit_type')
            ->where('track_stock', true)
            ->orderBy('name')
            ->get(['id', 'name', 'sku', 'base_unit_id', 'cost_per_unit', 'current_stock']);

        // Pull all per-location stocks in one query, group by ingredient id.
        $stocks = DB::table('ingredient_stock')
            ->whereIn('ingredient_id', $ingredients->pluck('id'))
            ->where('quantity', '>', 0)
            ->select('ingredient_id', 'storage_location_id', 'quantity')
            ->get()
            ->groupBy('ingredient_id');

        return $ingredients->map(function ($i) use ($stocks) {
            $perLoc = [];
            foreach ($stocks->get($i->id, collect()) as $row) {
                $perLoc[(int) $row->storage_location_id] = (float) $row->quantity;
            }
            return [
                'id'             => (int) $i->id,
                'name'           => $i->name,
                'sku'            => $i->sku,
                'base_unit_id'   => (int) $i->base_unit_id,
                'cost_per_unit'  => (float) $i->cost_per_unit,
                'current_stock'  => (float) $i->current_stock,
                'unit_code'      => $i->baseUnit?->code ?? '',
                // Drives the unit-dropdown filter on the client side.
                'base_unit_type' => $i->baseUnit?->unit_type ?? '',
                'stocks'         => $perLoc,   // {locationId: qty}
            ];
        })->values()->all();
    }

    /**
     * All units the user might pick from. We include `unit_type` so Alpine
     * can filter the dropdown to match the chosen ingredient (weight units
     * for a weight-based ingredient, volume for volume, count for count).
     * Without this filter the user could pick "ml" for "صدر دجاج" (g) and
     * the conversion would explode server-side.
     */
    #[Computed(persist: false)]
    public function units(): array
    {
        return Unit::orderBy('unit_type')
            ->orderBy('name')
            ->get(['id', 'name', 'code', 'unit_type'])
            ->map(fn ($u) => [
                'id'        => (int) $u->id,
                'label'     => "{$u->name} ({$u->code})",
                'unit_type' => (string) $u->unit_type,
            ])
            ->all();
    }

    /**
     * Reasons read from the global `waste_reasons` lookup group, so
     * admins can rename / reorder / add new reasons via the lookups
     * admin without touching code. The dropdown sends `id` (FK), and
     * `code` is included so client-side logic (auto-pick expired batch
     * when reason='expired') still works without an extra round-trip.
     */
    #[Computed(persist: false)]
    public function reasonOptions(): array
    {
        return Lookup::for('waste_reasons')
            ->map(fn ($r) => [
                'id'    => (int) $r->id,
                'code'  => (string) $r->code,
                'label' => (string) $r->label,
                'icon'  => (string) ($r->icon ?: ''),
                'color' => (string) ($r->color ?: ''),
            ])
            ->values()
            ->all();
    }

    #[Computed(persist: false)]
    public function storageLocations(): array
    {
        return StorageLocation::where('active', true)
            ->orderByDesc('is_default')
            ->orderBy('display_order')
            ->orderBy('name')
            ->get(['id', 'name', 'code', 'is_default'])
            ->map(fn ($location) => [
                'id' => $location->id,
                'label' => trim($location->name . ($location->code ? " ({$location->code})" : '')),
                'is_default' => (bool) $location->is_default,
            ])
            ->all();
    }

    /** One round-trip per ingredient pick. Returns FIFO batches as JSON. */
    public function loadBatches(int $ingredientId, ?int $storageLocationId = null): array
    {
        return IngredientBatch::where('ingredient_id', $ingredientId)
            ->when($storageLocationId, fn ($query) => $query->where('storage_location_id', $storageLocationId))
            ->fifo()
            ->limit(20)
            ->get(['id', 'batch_number', 'remaining_qty', 'expiry_date', 'unit_cost', 'storage_location_id'])
            ->map(fn ($b) => [
                'id'           => $b->id,
                'batch_number' => (string) $b->batch_number,
                'remaining_qty'=> (float) $b->remaining_qty,
                'expiry_date'  => $b->expiry_date?->toDateString(),
                'unit_cost'    => (float) $b->unit_cost,
                'storage_location_id' => $b->storage_location_id,
                'is_expired'   => $b->expiry_date && $b->expiry_date->isPast(),
            ])
            ->all();
    }

    /** Single round-trip persist. Alpine sends the full payload. */
    public function submit(array $payload)
    {
        $data = validator($payload, [
            'ingredient_id'    => ['required', 'exists:ingredients,id'],
            'batch_id'         => ['nullable', 'exists:ingredient_batches,id'],
            'quantity'         => ['required', 'numeric', 'min:0.0001'],
            'unit_id'          => ['required', 'exists:units,id'],
            'storage_location_id' => ['nullable', 'exists:storage_locations,id'],
            'reason_lookup_id' => ['required', 'integer', Rule::exists('lookups', 'id')->where('group', 'waste_reasons')],
            'notes'            => ['nullable', 'string', 'max:500'],
        ], [
            'reason_lookup_id.required' => 'اختر سبب الهدر.',
            'reason_lookup_id.exists'   => 'السبب المختار لم يعد متاحاً — حدّث الصفحة.',
        ])->validate();

        // Hardened (was `viewAny`): writing off stock requires the inventory
        // `manage` permission. Without this a chef/cashier could hide theft
        // by submitting waste events for items they "broke".
        $this->authorize('manage', Ingredient::class);

        // Resolve the lookup → derive the legacy enum string so we can
        // keep `inventory_movements.waste_reason` (string) populated for
        // any report still reading it directly.
        $lookup = Lookup::findOrFail($data['reason_lookup_id']);
        $reasonString = WasteReason::tryFrom($lookup->code) ? $lookup->code : 'other';

        $ingredient = Ingredient::with('baseUnit')->findOrFail($data['ingredient_id']);

        try {
            $qtyBase = UnitConverter::convert(
                (float) $data['quantity'],
                (int) $data['unit_id'],
                (int) $ingredient->base_unit_id,
            );
        } catch (\Throwable $e) {
            $this->dispatch('flash', type: 'error', message: 'تعذّر تحويل الوحدة: '.$e->getMessage());
            return;
        }

        try {
            // Closure must capture: $reasonString and $lookup are needed for
            // the movement payload + activity log inside the transaction.
            DB::transaction(function () use ($qtyBase, $ingredient, $data, $reasonString, $lookup) {
                $batch = null;

                if (! empty($data['batch_id'])) {
                    // ─── Batch path: validate against batch.remaining_qty ───
                    $batch = IngredientBatch::whereKey($data['batch_id'])
                        ->where('ingredient_id', $ingredient->id)
                        ->when(! empty($data['storage_location_id']), fn ($query) => $query->where('storage_location_id', $data['storage_location_id']))
                        ->lockForUpdate()
                        ->first();

                    if (! $batch) throw new \RuntimeException('الدفعة المختارة لم تعد متاحة.');
                    if ((float) $batch->remaining_qty + 0.0001 < $qtyBase) {
                        throw new \RuntimeException(
                            'الكمية المطلوبة (' . Qty::format($qtyBase) . ' ' . $ingredient->baseUnit?->code . ') '
                            . 'أكبر من المتبقّي في الدفعة (' . Qty::format($batch->remaining_qty) . ' ' . $ingredient->baseUnit?->code . '). '
                            . 'اختر دفعة أخرى أو قلّل الكمية.'
                        );
                    }
                    $batch->update(['remaining_qty' => (float) $batch->remaining_qty - $qtyBase]);
                } elseif (! empty($data['storage_location_id'])) {
                    // ─── Location path (no batch): validate against per-location stock ───
                    $available = (float) (IngredientStock::where('ingredient_id', $ingredient->id)
                        ->where('storage_location_id', $data['storage_location_id'])
                        ->lockForUpdate()
                        ->value('quantity') ?? 0);

                    if ($available + 0.0001 < $qtyBase) {
                        $locName = StorageLocation::whereKey($data['storage_location_id'])->value('name') ?? 'الموقع المختار';
                        throw new \RuntimeException(
                            'الكمية المطلوبة (' . Qty::format($qtyBase) . ' ' . $ingredient->baseUnit?->code . ') '
                            . "أكبر من المتوفّر في «{$locName}» (" . Qty::format($available) . ' ' . $ingredient->baseUnit?->code . '). '
                            . 'قلّل الكمية أو اختر موقعاً آخر.'
                        );
                    }
                } else {
                    // ─── Global path: validate against current_stock ───
                    $current = (float) Ingredient::whereKey($ingredient->id)
                        ->lockForUpdate()
                        ->value('current_stock');

                    if ($current + 0.0001 < $qtyBase) {
                        throw new \RuntimeException(
                            'الكمية المطلوبة (' . Qty::format($qtyBase) . ' ' . $ingredient->baseUnit?->code . ') '
                            . 'أكبر من المتوفّر إجمالياً (' . Qty::format($current) . ' ' . $ingredient->baseUnit?->code . '). '
                            . 'قلّل الكمية.'
                        );
                    }
                }

                $unitCost = $batch ? (float) $batch->unit_cost : (float) $ingredient->cost_per_unit;

                app(InventoryService::class)->recordMovement(
                    ingredient:  $ingredient,
                    type:        'waste',
                    qtyBase:     $qtyBase,
                    unitCost:    $unitCost,
                    reference:   $batch,
                    reason:      $data['notes'] ?? null,
                    userId:      auth()->id(),
                    batchId:     $batch?->id,
                    wasteReason: $reasonString,
                    storageLocationId: $data['storage_location_id'] ?? null,
                    wasteReasonLookupId: (int) $data['reason_lookup_id'],
                );

                ActivityLog::log(
                    'inventory.waste',
                    "هدر {$ingredient->name} ({$qtyBase} {$ingredient->baseUnit?->code}) — {$lookup->label}",
                    $ingredient,
                    [
                        'reason_lookup_id' => (int) $data['reason_lookup_id'],
                        'reason_code'      => $reasonString,
                        'reason_label'     => $lookup->label,
                        'qty_base'         => $qtyBase,
                        'batch_id'         => $batch?->id,
                        'cost'             => $qtyBase * $unitCost,
                    ],
                );
            });
        } catch (\Throwable $e) {
            $this->dispatch('flash', type: 'error', message: $e->getMessage());
            return;
        }

        // Flash via session so the success toast survives the redirect and
        // shows up on /admin/waste (not on the create page we're leaving).
        session()->flash('success', 'تم تسجيل الهدر وخصم المخزون.');

        // Redirect back to the waste log so the user sees the new event in
        // context. `navigate: false` does a full page reload — needed here
        // because Livewire's wire:navigate would re-mount the create page
        // on this same component instance.
        return $this->redirect(route('admin.waste.index'), navigate: false);
    }
}
?>

@php
    $wasteLoggerConfig = [
        'ingredients' => $this->ingredients,
        'units' => $this->units,
        'reasons' => $this->reasonOptions,
        'locations' => $this->storageLocations,
        'preselectedIngredientId' => (int) ($preselectedIngredientId ?? 0),
    ];
@endphp

@once
@push('styles')
<style>
    .wl-help {
        background: #fff;
        border: 1px solid #e2e8f0;
        border-radius: 14px;
        overflow: hidden;
    }
    .wl-help__head {
        display: flex; align-items: center; justify-content: space-between;
        width: 100%; border: 0;
        background: linear-gradient(90deg, #fef3c7 0%, #fff 100%);
        padding: .85rem 1.1rem;
        font-weight: 700; cursor: pointer;
        transition: background .15s;
    }
    .wl-help__head:hover { background: linear-gradient(90deg, #fde68a 0%, #fff 100%); }
    .wl-help__title { display: inline-flex; align-items: center; gap: .55rem; color: #78350f; }
    .wl-help__title i { color: #d97706; font-size: 1.1rem; }
    .wl-help__body { padding: 1rem 1.1rem; border-top: 1px solid #f1f5f9; }
    .wl-help__card {
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        padding: 1rem;
        height: 100%;
        position: relative;
    }
    .wl-help__num {
        position: absolute; top: -10px; inset-inline-end: 12px;
        width: 28px; height: 28px;
        display: inline-flex; align-items: center; justify-content: center;
        background: #d97706; color: #fff;
        border-radius: 50%;
        font-weight: 900; font-size: .85rem;
    }
    .wl-help__card h6 { font-weight: 800; color: #78350f; margin-bottom: .5rem; }
    .wl-help__card p { font-size: .82rem; color: #475569; margin: 0; line-height: 1.7; }
    [x-cloak] { display: none !important; }

    /* ─── Searchable ingredient combobox ─────────────────────────── */
    .wl-combo { position: relative; }
    .wl-combo__field {
        position: relative;
        display: flex; align-items: center;
    }
    .wl-combo__input {
        padding-inline-start: 36px;
        padding-inline-end: 64px;
        background: #fff;
        cursor: text;
    }
    .wl-combo__input[readonly] {
        background: #f8fafc;
        cursor: default;
        font-weight: 600;
        color: #0f172a;
    }
    .wl-combo__input[disabled] {
        background: #f1f5f9;
        cursor: not-allowed;
    }
    .wl-combo__lead {
        position: absolute;
        inset-inline-start: 12px;
        top: 50%;
        transform: translateY(-50%);
        color: #94a3b8;
        font-size: .9rem;
        pointer-events: none;
    }
    .wl-combo__caret {
        position: absolute;
        inset-inline-end: 12px;
        top: 50%;
        transform: translateY(-50%);
        color: #94a3b8;
        font-size: .85rem;
        cursor: pointer;
    }
    .wl-combo__clear {
        position: absolute;
        inset-inline-end: 8px;
        top: 50%;
        transform: translateY(-50%);
        width: 26px; height: 26px;
        border: 0; background: #fee2e2;
        color: #b91c1c;
        border-radius: 50%;
        display: inline-flex; align-items: center; justify-content: center;
        cursor: pointer;
        font-size: .7rem;
        transition: background .15s;
    }
    .wl-combo__clear:hover { background: #fecaca; }

    .wl-combo__list {
        position: absolute;
        top: calc(100% + 4px);
        inset-inline-start: 0;
        inset-inline-end: 0;
        max-height: 280px;
        overflow-y: auto;
        background: #fff;
        border: 1px solid #e2e8f0;
        border-radius: 10px;
        box-shadow: 0 8px 24px -8px rgba(15, 23, 42, .15);
        z-index: 1050;
    }
    .wl-combo__item {
        width: 100%;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: .75rem;
        padding: .55rem .85rem;
        border: 0;
        background: transparent;
        text-align: start;
        cursor: pointer;
        border-bottom: 1px solid #f1f5f9;
        transition: background .12s;
    }
    .wl-combo__item:hover {
        background: #f0fdf4;
    }
    .wl-combo__item:last-child { border-bottom: 0; }
    .wl-combo__item-main {
        display: flex;
        flex-direction: column;
        gap: 2px;
        flex: 1;
        min-width: 0;
    }
    .wl-combo__item-name {
        font-weight: 700;
        font-size: .88rem;
        color: #0f172a;
    }
    .wl-combo__item-sku {
        font-size: .72rem;
        color: #94a3b8;
        font-family: ui-monospace, monospace;
    }
    .wl-combo__item-stock {
        font-size: .78rem;
        font-weight: 700;
        color: #16a34a;
        white-space: nowrap;
        flex-shrink: 0;
    }
    .wl-combo__empty {
        padding: 1rem;
        text-align: center;
        color: #94a3b8;
        font-size: .85rem;
    }
    .wl-combo__empty i { display: block; font-size: 1.5rem; margin-bottom: .35rem; opacity: .6; }
</style>
@endpush
@endonce

<div x-data="wasteLogger(@js($wasteLoggerConfig))"
     x-init="init()"
     @waste-logged.window="resetForm()">

    {{-- ═══════════════════ Help banner ═══════════════════ --}}
    <div class="wl-help mb-3" x-data="{ open: true }">
        <button type="button" class="wl-help__head" @click="open = !open" :aria-expanded="open">
            <span class="wl-help__title">
                <i class="bi bi-info-circle-fill"></i>
                ما هو «تسجيل الهدر» ومتى أستخدمه؟
            </span>
            <i class="bi" :class="open ? 'bi-chevron-up' : 'bi-chevron-down'"></i>
        </button>
        <div class="wl-help__body" x-show="open" x-cloak>
            <div class="row g-3">
                <div class="col-md-4">
                    <div class="wl-help__card">
                        <div class="wl-help__num">١</div>
                        <h6>شو معنى «هدر»؟</h6>
                        <p>
                            أي كمية من المخزون <b>خرجت بدون بيع</b>: انتهت صلاحيتها، تلفت،
                            انكسرت العبوة، فُقدت في التحضير، أو سُرقت. تسجيلها يحفظ
                            دقة المخزون ويكشف لك أسباب الفقد لاحقاً في التقارير.
                        </p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="wl-help__card">
                        <div class="wl-help__num">٢</div>
                        <h6>كيف أسجل؟</h6>
                        <p>
                            ١. اختر المكوّن &nbsp;←&nbsp; ٢. حدّد <b>السبب</b> (مهم للتقارير)
                            &nbsp;←&nbsp; ٣. اكتب الكمية والوحدة &nbsp;←&nbsp; ٤. لو السبب
                            «انتهت الصلاحية» اختر الدفعة المنتهية ليتم خصمها بالـ FIFO.
                        </p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="wl-help__card">
                        <div class="wl-help__num">٣</div>
                        <h6>ماذا سيحدث؟</h6>
                        <p>
                            النظام يُنشئ حركة <b>«هدر»</b> ويخصم الكمية من المخزون فوراً.
                            القيمة المالية تظهر في شاشة تفاصيل الصندوق تحت بند «الهدر».
                            لا يمكن التراجع — استخدم الإلغاء قبل الإرسال إذا كان فيه خطأ.
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card custom-card">
        <div class="card-header">
            <h3 class="card-title mb-0">
                <i class="bi bi-pencil-square text-accent me-1"></i>
                تفاصيل الحدث
            </h3>
        </div>
        <div class="card-body">
            <div class="row g-3">
                {{-- ─── 1. Storage location (FIRST — drives ingredient list) ───── --}}
                <div class="col-md-6">
                    <label class="form-label">
                        <i class="bi bi-1-circle-fill text-accent me-1"></i>
                        موقع الهدر
                        <small class="text-muted ms-1" title="من أي موقع تخزين خرجت الكمية (مطبخ/بار/مخزن رئيسي…). تحديده يُحدّد المكوّنات المتاحة في الخطوة التالية.">
                            <i class="bi bi-info-circle"></i>
                        </small>
                    </label>
                    <select x-model.number="locationId" @change="onLocationChange()" class="form-select">
                        <option value="0">المخزون العام (كل المواقع)</option>
                        <template x-for="location in locations" :key="location.id">
                            <option :value="location.id" x-text="location.label"></option>
                        </template>
                    </select>
                    <small class="text-muted">
                        ابدأ من هنا — اختيار الموقع يفلتر قائمة المكونات لتظهر فقط ما هو متوفّر فعلاً.
                    </small>
                </div>

                {{-- ─── 2. Ingredient picker — searchable combobox, filtered by location ─── --}}
                <div class="col-md-6">
                    <label class="form-label">
                        <i class="bi bi-2-circle-fill text-accent me-1"></i>
                        المكوّن <span class="text-danger">*</span>
                        <small class="text-muted ms-1" title="ابحث بالاسم أو SKU — تظهر فقط المكونات المتوفرة في الموقع المختار">
                            <i class="bi bi-info-circle"></i>
                        </small>
                    </label>

                    <div class="wl-combo position-relative" @click.outside="ingredientOpen = false">
                        {{-- Visible input — shows selected ingredient OR the search text --}}
                        <div class="wl-combo__field">
                            <i class="bi bi-search wl-combo__lead"></i>
                            <input type="text"
                                   class="form-control wl-combo__input"
                                   :placeholder="ingredients.length === 0 ? 'لا توجد مكونات متوفرة في هذا الموقع' : 'ابحث عن مكوّن بالاسم أو SKU...'"
                                   :value="ingredientId ? ingredientDisplay() : ingredientSearch"
                                   @input="ingredientSearch = $event.target.value; ingredientOpen = true; ingredientId = 0"
                                   @focus="ingredientOpen = true"
                                   @keydown.escape="ingredientOpen = false"
                                   :readonly="ingredientId > 0"
                                   :disabled="ingredients.length === 0">
                            <button type="button" class="wl-combo__clear"
                                    x-show="ingredientId > 0"
                                    @click="clearIngredient()"
                                    title="مسح الاختيار">
                                <i class="bi bi-x-lg"></i>
                            </button>
                            <i class="bi bi-chevron-down wl-combo__caret"
                               x-show="!ingredientId"
                               @click="ingredientOpen = !ingredientOpen"></i>
                        </div>

                        {{-- Dropdown list --}}
                        <div class="wl-combo__list" x-show="ingredientOpen && ingredients.length > 0" x-cloak x-transition.opacity>
                            <template x-for="i in filteredIngredients()" :key="i.id">
                                <button type="button" class="wl-combo__item"
                                        @click="pickIngredient(i)">
                                    <span class="wl-combo__item-main">
                                        <span class="wl-combo__item-name" x-text="i.name"></span>
                                        <small class="wl-combo__item-sku" x-show="i.sku" x-text="i.sku"></small>
                                    </span>
                                    <span class="wl-combo__item-stock"
                                          x-text="'متوفّر: ' + displayQty(i).toFixed(4) + ' ' + i.unit_code"></span>
                                </button>
                            </template>
                            <div class="wl-combo__empty"
                                 x-show="filteredIngredients().length === 0">
                                <i class="bi bi-search"></i>
                                لا نتائج للبحث "<span x-text="ingredientSearch"></span>"
                            </div>
                        </div>
                    </div>

                    <small class="text-muted" x-show="ingredients.length === 0" x-cloak>
                        <i class="bi bi-info-circle"></i>
                        غيّر الموقع أعلاه أو اختر «المخزون العام» لرؤية كل المكونات.
                    </small>
                </div>

                {{-- ─── 3. Batch picker (Alpine — list cached after one-time fetch per ingredient) ─── --}}
                <div class="col-md-4">
                    <label class="form-label">
                        <i class="bi bi-3-circle-fill text-accent me-1"></i>
                        الدفعة <small class="text-muted">(اختياري)</small>
                        <small class="text-muted ms-1" title="دفعة استلام محددة من المكوّن — يضمن خصم FIFO ودقة الصلاحية. ضروري لـ«انتهاء الصلاحية».">
                            <i class="bi bi-info-circle"></i>
                        </small>
                    </label>
                    <select x-model.number="batchId" class="form-select"
                            :disabled="!ingredientId || loadingBatches">
                        <template x-if="!ingredientId">
                            <option value="0">— اختر مكوّناً أولاً —</option>
                        </template>
                        <template x-if="loadingBatches">
                            <option value="0">— تحميل... —</option>
                        </template>
                        <template x-if="ingredientId && !loadingBatches && batches.length === 0">
                            <option value="0">— لا دفعات متاحة —</option>
                        </template>
                        <template x-if="ingredientId && !loadingBatches && batches.length > 0">
                            <option value="0">— بدون دفعة محددة —</option>
                        </template>
                        <template x-for="b in batches" :key="b.id">
                            <option :value="b.id" x-text="batchLabel(b)"></option>
                        </template>
                    </select>
                    <small class="text-danger" x-show="batchShortfall()" x-cloak>
                        <i class="bi bi-exclamation-triangle-fill"></i>
                        الكمية أكبر من المتبقي في الدفعة.
                    </small>
                    <small class="text-muted" x-show="!batchShortfall()">
                        يُوصى بالاختيار لأسباب «انتهاء الصلاحية» لخصم FIFO الصحيح.
                    </small>
                </div>

                {{-- ─── 4. Unit (filtered by selected ingredient's unit_type) ── --}}
                <div class="col-md-4">
                    <label class="form-label">
                        <i class="bi bi-4-circle-fill text-accent me-1"></i>
                        الوحدة <span class="text-danger">*</span>
                        <small class="text-muted ms-1" title="وحدة قياس الكمية — تظهر فقط الوحدات المتوافقة مع نوع المكوّن (وزن / حجم / عدد). النظام يحوّلها تلقائياً إلى الوحدة الأساسية.">
                            <i class="bi bi-info-circle"></i>
                        </small>
                    </label>
                    <select x-model.number="unitId" class="form-select" :disabled="!ingredientId">
                        <option value="0"
                                x-text="!ingredientId
                                    ? '— اختر مكوّناً أولاً —'
                                    : '— اختر —'"></option>
                        <template x-for="u in compatibleUnits" :key="u.id">
                            <option :value="u.id" x-text="u.label"></option>
                        </template>
                    </select>
                    <small class="text-muted" x-show="ingredientId && ingredient()" x-cloak>
                        أساسية المكوّن: <span class="fw-bold" x-text="ingredient()?.unit_code || ''"></span>
                    </small>
                </div>

                {{-- ─── 5. Quantity ───────────────────────────────────────────── --}}
                <div class="col-md-4">
                    <label class="form-label d-flex align-items-center justify-content-between">
                        <span>
                            <i class="bi bi-5-circle-fill text-accent me-1"></i>
                            الكمية <span class="text-danger">*</span>
                            <small class="text-muted ms-1" title="كم خرج من المخزون فعلياً (بالوحدة المختارة جنبها)">
                                <i class="bi bi-info-circle"></i>
                            </small>
                        </span>
                        {{-- Live "available" cap reminder when an ingredient is picked --}}
                        <small x-show="ingredientId && availableQty() > 0" x-cloak
                               class="text-muted">
                            متاح:
                            <span class="fw-bold text-success" x-text="availableQty().toFixed(4) + ' ' + (ingredient()?.unit_code || '')"></span>
                        </small>
                    </label>
                    <input type="number" step="0.0001" min="0.0001"
                           x-model.number="quantity"
                           class="form-control text-end"
                           :max="ingredientId ? availableQty() : null"
                           :class="(stockShortfall() || batchShortfall()) ? 'is-invalid' : ''">
                    {{-- Inline shortfall warning — appears the moment qty > available --}}
                    <small class="text-danger d-block mt-1" x-show="stockShortfall()" x-cloak>
                        <i class="bi bi-exclamation-triangle-fill"></i>
                        <span x-text="shortfallMessage()"></span>
                    </small>
                </div>

                {{-- ─── 6. Reason (from Lookup) ────────────────────────────────── --}}
                <div class="col-12">
                    <label class="form-label d-flex align-items-center justify-content-between">
                        <span>
                            <i class="bi bi-6-circle-fill text-accent me-1"></i>
                            السبب <span class="text-danger">*</span>
                            <small class="text-muted ms-1" title="تصنيف سبب الهدر — يُستخدم في تحليل أسباب الفقد بالتقارير. يُقرأ من شاشة الثوابت.">
                                <i class="bi bi-info-circle"></i>
                            </small>
                        </span>
                        @can('viewAny', \App\Models\Lookup::class)
                            <a href="{{ route('admin.lookups.index', ['group' => 'waste_reasons']) }}"
                               target="_blank"
                               class="small text-muted text-decoration-none"
                               title="إدارة أسباب الهدر من شاشة الثوابت">
                                <i class="bi bi-gear-fill"></i> إدارة الأسباب
                            </a>
                        @endcan
                    </label>
                    <select x-model.number="reasonLookupId"
                            @change="onReasonChange()"
                            class="form-select">
                        <option value="0">— اختر —</option>
                        <template x-for="r in reasons" :key="r.id">
                            <option :value="r.id" x-text="r.label"></option>
                        </template>
                    </select>
                    <small class="text-muted">
                        لإضافة سبب جديد أو تعديل الأسباب → <a href="{{ route('admin.lookups.index', ['group' => 'waste_reasons']) }}" target="_blank">شاشة الثوابت</a>
                    </small>
                </div>

                {{-- Notes --}}
                <div class="col-12">
                    <label class="form-label">ملاحظات <small class="text-muted">(اختياري)</small></label>
                    <textarea x-model="notes" class="form-control" rows="2" maxlength="500"
                              placeholder="مثلاً: سُرقت من البراد، انكسرت العبوة عند التسليم..."></textarea>
                </div>

                {{-- Live cost preview --}}
                <div class="col-12" x-show="costPreview() > 0" x-cloak>
                    <div class="alert d-flex align-items-center gap-2 mb-0"
                         style="background: rgba(var(--accent-rgb),.08); border-right:4px solid var(--accent);">
                        <i class="bi bi-cash-coin fs-18 text-accent"></i>
                        <span>
                            <strong>قيمة الخسارة المقدرة:</strong>
                            <span class="text-danger fw-bold fs-15"
                                  x-text="costPreview().toFixed(2) + ' ₪'"></span>
                            <small class="text-muted ms-2"
                                   x-text="selectedBatch() ? '(سعر دفعة #' + (selectedBatch().batch_number || selectedBatch().id) + ')' : '(متوسط تكلفة المكوّن)'"></small>
                        </span>
                    </div>
                </div>
            </div>
        </div>

        <div class="card-footer d-flex justify-content-end gap-2">
            <a href="{{ route('admin.waste.index') }}" class="btn btn-light">إلغاء</a>
            <button type="button" @click="submitNow()"
                    :disabled="!canSubmit() || saving"
                    class="btn btn-danger">
                <i class="bi bi-trash3 me-1"></i>
                <span x-text="saving ? 'جارٍ التسجيل...' : 'سجّل الهدر واخصم'"></span>
            </button>
        </div>
    </div>
</div>

@script
<script>
window.wasteLogger = function (config) {
    return {
        // Master list — every tracked ingredient with stocks per location.
        // We never mutate this; `availableIngredients` derives from it.
        allIngredients: config.ingredients,
        units:          config.units,
        reasons:        config.reasons,        // [{id, code, label, icon, color}]
        locations:      config.locations || [],

        ingredientId: 0,
        reasonLookupId: 0,                     // FK → lookups.id
        quantity: 0,
        unitId: 0,
        locationId: 0,
        batchId: 0,
        notes: '',

        batches: [],
        loadingBatches: false,
        saving: false,

        async init() {
            const defaultLocation = this.locations.find(l => l.is_default) || this.locations[0];
            this.locationId = defaultLocation ? defaultLocation.id : 0;

            if (config.preselectedIngredientId) {
                // If the preselected ingredient isn't stocked at the default
                // location, drop to "general inventory" so it's still pickable.
                const preselected = this.allIngredients.find(i => i.id === config.preselectedIngredientId);
                if (preselected && this.locationId && !(preselected.stocks?.[this.locationId] > 0)) {
                    this.locationId = 0;
                }
                this.ingredientId = config.preselectedIngredientId;
                await this.onIngredientChange();
            }
        },

        // ─── Client-side ingredient filtering ──────────────────────────
        // Plain filter — NO spread/map. Spreading reactive proxies inside
        // an x-for getter trips an Alpine bug where added keys (`displayQty`)
        // come back as `undefined` on the iterator scope. Instead we keep
        // the original objects and use a `displayQty(i)` helper.
        //
        // locationId === 0 → "المخزون العام": every ingredient with global stock>0
        // locationId  > 0 → only ingredients with stocks[locationId] > 0
        get availableIngredients() {
            if (! this.locationId) {
                return this.allIngredients.filter(i => (i.current_stock || 0) > 0);
            }
            return this.allIngredients.filter(i => (i.stocks?.[this.locationId] || 0) > 0);
        },
        // The view binds to `ingredients` — keep the name stable.
        get ingredients() { return this.availableIngredients; },

        // Per-row display quantity — global stock when no location filter,
        // per-location quantity otherwise. Defensive against missing keys.
        displayQty(i) {
            if (! i) return 0;
            const v = this.locationId
                ? (i.stocks && i.stocks[this.locationId])
                : i.current_stock;
            return Number(v) || 0;
        },

        // ─── Unit dropdown filter ──────────────────────────────────────
        // Only show units whose `unit_type` matches the chosen ingredient's
        // base unit type (weight ↔ weight, volume ↔ volume, count ↔ count).
        // Without this, a user could pick "ml" for a "g"-based ingredient
        // and the server-side conversion would fail.
        // When no ingredient is picked yet, return an empty list so the
        // dropdown is locked until the user picks one.
        get compatibleUnits() {
            const ing = this.ingredient();
            if (! ing || ! ing.base_unit_type) return [];
            return this.units.filter(u => u.unit_type === ing.base_unit_type);
        },

        // ─── Searchable ingredient combobox ────────────────────────────
        ingredientSearch: '',
        ingredientOpen: false,

        // Display value for the combobox input — shows selected ingredient
        // when one is picked, otherwise the user's typed search.
        ingredientDisplay() {
            if (! this.ingredientId) return this.ingredientSearch;
            const ing = this.ingredient();
            if (! ing) return this.ingredientSearch;
            return ing.name + (ing.sku ? ' (' + ing.sku + ')' : '')
                + ' — متوفّر: ' + this.displayQty(ing).toFixed(4) + ' ' + ing.unit_code;
        },

        // Filter the available ingredients by the typed search text.
        // Matches name OR sku (case-insensitive).
        filteredIngredients() {
            const q = (this.ingredientSearch || '').trim().toLowerCase();
            if (! q) return this.ingredients;
            return this.ingredients.filter(i =>
                (i.name || '').toLowerCase().includes(q)
                || (i.sku || '').toLowerCase().includes(q)
            );
        },

        pickIngredient(i) {
            this.ingredientId = i.id;
            this.ingredientSearch = '';
            this.ingredientOpen = false;
            this.onIngredientChange();
        },

        clearIngredient() {
            this.ingredientId = 0;
            this.ingredientSearch = '';
            this.batches = [];
            this.batchId = 0;
            this.ingredientOpen = true;
        },

        // ─── Pure computed (instant) ────────────────────────────────────
        ingredient() {
            return this.ingredients.find(i => i.id === this.ingredientId);
        },
        selectedBatch() {
            return this.batchId ? this.batches.find(b => b.id === this.batchId) : null;
        },
        batchLabel(b) {
            let s = b.batch_number ? ('#' + b.batch_number + ' · ') : '';
            s += 'متبقي ' + b.remaining_qty.toFixed(4);
            if (b.expiry_date) s += ' · ينتهي ' + b.expiry_date;
            if (b.is_expired)  s += ' ⚠️ منتهية';
            return s;
        },
        // qty in BASE unit (using a simple ratio — accurate when same dimension).
        // Server validates the conversion, so an edge-case unit mismatch fails
        // there. This client-side preview is for instant feedback only.
        qtyBase() {
            const ing = this.ingredient();
            if (! ing || ! this.unitId) return 0;
            // Same unit → no conversion. Different unit → assume preview-only
            // (server is the source of truth for cross-unit math).
            if (this.unitId === ing.base_unit_id) return Number(this.quantity) || 0;
            return Number(this.quantity) || 0;
        },
        costPreview() {
            const ing = this.ingredient();
            if (! ing || this.qtyBase() <= 0) return 0;
            const unitCost = this.selectedBatch()?.unit_cost ?? ing.cost_per_unit;
            return this.qtyBase() * unitCost;
        },
        batchShortfall() {
            const b = this.selectedBatch();
            if (! b) return false;
            return this.qtyBase() > b.remaining_qty + 0.0001;
        },
        // ─── Stock availability check (per-location or global) ─────────
        // Returns the cap the user can waste right now:
        //   - selected batch     → batch.remaining_qty
        //   - selected location  → ingredient.stocks[locationId]
        //   - else (general)     → ingredient.current_stock
        availableQty() {
            const b = this.selectedBatch();
            if (b) return Number(b.remaining_qty) || 0;
            const ing = this.ingredient();
            if (! ing) return 0;
            return this.displayQty(ing);
        },
        // True when the requested qty exceeds what's actually on hand.
        stockShortfall() {
            const ing = this.ingredient();
            if (! ing) return false;
            const requested = this.qtyBase();
            if (requested <= 0) return false;
            return requested > this.availableQty() + 0.0001;
        },
        // Human-readable message for the inline warning under the qty input.
        shortfallMessage() {
            const ing = this.ingredient();
            if (! ing || ! this.stockShortfall()) return '';
            const cap = this.availableQty();
            const unit = ing.unit_code || '';
            if (this.selectedBatch()) {
                return `أكبر من المتبقّي في الدفعة (${cap.toFixed(4)} ${unit})`;
            }
            return this.locationId
                ? `أكبر من المتوفّر في الموقع المختار (${cap.toFixed(4)} ${unit})`
                : `أكبر من المتوفّر إجمالياً (${cap.toFixed(4)} ${unit})`;
        },
        canSubmit() {
            return this.ingredientId
                && this.reasonLookupId
                && this.quantity > 0
                && this.unitId
                && ! this.batchShortfall()
                && ! this.stockShortfall();
        },

        // The selected lookup row (for code-based logic like auto-pick expired batch)
        selectedReason() {
            return this.reasons.find(r => r.id === this.reasonLookupId);
        },

        // ─── Pickers (each = one tiny round-trip) ───────────────────────
        async onIngredientChange() {
            this.batchId = 0;
            this.batches = [];

            const ing = this.ingredient();

            // Snap the unit to the ingredient's base unit whenever the
            // ingredient changes. If the previously-picked unit doesn't
            // belong to the new ingredient's unit_type (weight ↔ volume),
            // it gets replaced. Otherwise it's already valid and stays.
            if (ing) {
                const stillCompatible = this.unitId
                    && this.compatibleUnits.some(u => u.id === this.unitId);
                if (! stillCompatible) {
                    this.unitId = ing.base_unit_id;
                }
            } else {
                this.unitId = 0;
            }

            if (! this.ingredientId) return;

            this.loadingBatches = true;
            try {
                const res = await this.$wire.loadBatches(this.ingredientId, this.locationId || null);
                this.batches = Array.isArray(res) ? res : [];
            } catch (e) {
                this.batches = [];
            } finally {
                this.loadingBatches = false;
            }
        },

        // Location changed → reset ingredient + batch (the previously picked
        // ingredient may not exist at the new location). The dropdown
        // re-renders instantly thanks to `availableIngredients` getter.
        onLocationChange() {
            this.ingredientId = 0;
            this.batchId = 0;
            this.batches = [];
        },

        // When reason changes to "expired" (matched on code, not id), auto-pick
        // the soonest-expiring batch. Code-based check survives renames in the
        // lookups admin — the `code` is the immutable identifier.
        onReasonChange() {
            const sel = this.selectedReason();
            if (! sel || sel.code !== 'expired' || this.batchId) return;
            const expired = this.batches
                .filter(b => b.is_expired)
                .sort((a, b) => (a.expiry_date || '').localeCompare(b.expiry_date || ''))[0];
            if (expired) this.batchId = expired.id;
        },

        resetForm() {
            this.quantity = 0;
            this.batchId = 0;
            this.reasonLookupId = 0;
            this.notes = '';
            // Keep ingredient + unit selected so warehouse staff can quickly log multiple losses
        },

        // ─── Submit (single round-trip) ────────────────────────────────
        async submitNow() {
            if (! this.canSubmit()) return;
            this.saving = true;
            try {
                await this.$wire.submit({
                    ingredient_id:     this.ingredientId,
                    batch_id:          this.batchId || null,
                    quantity:          this.quantity,
                    unit_id:           this.unitId,
                    storage_location_id: this.locationId || null,
                    reason_lookup_id:  this.reasonLookupId,
                    notes:             this.notes,
                });
            } finally {
                this.saving = false;
            }
        },
    };
};
</script>
@endscript
