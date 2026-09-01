import { computed, ref, shallowRef, watch } from 'vue';
import { defineStore } from 'pinia';

export const useWaiterCartStore = defineStore('waiterCart', () => {
    const lines = ref([]);
    const sessionId = ref(null);
    const storageName = ref(null);
    const menuCatalog = shallowRef(new Map());

    const unitCount = computed(() => lines.value.reduce(
        (total, line) => total + line.quantity,
        0,
    ));

    const total = computed(() => lines.value.reduce(
        (amount, line) => amount + line.subtotal,
        0,
    ));

    const itemQuantities = computed(() => lines.value.reduce((quantities, line) => {
        const itemId = String(line.menu_item_id);
        quantities[itemId] = (quantities[itemId] ?? 0) + line.quantity;
        return quantities;
    }, {}));

    watch(lines, persistCart, { deep: true });

    function startSession(nextSessionId, menuItems, draft = {}) {
        sessionId.value = Number(nextSessionId);
        storageName.value = draft.storageKey || `waiter_cart_vue.${sessionId.value}`;
        menuCatalog.value = new Map(
            menuItems.map((item) => [Number(item.id), item]),
        );
        const stored = loadCart();
        const source = stored.length ? stored : (draft.initialLines ?? []);
        lines.value = source.map(normalizeLine).filter(Boolean);
    }

    function addPlain(item) {
        if (!item?.in_stock) return null;

        const existingIndex = lines.value.findIndex((line) => (
            line.menu_item_id === Number(item.id)
            && line.modifier_ids.length === 0
            && line.excluded_ingredient_ids.length === 0
            && line.line_notes === null
        ));

        if (existingIndex >= 0) {
            changeQty(existingIndex, 1);
            return lines.value[existingIndex];
        }

        const line = configuredLine({ item, quantity: 1 });
        if (!line) return null;
        lines.value.push(line);
        return line;
    }

    function saveLine(payload) {
        const index = Number.isInteger(payload.index) ? payload.index : null;
        const existing = index === null ? null : lines.value[index] ?? null;
        const line = configuredLine({
            item: payload.item,
            quantity: payload.quantity,
            modifier_ids: payload.modifier_ids,
            excluded_ingredient_ids: payload.excluded_ingredient_ids,
            line_notes: payload.line_notes,
            id: existing?.id,
            order_item_id: existing?.menu_item_id === Number(payload.item?.id)
                ? existing.order_item_id
                : null,
            unit_price: existing?.menu_item_id === Number(payload.item?.id)
                ? existing.unit_price
                : null,
        });

        if (!line) return null;
        if (existing) lines.value.splice(index, 1, line);
        else lines.value.push(line);
        return line;
    }

    function editLine(index) {
        const line = lines.value[index];
        if (!line) return null;

        return {
            ...line,
            modifier_ids: [...line.modifier_ids],
            modifier_labels: [...line.modifier_labels],
            excluded_ingredient_ids: [...line.excluded_ingredient_ids],
            excluded_ingredient_labels: [...line.excluded_ingredient_labels],
        };
    }

    function changeQty(index, delta) {
        const line = lines.value[index];
        if (!line) return null;

        const quantity = Number(line.quantity) + Number(delta);
        if (quantity < 1) {
            removeLine(index);
            return null;
        }

        line.quantity = clampQuantity(quantity);
        line.subtotal = lineTotal(line);
        return line;
    }

    function removeLine(index) {
        if (!lines.value[index]) return null;
        return lines.value.splice(index, 1)[0];
    }

    function repeatRound(roundLines) {
        const skipped = [];

        roundLines.forEach((historicalLine) => {
            const item = menuCatalog.value.get(Number(historicalLine.menu_item_id));
            if (!item?.in_stock) {
                skipped.push(historicalLine.name);
                return;
            }

            // Historical prices are display snapshots. A repeated round is a
            // new sale and must use today's menu and modifier prices.
            const line = configuredLine({
                item,
                quantity: historicalLine.quantity,
                modifier_ids: historicalLine.modifier_ids,
                excluded_ingredient_ids: historicalLine.excluded_ingredient_ids,
                line_notes: historicalLine.line_notes,
                enforceGroups: true,
            });

            if (!line) skipped.push(historicalLine.name);
            else lines.value.push(line);
        });

        return skipped;
    }

    function clear() {
        lines.value = [];
        if (typeof window !== 'undefined' && sessionId.value) {
            window.localStorage.removeItem(storageKey());
        }
    }

    function configuredLine({
        item,
        quantity = 1,
        modifier_ids = [],
        excluded_ingredient_ids = [],
        line_notes = null,
        id = null,
        order_item_id = null,
        unit_price = null,
        enforceGroups = false,
    }) {
        if (!item) return null;

        const selectedIds = [...new Set(modifier_ids.map(Number).filter(Boolean))];
        const selectedModifiers = [];

        for (const group of item.modifier_groups ?? []) {
            const groupModifiers = (group.modifiers ?? []).filter((modifier) => (
                selectedIds.includes(Number(modifier.id))
            ));

            if (enforceGroups) {
                const minimum = Number(group.min_select) || 0;
                const maximum = Number(group.max_select) || 0;
                if (groupModifiers.length < minimum || (maximum > 0 && groupModifiers.length > maximum)) {
                    return null;
                }
            }

            selectedModifiers.push(...groupModifiers);
        }

        const validIds = selectedModifiers.map((modifier) => Number(modifier.id));
        const removableIngredients = new Map((item.removable_ingredients ?? []).map(
            (ingredient) => [Number(ingredient.id), ingredient],
        ));
        const validExcludedIds = [...new Set(excluded_ingredient_ids.map(Number).filter(
            (id) => removableIngredients.has(id),
        ))];
        const modifiersTotal = selectedModifiers.reduce(
            (amount, modifier) => amount + numeric(modifier.price_delta),
            0,
        );
        const line = {
            id: id || localLineId(),
            order_item_id: order_item_id ? Number(order_item_id) : null,
            menu_item_id: Number(item.id),
            name: String(item.name ?? ''),
            unit_price: unit_price === null ? numeric(item.price) : numeric(unit_price),
            modifiers_total: modifiersTotal,
            quantity: clampQuantity(quantity),
            modifier_ids: validIds,
            modifier_labels: selectedModifiers.map((modifier) => String(modifier.name ?? '')),
            excluded_ingredient_ids: validExcludedIds,
            excluded_ingredient_labels: validExcludedIds.map(
                (id) => String(removableIngredients.get(id)?.name ?? ''),
            ).filter(Boolean),
            line_notes: cleanNote(line_notes),
            subtotal: 0,
        };
        line.subtotal = lineTotal(line);

        return line;
    }

    function normalizeLine(line) {
        if (!line || !Number(line.menu_item_id)) return null;

        const normalized = {
            id: String(line.id || localLineId()),
            order_item_id: line.order_item_id ? Number(line.order_item_id) : null,
            menu_item_id: Number(line.menu_item_id),
            name: String(line.name ?? ''),
            unit_price: numeric(line.unit_price),
            modifiers_total: numeric(line.modifiers_total),
            quantity: clampQuantity(line.quantity),
            modifier_ids: [...new Set((line.modifier_ids ?? []).map(Number).filter(Boolean))],
            modifier_labels: (line.modifier_labels ?? []).map((label) => String(label)),
            excluded_ingredient_ids: [...new Set((line.excluded_ingredient_ids ?? []).map(Number).filter(Boolean))],
            excluded_ingredient_labels: (line.excluded_ingredient_labels ?? []).map((label) => String(label)),
            line_notes: cleanNote(line.line_notes),
            subtotal: 0,
        };
        normalized.subtotal = lineTotal(normalized);

        return normalized;
    }

    function loadCart() {
        if (typeof window === 'undefined' || !sessionId.value) return [];

        try {
            const stored = JSON.parse(window.localStorage.getItem(storageKey()) || '[]');
            return Array.isArray(stored) ? stored : [];
        } catch {
            return [];
        }
    }

    function persistCart() {
        if (typeof window === 'undefined' || !sessionId.value) return;

        try {
            window.localStorage.setItem(storageKey(), JSON.stringify(lines.value));
        } catch {
            // A private browser can deny storage; the in-memory cart must stay usable.
        }
    }

    function storageKey() {
        return storageName.value || `waiter_cart_vue.${sessionId.value}`;
    }

    function lineTotal(line) {
        return (numeric(line.unit_price) + numeric(line.modifiers_total)) * clampQuantity(line.quantity);
    }

    function cleanNote(note) {
        const cleaned = String(note ?? '').trim().slice(0, 500);
        return cleaned || null;
    }

    function clampQuantity(quantity) {
        return Math.min(99, Math.max(1, Math.trunc(numeric(quantity)) || 1));
    }

    function numeric(value) {
        const number = Number(value);
        return Number.isFinite(number) ? number : 0;
    }

    function localLineId() {
        if (typeof crypto !== 'undefined' && crypto.randomUUID) return crypto.randomUUID();
        return `wp-${Date.now()}-${Math.random().toString(36).slice(2)}`;
    }

    return {
        lines,
        unitCount,
        total,
        itemQuantities,
        startSession,
        addPlain,
        saveLine,
        editLine,
        changeQty,
        removeLine,
        repeatRound,
        clear,
    };
});
