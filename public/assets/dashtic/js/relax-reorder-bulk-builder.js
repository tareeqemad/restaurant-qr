(function () {
    window.reorderBulkBuilder = function (config) {
        return {
            candidates: config.candidates,
            selected: {},
            customQty: {},
            saving: false,

            init() {
                this.candidates.forEach(c => {
                    this.selected[c.id] = !!c.supplier_id;
                    this.customQty[c.id] = c.suggested_qty;
                });
            },

            effectiveQty(ing) {
                const v = this.customQty[ing.id];
                if (v === null || v === undefined || v === '' || Number(v) <= 0) {
                    return ing.suggested_qty;
                }
                return Number(v);
            },
            lineCost(ing) {
                return this.effectiveQty(ing) * ing.cost_per_unit;
            },
            formatDays(days) {
                if (days === null || days === undefined) return '∞';
                if (days <= 0) return 'الآن';
                if (days < 1) return '< يوم';
                return days.toFixed(1) + ' يوم';
            },
            urgencyLabel(u) {
                return { critical: 'حرج', high: 'مرتفع', medium: 'متوسط', low: 'منخفض' }[u] || u;
            },
            urgencyBadgeClass(u) {
                const color = { critical: 'danger', high: 'warning', medium: 'info', low: 'success' }[u] || 'secondary';
                return `bg-${color}-transparent text-${color}`;
            },

            get groups() {
                const out = {};
                this.candidates.forEach(c => {
                    const key = c.supplier_id || 0;
                    (out[key] ||= []).push(c);
                });
                return out;
            },

            get totals() {
                let count = 0;
                let cost = 0;
                const sup = new Set();
                this.candidates.forEach(c => {
                    if (!this.selected[c.id]) return;
                    if (!c.supplier_id) return;
                    count++;
                    cost += this.lineCost(c);
                    sup.add(c.supplier_id);
                });
                return { count, cost, supplier_count: sup.size };
            },
            get urgencyCounts() {
                const out = { critical: 0, high: 0, medium: 0, low: 0 };
                this.candidates.forEach(c => { out[c.urgency] = (out[c.urgency] || 0) + 1; });
                return out;
            },
            groupCost(supplierId) {
                return (this.groups[supplierId] || []).reduce((s, c) => s + this.lineCost(c), 0);
            },
            allSelectedInGroup(supplierId) {
                const items = this.groups[supplierId] || [];
                return items.length > 0 && items.every(c => !c.supplier_id || this.selected[c.id]);
            },

            toggleAll(on) {
                this.candidates.forEach(c => { if (c.supplier_id) this.selected[c.id] = on; });
            },
            toggleGroup(supplierId, on) {
                (this.groups[supplierId] || []).forEach(c => { if (c.supplier_id) this.selected[c.id] = on; });
            },

            async submit() {
                if (this.totals.count === 0) return;
                if (!confirm(`سيتم إنشاء ${this.totals.supplier_count} مسودة PO. متابعة؟`)) return;
                this.saving = true;
                try {
                    const picks = [];
                    this.candidates.forEach(c => {
                        if (!this.selected[c.id]) return;
                        if (!c.supplier_id) return;
                        picks.push({
                            ingredient_id: c.id,
                            supplier_id: c.supplier_id,
                            unit_id: c.unit_id,
                            qty: this.effectiveQty(c),
                            price: c.cost_per_unit,
                        });
                    });
                    await this.$wire.submit({ picks });
                } finally {
                    this.saving = false;
                }
            },
        };
    };
})();
