import { computed, ref, shallowRef, watch } from 'vue';
import { defineStore } from 'pinia';
import { requiresChoices } from '../Support/waiterChoices.js';

export const useWaiterCartStore = defineStore('waiterCart', () => {
    const lines = ref([]);
    const sessionId = ref(null);
    const storageName = ref(null);
    const pendingSubmission = ref(null);
    const submissionScope = ref(null);
    const submissionToken = ref(null);
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
        submissionScope.value = draft.submissionScope ?? null;
        submissionToken.value = draft.submitToken ?? null;
        menuCatalog.value = new Map(
            menuItems.map((item) => [Number(item.id), item]),
        );
        const stored = loadCart();
        pendingSubmission.value = stored.pending?.scope === submissionScope.value
            ? stored.pending : null;
        const source = stored.lines.length ? stored.lines : (draft.initialLines ?? []);
        lines.value = source.map(normalizeLine).filter(Boolean);
    }

    function addPlain(item) {
        if (pendingSubmission.value) return null;
        if (!item?.in_stock || requiresChoices(item)) return null;

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
        if (pendingSubmission.value) return null;
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
        if (pendingSubmission.value) return null;
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
        if (pendingSubmission.value) return null;
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
        if (pendingSubmission.value) return null;
        if (!lines.value[index]) return null;
        return lines.value.splice(index, 1)[0];
    }

    function repeatRound(roundLines) {
        if (pendingSubmission.value) return roundLines.map((line) => line.name);
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
        pendingSubmission.value = null;
        submissionToken.value = localLineId();
        persistCart();
    }

    // Persist before the write. An unknown outcome always retries this exact
    // request, including after navigation/reload or changes to menu prices.
    function beginSubmission({ notes = '', staff_employee_id = null } = {}) {
        if (pendingSubmission.value) return clone(pendingSubmission.value.payload);
        if (!submissionScope.value || !submissionToken.value || !lines.value.length) {
            throw new Error('تعذّر تجهيز الإرسال. حدّث الصفحة؛ السلة محفوظة.');
        }
        pendingSubmission.value = {
            scope: submissionScope.value,
            payload: clone({
                token: submissionToken.value,
                session_id: sessionId.value,
                notes,
                staff_employee_id,
                cart: lines.value,
            }),
        };
        if (!persistCart()) {
            pendingSubmission.value = null;
            throw new Error('تعذّر حفظ الطلب على الجهاز. لم يُرسل؛ أعد المحاولة.');
        }
        return clone(pendingSubmission.value.payload);
    }

    function rejectSubmission() {
        // Only an explicit server rejection guarantees there was no commit.
        pendingSubmission.value = null;
        submissionToken.value = localLineId();
        persistCart();
    }

    function clone(value) {
        return JSON.parse(JSON.stringify(value));
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
        const empty = { lines: [], pending: null };
        if (typeof window === 'undefined' || !sessionId.value) return empty;

        try {
            const stored = JSON.parse(window.localStorage.getItem(storageKey()) || '[]');
            if (Array.isArray(stored)) return { lines: stored, pending: null };
            if (!stored || !Array.isArray(stored.lines)) return empty;
            return { lines: stored.lines, pending: stored.pending ?? null };
        } catch {
            return empty;
        }
    }

    function persistCart() {
        if (typeof window === 'undefined' || !sessionId.value) return false;

        try {
            window.localStorage.setItem(storageKey(), JSON.stringify({
                lines: lines.value,
                pending: pendingSubmission.value,
            }));
            return true;
        } catch {
            // A private browser can deny storage; the in-memory cart must stay usable.
            return false;
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
        pendingSubmission,
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
        beginSubmission,
        rejectSubmission,
    };
});
