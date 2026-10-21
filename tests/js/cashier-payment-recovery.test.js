import test from 'node:test';
import assert from 'node:assert/strict';
import { createPaymentRecovery } from '../../resources/js/Stores/cashierPaymentRecovery.js';
import { confirmsPayment, confirmsCustody, confirmsRejection, readCustodyRecovery } from '../../resources/js/Stores/cashierAcknowledgement.js';

function storage() { const values = new Map(); return { getItem: key => values.get(key) ?? null, setItem: (key, value) => values.set(key, value), removeItem: key => values.delete(key) }; }
const operation = { token: 'same-command', invoiceId: 17, payload: { amount: 40, method: 'cash' }, createdAt: '2026-10-21T10:00:00Z' };
test('unconfirmed payment survives sheet close/reload without generating a new token or payload', () => {
    const disk = storage();
    createPaymentRecovery(disk, 'staff-branch').save(operation);
    const restored = createPaymentRecovery(disk, 'staff-branch');
    assert.deepEqual(restored.read(), { ...operation, version: 1 });
    assert.throws(() => restored.save({ ...operation, token: 'replacement' }));
    assert.throws(() => restored.save({ ...operation, payload: { amount: 41, method: 'cash' } }));
    assert.equal(restored.read().token, operation.token);
    restored.clear('unrelated-response');
    assert.notEqual(restored.read(), null);
    restored.clear(operation.token);
    assert.equal(restored.read(), null);
});
test('payment recovery is scoped to employee and branch and refuses a failed durable save', () => {
    const disk = storage();
    createPaymentRecovery(disk, 'a').save(operation);
    assert.equal(createPaymentRecovery(disk, 'b').read(), null);
    assert.throws(() => createPaymentRecovery({ getItem: () => null, setItem: () => {}, removeItem: () => {} }, 'a').save(operation));
    assert.throws(() => createPaymentRecovery({ getItem: () => 'invalid json' }, 'a').read());
});

test('financial acknowledgements must identify the committed original operation, not merely ok:true', () => {
    const payment = { submission_state: 'committed', operation_token: operation.token, payment_id: 5, invoice_id: operation.invoiceId };
    assert.equal(confirmsPayment(payment, operation), true);
    for (const invalid of [null, {}, { ok: true }, { ...payment, payment_id: 0 }, { ...payment, invoice_id: 18 }, { ...payment, operation_token: 'different' }]) assert.equal(confirmsPayment(invalid, operation), false);
    const custody = { token: 'manual-cash-token', shiftId: 9, payload: { amount: 25, direction: 'out', reason: 'cash pickup', reference: 'receipt42' } };
    const disk = storage();
    disk.setItem('custody', JSON.stringify(custody));
    const restored = readCustodyRecovery(disk, 'custody');
    assert.deepEqual(restored, custody);
    const acknowledgment = { submission_state: 'committed', operation_token: custody.token, movement_id: 7, shift: { id: 9 } };
    assert.equal(confirmsCustody(acknowledgment, restored), true);
    assert.equal(confirmsCustody({ ...acknowledgment, replayed: true }, restored), true);
    for (const invalid of [null, {}, { ok: true }, { ...acknowledgment, movement_id: null }, { ...acknowledgment, shift: { id: 10 } }, { ...acknowledgment, submission_state: 'rejected' }]) assert.equal(confirmsCustody(invalid, restored), false);
    assert.deepEqual(readCustodyRecovery(disk, 'custody'), custody, 'Malformed or refused acknowledgments do not mutate recovery data');
    for (const invalid of [{}, { errors: {} }, { ok: false }, { ok: false, submission_state: 'rejected', operation_token: 'wrong', message: 'refused' }]) assert.equal(confirmsRejection(invalid, operation), false);
    assert.equal(confirmsRejection({ ok: false, submission_state: 'rejected', operation_token: operation.token, message: 'refused' }, operation), true);
});

test('two browser tabs cannot replace an unresolved payment while a Web Lock serializes reservation', async () => {
    const disk = storage();
    let tail = Promise.resolve();
    const locks = { request(_key, fn) { const next = tail.then(fn); tail = next.catch(() => {}); return next; } };
    const first = createPaymentRecovery(disk, 'same-cashier', locks);
    const second = createPaymentRecovery(disk, 'same-cashier', locks);
    const results = await Promise.allSettled([first.reserve(operation), second.reserve({ ...operation, token: 'other-tab' })]);
    assert.equal(results.filter(row => row.status === 'fulfilled').length, 1);
    assert.equal(first.read().token, operation.token);
    await second.clearConfirmed('other-tab');
    assert.equal(first.read().token, operation.token);
});
