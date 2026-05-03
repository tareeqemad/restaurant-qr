<?php

use App\Models\Ingredient;
use App\Models\IngredientStock;
use App\Models\StorageLocation;
use App\Services\LocationInventoryService;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Storage Location Transfer (Livewire seed + Alpine reactive UI — fast variant)
 *
 * Performance design:
 *   - Server seeds the ingredient + location lists once at mount.
 *   - Alpine owns ALL interactive state: ingredient/from/to picks, qty
 *     typing, validation flags, "after transfer" preview math.
 *   - The only round-trip is `lookupStocks(ingredientId, fromId, toId)` —
 *     called once per picker change (not per keystroke).
 *   - `submit(payload)` is the single round-trip on commit.
 */
new class extends Component
{
    /** Lightweight seed for Alpine init — no per-render queries. */
    #[Computed(persist: false)]
    public function locations(): array
    {
        return StorageLocation::where('active', true)
            ->orderBy('display_order')->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn ($l) => ['id' => $l->id, 'name' => $l->name])
            ->all();
    }

    #[Computed(persist: false)]
    public function ingredients(): array
    {
        return Ingredient::with('baseUnit:id,code')
            ->orderBy('name')
            ->get(['id', 'name', 'base_unit_id'])
            ->map(fn ($i) => [
                'id'        => $i->id,
                'name'      => $i->name,
                'unit_code' => $i->baseUnit?->code ?? '',
            ])
            ->all();
    }

    /**
     * One round-trip lookup: for the chosen ingredient, return its qty at
     * the source + destination locations. Called by Alpine when the user
     * picks a different ingredient or location — NOT per keystroke.
     */
    public function lookupStocks(?int $ingredientId, ?int $fromId, ?int $toId): array
    {
        if (! $ingredientId) return ['source' => 0, 'dest' => 0];

        $source = $fromId ? (float) IngredientStock::where([
            'ingredient_id' => $ingredientId,
            'storage_location_id' => $fromId,
        ])->value('quantity') : 0;

        $dest = $toId ? (float) IngredientStock::where([
            'ingredient_id' => $ingredientId,
            'storage_location_id' => $toId,
        ])->value('quantity') : 0;

        return ['source' => $source, 'dest' => $dest];
    }

    /** Single round-trip persist. Alpine sends the full payload. */
    public function submit(array $payload)
    {
        $ingId   = (int) ($payload['ingredient_id'] ?? 0);
        $fromId  = (int) ($payload['from_id'] ?? 0);
        $toId    = (int) ($payload['to_id'] ?? 0);
        $qty     = (float) ($payload['quantity'] ?? 0);
        $reason  = trim((string) ($payload['reason'] ?? '')) ?: null;

        if (! $ingId || ! $fromId || ! $toId || $qty <= 0 || $fromId === $toId) {
            $this->dispatch('flash', type: 'error', message: 'تأكد من اختيار المكوّن والمصدر والوجهة وكمية صحيحة.');
            return;
        }

        $ing  = Ingredient::with('baseUnit')->findOrFail($ingId);
        $from = StorageLocation::findOrFail($fromId);
        $to   = StorageLocation::findOrFail($toId);

        try {
            app(LocationInventoryService::class)->transfer(
                $ing, $from, $to, $qty, $reason, auth()->id()
            );
            session()->flash('success',
                "تم نقل {$qty} {$ing->baseUnit?->code} من «{$from->name}» إلى «{$to->name}».");
            return $this->redirect(route('admin.storage-locations.index'), navigate: false);
        } catch (\Throwable $e) {
            $this->dispatch('flash', type: 'error', message: $e->getMessage());
        }
    }
}
?>

<div x-data='locationTransfer({
        ingredients: @json($this->ingredients),
        locations:   @json($this->locations),
     })' x-init="init()">

    <div class="row justify-content-center">
        <div class="col-lg-9">
            <div class="alert d-flex align-items-start gap-2"
                 style="background: rgba(var(--accent-rgb),.08); border-right:4px solid var(--accent); color:var(--primary);">
                <i class="bi bi-info-circle-fill fs-18"></i>
                <div>
                    النقل ينشئ حركتين: <strong>out</strong> من المصدر و <strong>in</strong> للوجهة في نفس اللحظة.
                    إجمالي مخزون المكوّن (current_stock) لن يتغيّر — فقط توزيعه بين المواقع.
                </div>
            </div>

            <div class="card custom-card">
                <div class="card-header">
                    <h3 class="card-title mb-0">
                        <i class="bi bi-arrow-left-right text-accent me-1"></i>
                        تفاصيل النقل
                    </h3>
                </div>
                <div class="card-body">
                    {{-- Ingredient picker --}}
                    <div class="mb-3">
                        <label class="form-label">المكوّن <span class="text-danger">*</span></label>
                        <select x-model.number="ingredientId"
                                @change="onPickerChange()"
                                class="form-select form-select-lg">
                            <option value="0">— اختر مكون —</option>
                            <template x-for="i in ingredients" :key="i.id">
                                <option :value="i.id" x-text="i.name + ' (' + i.unit_code + ')'"></option>
                            </template>
                        </select>
                    </div>

                    {{-- From / To pickers --}}
                    <div class="row g-3">
                        <div class="col-md-5">
                            <label class="form-label">من الموقع <span class="text-danger">*</span></label>
                            <select x-model.number="fromId"
                                    @change="onPickerChange()"
                                    class="form-select form-select-lg">
                                <option value="0">— المصدر —</option>
                                <template x-for="loc in locations" :key="loc.id">
                                    <option :value="loc.id" x-text="loc.name"></option>
                                </template>
                            </select>
                            <div class="mt-2 p-2 bg-light rounded fs-13" x-show="ingredientId && fromId" x-cloak>
                                <i class="bi bi-box2-fill text-muted me-1"></i>
                                المتاح حالياً: <strong x-text="sourceStock.toFixed(4) + ' ' + currentUnit()"></strong>
                            </div>
                        </div>

                        <div class="col-md-2 text-center">
                            <div style="padding-top: 2.4rem;">
                                <i class="bi bi-arrow-left-circle-fill" style="font-size: 2rem; color: var(--accent);"></i>
                            </div>
                        </div>

                        <div class="col-md-5">
                            <label class="form-label">إلى الموقع <span class="text-danger">*</span></label>
                            <select x-model.number="toId"
                                    @change="onPickerChange()"
                                    class="form-select form-select-lg">
                                <option value="0">— الوجهة —</option>
                                <template x-for="loc in locations" :key="loc.id">
                                    <option :value="loc.id"
                                            :disabled="loc.id === fromId"
                                            x-text="loc.id === fromId
                                                ? loc.name + ' (المصدر — اختر موقعاً مختلفاً)'
                                                : loc.name"></option>
                                </template>
                            </select>
                            <div class="mt-2 p-2 bg-light rounded fs-13" x-show="ingredientId && toId" x-cloak>
                                <i class="bi bi-box2-fill text-muted me-1"></i>
                                الموجود قبل النقل: <strong x-text="destStock.toFixed(4) + ' ' + currentUnit()"></strong>
                            </div>
                        </div>
                    </div>

                    {{-- Quantity input (Alpine — instant) --}}
                    <div class="mt-3">
                        <label class="form-label">الكمية <span class="text-danger">*</span></label>
                        <div class="input-group input-group-lg" :class="isOver() ? 'is-invalid' : ''">
                            <input type="number" step="0.0001" min="0"
                                   x-model.number="qty"
                                   class="form-control text-end"
                                   :class="isOver() ? 'is-invalid' : ''"
                                   placeholder="0">
                            <span class="input-group-text" x-text="currentUnit()"></span>
                        </div>
                        <small class="text-danger" x-show="isOver()" x-cloak>
                            <i class="bi bi-exclamation-triangle-fill"></i>
                            الكمية أكبر من المتاح في المصدر (<span x-text="sourceStock.toFixed(4)"></span>).
                        </small>
                    </div>

                    {{-- Live preview after transfer --}}
                    <div class="mt-3 p-3 rounded"
                         x-show="ingredientId && fromId && toId && fromId !== toId && qty > 0 && !isOver()"
                         x-cloak
                         style="background: rgba(var(--primary-rgb), .04); border: 1px solid rgba(var(--primary-rgb), .15);">
                        <strong class="d-block mb-2 text-primary">
                            <i class="bi bi-eye-fill"></i> معاينة بعد النقل
                        </strong>
                        <div class="row g-2 fs-13">
                            <div class="col-md-6">
                                <div class="d-flex justify-content-between p-2 bg-white rounded">
                                    <span>المصدر:</span>
                                    <span>
                                        <span class="text-muted" x-text="sourceStock.toFixed(4)"></span>
                                        <i class="bi bi-arrow-left mx-1"></i>
                                        <strong class="text-warning" x-text="sourceStockAfter().toFixed(4)"></strong>
                                        <span x-text="currentUnit()"></span>
                                    </span>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="d-flex justify-content-between p-2 bg-white rounded">
                                    <span>الوجهة:</span>
                                    <span>
                                        <span class="text-muted" x-text="destStock.toFixed(4)"></span>
                                        <i class="bi bi-arrow-left mx-1"></i>
                                        <strong class="text-success" x-text="destStockAfter().toFixed(4)"></strong>
                                        <span x-text="currentUnit()"></span>
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>

                    {{-- Reason --}}
                    <div class="mt-3">
                        <label class="form-label">سبب النقل <small class="text-muted">(اختياري)</small></label>
                        <textarea x-model="reason" class="form-control" rows="2" maxlength="500"
                                  placeholder="نقل للاستخدام في البار، توزيع مخزون..."></textarea>
                    </div>
                </div>

                <div class="card-footer d-flex justify-content-end gap-2">
                    <a href="{{ route('admin.storage-locations.index') }}" class="btn btn-light">
                        <i class="bi bi-arrow-right"></i> إلغاء
                    </a>
                    <button type="button" @click="submitNow()"
                            :disabled="!canSubmit() || saving"
                            class="btn btn-primary btn-lg">
                        <i class="bi bi-arrow-left-right me-1"></i>
                        <span x-text="saving ? 'جارٍ النقل...' : 'تنفيذ النقل'"></span>
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
window.locationTransfer = function (config) {
    return {
        ingredients: config.ingredients,
        locations:   config.locations,

        ingredientId: 0,
        fromId: 0,
        toId: 0,
        qty: 0,
        reason: '',

        sourceStock: 0,
        destStock: 0,
        saving: false,

        init() { /* nothing */ },

        currentUnit() {
            const ing = this.ingredients.find(i => i.id === this.ingredientId);
            return ing ? ing.unit_code : '';
        },

        // ─── Picker change → ONE round-trip to fetch fresh stocks ──────
        async onPickerChange() {
            // Reset destination if user picks the same location as source
            if (this.toId === this.fromId && this.fromId) this.toId = 0;

            if (! this.ingredientId) {
                this.sourceStock = 0;
                this.destStock = 0;
                return;
            }
            try {
                const res = await this.$wire.lookupStocks(this.ingredientId, this.fromId || null, this.toId || null);
                this.sourceStock = Number(res?.source) || 0;
                this.destStock   = Number(res?.dest)   || 0;
            } catch (e) {
                this.sourceStock = 0;
                this.destStock = 0;
            }
        },

        // ─── Pure-display math (instant, no network) ───────────────────
        isOver() { return this.qty > this.sourceStock + 0.0001 && this.ingredientId && this.fromId; },
        sourceStockAfter() { return this.sourceStock - (this.qty || 0); },
        destStockAfter()   { return this.destStock + (this.qty || 0); },
        canSubmit() {
            return this.ingredientId && this.fromId && this.toId
                && this.fromId !== this.toId
                && this.qty > 0 && ! this.isOver();
        },

        // ─── Submit (single round-trip) ────────────────────────────────
        async submitNow() {
            if (! this.canSubmit()) return;
            if (! confirm('تأكيد تنفيذ النقل؟')) return;
            this.saving = true;
            try {
                await this.$wire.submit({
                    ingredient_id: this.ingredientId,
                    from_id: this.fromId,
                    to_id: this.toId,
                    quantity: this.qty,
                    reason: this.reason,
                });
            } finally {
                this.saving = false;
            }
        },
    };
};
</script>
