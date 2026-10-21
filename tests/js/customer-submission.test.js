import test from 'node:test';
import assert from 'node:assert/strict';
import { createCustomerSubmission, readCustomerAcknowledgement } from '../../resources/js/Support/customerSubmission.js';
import { useCustomerCart } from '../../resources/js/Composables/useCustomerCart.js';

function memoryStorage() {
    const values = new Map();
    return {
        getItem: (key) => values.get(key) ?? null,
        setItem: (key, value) => values.set(key, value),
        removeItem: (key) => values.delete(key),
    };
}
const line = () => ({ id: 'row-1', menu_item_id: 2, quantity: 1, unit_price: 8, modifiers_total: 0, subtotal: 8 });
const acknowledgement = () => ({
    ok: true, submission_state: 'committed', receipt: { token: 'round-1', orderId: 42 },
    orderNumber: 'R42', submitToken: 'round-2', orders: [{ id: 42 }], cart: [],
});
const response = (data, status = 200) => new Response(JSON.stringify(data), { status });

test('customer retry survives reload with the exact request and is isolated by table/device scope', () => {
    const storage = memoryStorage();
    const draft = createCustomerSubmission('branch.table.device', storage);
    const rows = [line()];
    const original = draft.begin({ _idem: 'round-1', notes: 'no salt', cart_snapshot: rows }, rows);
    original.cart_snapshot[0].quantity = 99;
    rows[0].quantity = 77;
    const restored = createCustomerSubmission('branch.table.device', storage);
    const retry = restored.begin({ _idem: 'new-token', notes: 'changed' }, [line()]);
    assert.equal(retry._idem, 'round-1');
    assert.equal(retry.notes, 'no salt');
    assert.equal(retry.cart_snapshot[0].quantity, 1);
    assert.equal(restored.pending.value.rows[0].quantity, 1);
    assert.equal(createCustomerSubmission('branch.other-table.device', storage).pending.value, null);
    assert.equal(createCustomerSubmission('branch.table.other-device', storage).pending.value, null);
    restored.clear();
    assert.equal(createCustomerSubmission('branch.table.device', storage).pending.value, null);
});

test('customer must persist the exact operation before any request can be sent', () => {
    const draft = createCustomerSubmission('scope', {
        getItem: () => null, setItem: () => { throw new Error('quota'); }, removeItem() {},
    });
    assert.throws(() => draft.begin({ _idem: 'round-1', cart_snapshot: [line()] }, [line()]));
    assert.equal(draft.pending.value, null);
    const silentlyDiscarded = createCustomerSubmission('scope', {
        getItem: () => null, setItem() {}, removeItem() {},
    });
    assert.throws(() => silentlyDiscarded.begin({ _idem: 'round-1' }, [line()]));
    assert.equal(silentlyDiscarded.pending.value, null);
});

test('malformed persisted customer recovery cannot be overwritten by a new operation', () => {
    let writes = 0;
    const draft = createCustomerSubmission('scope', {
        getItem: () => JSON.stringify({ scope: 'scope', rows: [], payload: { _idem: 'old' } }),
        setItem: () => { writes += 1; }, removeItem() {},
    });
    assert.throws(() => draft.begin({ _idem: 'new' }, [line()]));
    assert.equal(writes, 0);
});

test('stale customer tab adopts the stored operation and a late acknowledgement cannot erase a newer one', () => {
    const storage = memoryStorage();
    const first = createCustomerSubmission('scope', storage);
    const staleTab = createCustomerSubmission('scope', storage);
    first.begin({ _idem: 'round-1', notes: 'first' }, [line()]);
    assert.deepEqual(staleTab.begin({ _idem: 'round-1', notes: 'different' }, [line()]), { _idem: 'round-1', notes: 'first' });
    first.clear();
    const newer = createCustomerSubmission('scope', storage);
    newer.begin({ _idem: 'round-2', notes: 'second' }, [line()]);
    staleTab.clear();
    assert.equal(createCustomerSubmission('scope', storage).pending.value.payload._idem, 'round-2');
});

test('HTML, empty JSON, incomplete receipts and wrong tokens can never acknowledge customer success', async () => {
    const malformed = [null, [], {}, { ok: true }, { ...acknowledgement(), receipt: null },
        { ...acknowledgement(), receipt: { token: 'other-round', orderId: 42 } },
        { ...acknowledgement(), receipt: { token: 'round-1', orderId: null } },
        { ...acknowledgement(), orders: null }, { ...acknowledgement(), cart: null }];
    for (const data of malformed) {
        assert.equal((await readCustomerAcknowledgement(response(data), 'round-1')).committed, false);
    }
    const html = await readCustomerAcknowledgement(new Response('<html>Login</html>'), 'round-1');
    assert.equal(html.committed, false);
    assert.equal(html.rejected, false);
    assert.equal((await readCustomerAcknowledgement(response(acknowledgement()), 'round-1')).committed, true);
});

test('only definite validation or business rejection unlocks a customer request', async () => {
    for (const [status, data] of [[500, {}], [409, { ok: false, submission_state: 'unknown' }], [419, { message: 'expired' }]]) {
        const result = await readCustomerAcknowledgement(response(data, status), 'round-1');
        assert.equal(result.committed, false);
        assert.equal(result.rejected, false);
    }
    assert.equal((await readCustomerAcknowledgement(response({ ok: false, submission_state: 'rejected' }, 409), 'round-1')).rejected, true);
    assert.equal((await readCustomerAcknowledgement(response({ errors: { notes: ['Too long'] } }, 422), 'round-1')).rejected, true);
});

test('all cart writes hold submission busy until every response arrives and pending requests prevent editing', async () => {
    const previousFetch = globalThis.fetch;
    const previousDocument = globalThis.document;
    const requests = [];
    let canMutate = true;
    globalThis.document = { querySelector: () => null };
    globalThis.fetch = () => new Promise((resolve) => requests.push(resolve));
    try {
        const cart = useCustomerCart({ urls: { cartUpdate: '/update', cartRemove: '/remove' }, initial: [line()], canMutate: () => canMutate });
        const quantity = cart.setQuantity(cart.rows[0], 2);
        const notes = cart.setNotes(cart.rows[0], 'note');
        assert.equal(cart.busy.value, true);
        requests[0](response({ ok: true }));
        await quantity;
        assert.equal(cart.busy.value, true);
        requests[1](response({ ok: true }));
        await notes;
        assert.equal(cart.busy.value, false);
        canMutate = false;
        await cart.setQuantity(cart.rows[0], 9);
        await cart.remove(cart.rows[0]);
        assert.equal(cart.rows.length, 1);
        assert.equal(cart.rows[0].quantity, 2);
        assert.equal(requests.length, 2);
    } finally {
        globalThis.fetch = previousFetch;
        globalThis.document = previousDocument;
    }
});
