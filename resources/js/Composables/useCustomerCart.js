import { computed, reactive, ref } from 'vue';

/**
 * The customer cart — a live mirror of the SERVER session cart.
 *
 * The server stays the source of truth on purpose: the stock gate runs on
 * EVERY add/increase (the "never let a diner build an un-fulfillable cart"
 * contract), rows get their canonical ids server-side, and the cart
 * survives refreshes because it lives in the Laravel session. This layer
 * adds optimism: the UI moves instantly, then reconciles with (or reverts
 * to) what the server answered.
 *
 * A 419 anywhere means the table session died → onSessionExpired fires and
 * the page shows the rescan overlay instead of a dead button.
 */
export function useCustomerCart({ urls, initial = [], onSessionExpired, onOrderingLocked, onRejected }) {
    const rows = reactive([...initial]);
    const busy = ref(false);

    const count = computed(() => rows.reduce((s, r) => s + Number(r.quantity), 0));
    const total = computed(() => rows.reduce((s, r) => s + Number(r.subtotal), 0));

    const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';

    const call = async (url, body) => {
        const res = await fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrf(),
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
            body: JSON.stringify(body),
        });

        if (res.status === 419) {
            onSessionExpired?.();
            throw new Error('session_expired');
        }

        const data = await res.json().catch(() => null);
        if (res.status === 409 && data?.error === 'ordering_device_locked') {
            onOrderingLocked?.(data.message);
            throw new Error('ordering_device_locked');
        }

        return { status: res.status, data };
    };

    /** Add a line. Returns true when the server accepted it. */
    const add = async ({
        item,
        quantity,
        modifierIds = [],
        modifiers = [],
        excludedIngredientIds = [],
        exclusions = [],
        notes = null,
    }) => {
        if (busy.value) return false;
        busy.value = true;

        // Optimistic row with a temp id — swapped for the server's row.
        const modifiersTotal = modifiers.reduce((s, m) => s + Number(m.price_delta), 0);
        const temp = {
            id: 'tmp-' + Date.now(),
            menu_item_id: item.id,
            name: item.name,
            image: item.image,
            quantity,
            unit_price: item.price,
            modifier_ids: modifierIds,
            modifiers,
            modifiers_total: modifiersTotal,
            excluded_ingredient_ids: excludedIngredientIds,
            excluded_ingredients: exclusions,
            notes,
            subtotal: (item.price + modifiersTotal) * quantity,
            _pending: true,
        };
        rows.push(temp);

        try {
            const { data } = await call(urls.cartAdd, {
                menu_item_id: item.id,
                quantity,
                modifier_ids: modifierIds,
                excluded_ingredient_ids: excludedIngredientIds,
                notes,
            });

            const i = rows.findIndex((r) => r.id === temp.id);
            if (data?.ok && data.row) {
                rows.splice(i, 1, data.row);
                return true;
            }

            if (i !== -1) rows.splice(i, 1);
            onRejected?.(data?.message ?? 'تعذّرت الإضافة — حاول مجدداً.');
            return false;
        } catch (e) {
            const i = rows.findIndex((r) => r.id === temp.id);
            if (i !== -1) rows.splice(i, 1);
            if (! ['session_expired', 'ordering_device_locked'].includes(e.message)) {
                onRejected?.('انقطع الاتصال — ما انضاف الصنف.');
            }
            return false;
        } finally {
            busy.value = false;
        }
    };

    /** Change a row's quantity (server re-checks stock on increases). */
    const setQuantity = async (row, quantity) => {
        if (quantity < 1 || row._pending) return;
        const before = { quantity: row.quantity, subtotal: row.subtotal };
        row.quantity = quantity;
        row.subtotal = (Number(row.unit_price) + Number(row.modifiers_total)) * quantity;

        try {
            const { data } = await call(urls.cartUpdate, { row_id: row.id, quantity });
            if (! data?.ok) {
                Object.assign(row, before);
                onRejected?.(data?.message ?? 'تعذّر التعديل.');
            }
        } catch (e) {
            Object.assign(row, before);
            if (! ['session_expired', 'ordering_device_locked'].includes(e.message)) {
                onRejected?.('انقطع الاتصال — ما اتسجل التعديل.');
            }
        }
    };

    const setNotes = async (row, notes) => {
        if (row._pending) return;
        const before = row.notes;
        row.notes = notes;
        try {
            const { data } = await call(urls.cartUpdate, { row_id: row.id, notes });
            if (! data?.ok) row.notes = before;
        } catch {
            row.notes = before;
        }
    };

    const remove = async (row) => {
        const i = rows.findIndex((r) => r.id === row.id);
        if (i === -1) return;
        const removed = rows.splice(i, 1)[0];

        if (removed._pending) return; // never reached the server anyway

        try {
            const { data } = await call(urls.cartRemove, { row_id: row.id });
            if (! data?.ok) rows.splice(i, 0, removed);
        } catch (e) {
            if (! ['session_expired', 'ordering_device_locked'].includes(e.message)) rows.splice(i, 0, removed);
        }
    };

    /** Total quantity of one menu item across all its cart rows. */
    const qtyOf = (itemId) => rows
        .filter((r) => Number(r.menu_item_id) === Number(itemId))
        .reduce((s, r) => s + Number(r.quantity), 0);

    /** The server accepted this round; start a clean, independent next one. */
    const clear = () => rows.splice(0, rows.length);

    return { rows, busy, count, total, add, setQuantity, setNotes, remove, qtyOf, clear };
}
