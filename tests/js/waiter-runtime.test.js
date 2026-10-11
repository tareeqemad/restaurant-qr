import test from 'node:test';
import assert from 'node:assert/strict';
import { createPinia, setActivePinia } from 'pinia';
import { useWaiterCartStore } from '../../resources/js/Stores/waiterCart.js';
import { requiresChoices } from '../../resources/js/Support/waiterChoices.js';
import { submitOrder, previewStock } from '../../resources/js/waiterPosApi.js';
import { nextTick } from 'vue';

const item = { id: 1, name: 'Coffee', price: 8, in_stock: true, modifier_groups: [] };
function memoryStorage() {
    const values = new Map();
    return { getItem: (key) => values.get(key) ?? null, setItem: (key, value) => values.set(key, value) };
}
function startDraft(scope = '1.2.3.4', token = 'initial-token') {
    setActivePinia(createPinia());
    const cart = useWaiterCartStore();
    cart.startSession(4, [item], { storageKey: `waiter_cart_vue.${scope}`, submissionScope: scope, submitToken: token });
    return cart;
}

test('unknown waiter submission survives reload with the exact payload and token, and blocks editing', async () => {
    const previousWindow = globalThis.window;
    globalThis.window = { localStorage: memoryStorage() };
    try {
        const original = startDraft();
        original.addPlain(item);
        const sent = original.beginSubmission({ notes: 'first drinks', staff_employee_id: 12 });
        sent.cart[0].quantity = 99; // A caller cannot alter the persisted request.
        await nextTick();
        const reloaded = startDraft('1.2.3.4', 'new-page-token');
        const retry = reloaded.beginSubmission({ notes: 'different note', staff_employee_id: 99 });
        assert.equal(retry.token, 'initial-token');
        assert.equal(retry.session_id, 4);
        assert.equal(retry.notes, 'first drinks');
        assert.equal(retry.staff_employee_id, 12);
        assert.equal(retry.cart[0].quantity, 1);
        reloaded.changeQty(0, 1);
        reloaded.removeLine(0);
        reloaded.addPlain(item);
        reloaded.saveLine({ item, quantity: 9, index: 0 });
        assert.equal(reloaded.lines[0].quantity, 1);
        assert.equal(reloaded.lines.length, 1);
        assert.deepEqual(reloaded.beginSubmission(), retry);
    } finally { globalThis.window = previousWindow; }
});

test('definite refusal unlocks the saved draft with a new token, while acknowledgment removes pending state', async () => {
    const previousWindow = globalThis.window;
    globalThis.window = { localStorage: memoryStorage() };
    try {
        const cart = startDraft();
        cart.addPlain(item);
        cart.beginSubmission();
        cart.rejectSubmission();
        cart.changeQty(0, 1);
        const retry = cart.beginSubmission();
        assert.notEqual(retry.token, 'initial-token');
        assert.equal(retry.cart[0].quantity, 2);
        cart.clear();
        cart.addPlain(item); // The floor navigation may fail after acknowledgment.
        assert.notEqual(cart.beginSubmission().token, retry.token);
        cart.clear();
        await nextTick();
        const reloaded = startDraft();
        assert.equal(reloaded.pendingSubmission, null);
        assert.equal(reloaded.lines.length, 0);
    } finally { globalThis.window = previousWindow; }
});

test('pending submission cannot leak into another operator, table, branch or session scope', async () => {
    const previousWindow = globalThis.window;
    globalThis.window = { localStorage: memoryStorage() };
    try {
        const cart = startDraft();
        cart.addPlain(item);
        cart.beginSubmission();
        await nextTick();
        for (const scope of ['9.2.3.4', '1.9.3.4', '1.2.9.4', '1.2.3.9']) {
            const unrelated = startDraft(scope);
            assert.equal(unrelated.pendingSubmission, null);
            assert.equal(unrelated.lines.length, 0);
        }
    } finally { globalThis.window = previousWindow; }
});

test('storage failure does not issue an unprotected waiter request or discard its draft', () => {
    const previousWindow = globalThis.window;
    globalThis.window = { localStorage: { getItem: () => null, setItem: () => { throw Error('full'); } } };
    try {
        const cart = startDraft();
        cart.addPlain(item);
        assert.throws(() => cart.beginSubmission(), /لم يُرسل/);
        assert.equal(cart.pendingSubmission, null);
        assert.equal(cart.lines.length, 1);
    } finally { globalThis.window = previousWindow; }
});

test('ordinary variants add directly but required choices cannot be bypassed', () => {
    setActivePinia(createPinia());
    const cart = useWaiterCartStore();
    const ordinary = { id: 1, name: 'Coffee', price: 8, in_stock: true, has_mods: true,
        removable_ingredients: [{ id: 9, name: 'Sugar' }],
        modifier_groups: [{ required: false, min_select: 0, modifiers: [] }] };
    assert.equal(requiresChoices(ordinary), false);
    cart.addPlain(ordinary);
    cart.addPlain(ordinary);
    assert.equal(cart.lines.length, 1);
    assert.equal(cart.lines[0].quantity, 2);
    assert.equal(cart.total, 16);
    for (const group of [{ required: true, min_select: 0 }, { required: false, min_select: 1 }]) {
        const required = { ...ordinary, id: 2, modifier_groups: [group] };
        assert.equal(requiresChoices(required), true);
        assert.equal(cart.addPlain(required), null);
    }
});

test('waiter never clears an order on malformed or unacknowledged success', async () => {
    const previousFetch = globalThis.fetch;
    const previousDocument = globalThis.document;
    globalThis.document = { querySelector: () => null };
    try {
        for (const body of ['<html>Login</html>', '{}', 'null', '[]', '{"ok":"true"}']) {
            globalThis.fetch = async () => new Response(body, { status: 200 });
            assert.equal((await submitOrder(1, { token: 'same-token', cart: [] })).ok, false);
        }
        globalThis.fetch = async () => new Response('{"ok":true,"order_number":"R1"}', { status: 200 });
        assert.equal((await submitOrder(1, {})).ok, true);
        globalThis.fetch = async () => new Response('{"issues":[]}', { status: 200 });
        assert.equal((await previewStock(1, [])).ok, true);
    } finally {
        globalThis.fetch = previousFetch;
        globalThis.document = previousDocument;
    }
});
