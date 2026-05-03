(function () {
function makeWasteLogger(config) {
    return {
        ingredients: config.ingredients || [],
        units: config.units || [],
        reasons: config.reasons || [],
        locations: config.locations || [],

        ingredientId: 0,
        reason: '',
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
                this.ingredientId = config.preselectedIngredientId;
                await this.onIngredientChange();
            }
        },

        ingredient() {
            return this.ingredients.find(i => i.id === this.ingredientId);
        },
        selectedBatch() {
            return this.batchId ? this.batches.find(b => b.id === this.batchId) : null;
        },
        batchLabel(b) {
            let label = b.batch_number ? ('#' + b.batch_number + ' · ') : '';
            label += 'متبقي ' + b.remaining_qty.toFixed(4);
            if (b.expiry_date) label += ' · ينتهي ' + b.expiry_date;
            if (b.is_expired) label += ' ! منتهية';
            return label;
        },
        qtyBase() {
            const ing = this.ingredient();
            if (! ing || ! this.unitId) return 0;
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
        canSubmit() {
            return this.ingredientId
                && this.reason
                && this.quantity > 0
                && this.unitId
                && ! this.batchShortfall();
        },

        async onIngredientChange() {
            this.batchId = 0;
            this.batches = [];
            const ing = this.ingredient();
            if (ing && ! this.unitId) this.unitId = ing.base_unit_id;
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

        async onLocationChange() {
            if (this.ingredientId) {
                await this.onIngredientChange();
            }
        },

        onReasonChange() {
            if (this.reason !== 'expired' || this.batchId) return;
            const expired = this.batches
                .filter(b => b.is_expired)
                .sort((a, b) => (a.expiry_date || '').localeCompare(b.expiry_date || ''))[0];
            if (expired) this.batchId = expired.id;
        },

        resetForm() {
            this.quantity = 0;
            this.batchId = 0;
            this.reason = '';
            this.notes = '';
        },

        async submitNow() {
            if (! this.canSubmit()) return;
            this.saving = true;
            try {
                await this.$wire.submit({
                    ingredient_id: this.ingredientId,
                    batch_id: this.batchId || null,
                    quantity: this.quantity,
                    unit_id: this.unitId,
                    storage_location_id: this.locationId || null,
                    reason: this.reason,
                    notes: this.notes,
                });
            } finally {
                this.saving = false;
            }
        },
    };
}

window.wasteLogger = makeWasteLogger;

document.addEventListener('alpine:init', () => {
    if (window.Alpine) {
        window.Alpine.data('wasteLogger', makeWasteLogger);
    }
});

if (window.Alpine) {
    window.Alpine.data('wasteLogger', makeWasteLogger);
}
})();
