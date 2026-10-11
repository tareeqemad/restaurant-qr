import assert from 'node:assert/strict';
import { afterEach, beforeEach, test } from 'node:test';
import { createPinia, setActivePinia } from 'pinia';
import { useCashierStore } from '../../resources/js/Stores/cashierStore.js';
import { sendCashierCommand } from '../../resources/js/Api/cashierApi.js';

const originalFetch = globalThis.fetch;
const json = (data, status = 200) => new Response(JSON.stringify(data), {
    status, headers: { 'Content-Type': 'application/json' },
});
const workspace = (id) => ({ kind: 'session', id, orders: [], invoice: null });
const state = () => ({
    version: 'queue-v1', generated_at: 'initial',
    filters: { mode: 'all', filter: 'checkout', search: '', session_id: 1 },
    workspace: workspace(1), queues: { sessions: [{ id: 1 }, { id: 2 }] },
    today: { net: 25 }, counts: { active_sessions: 2 }, abilities: {},
});
const billResponse = (id) => json({ ok: true, data: {
    changed: true, scope: 'workspace', workspace: workspace(id), generated_at: 'fresh',
} });
const deferred = () => {
    let resolve;
    let reject;
    const promise = new Promise((yes, no) => { resolve = yes; reject = no; });
    return { promise, resolve, reject };
};

beforeEach(() => {
    setActivePinia(createPinia());
    globalThis.window = { location: { origin: 'https://restaurant.test' } };
    globalThis.document = { querySelector: () => ({ content: 'csrf-test' }) };
});

afterEach(() => {
    globalThis.fetch = originalFetch;
    delete globalThis.window;
    delete globalThis.document;
});

function startStore() {
    const store = useCashierStore();
    store.start(state(), '/admin/cashier/api/state');
    return store;
}

test('polling cannot cancel a foreground bill selection, even when forced', async () => {
    const request = deferred();
    const calls = [];
    globalThis.fetch = (url, options) => {
        calls.push({ url, options });
        return request.promise;
    };
    const store = startStore();
    const opening = store.select('session', 2);
    const poll = await store.refresh({ force: true, source: 'poll' });
    assert.equal(poll.skipped, true);
    assert.equal(calls.length, 1);
    assert.equal(calls[0].options.signal.aborted, false);
    assert.equal(calls[0].url.searchParams.get('scope'), 'workspace');
    request.resolve(billResponse(2));
    await opening;
    assert.equal(store.workspace.id, 2);
    assert.equal(store.refreshing, false);
    assert.equal(store.lastError, '');
});

test('late response from previous selection cannot replace the current bill', async () => {
    const first = deferred();
    const second = deferred();
    let calls = 0;
    globalThis.fetch = () => (++calls === 1 ? first.promise : second.promise);
    const store = startStore();
    const openingTwo = store.select('session', 2);
    const openingThree = store.select('session', 3);
    second.resolve(billResponse(3));
    await openingThree;
    first.resolve(billResponse(2));
    await openingTwo;
    assert.equal(store.workspace.id, 3);
});

test('bill-only reads preserve queue data and its version for the next poll', async () => {
    const urls = [];
    globalThis.fetch = async (url) => {
        urls.push(url);
        return urls.length === 1 ? billResponse(2) : json({ ok: true, data: {
            changed: false, version: 'queue-v1', generated_at: 'polled',
        } });
    };
    const store = startStore();
    await store.select('session', 2);
    assert.equal(store.snapshot.version, 'queue-v1');
    assert.deepEqual(store.sessions, [{ id: 1 }, { id: 2 }]);
    assert.equal(store.today.net, 25);
    await store.refresh({ source: 'poll' });
    assert.equal(urls[1].searchParams.get('since'), 'queue-v1');
    assert.equal(store.workspace.id, 2);
});

test('failed selection is retried with a full read rather than an unchanged pulse', async () => {
    const urls = [];
    globalThis.fetch = async (url) => {
        urls.push(url);
        if (urls.length === 1) throw new Error('network unavailable');
        return json({ ok: true, data: { ...state(), changed: true, workspace: workspace(2) } });
    };
    const store = startStore();
    await assert.rejects(store.select('session', 2));
    await store.refresh({ source: 'poll' });
    assert.equal(urls[1].searchParams.get('full'), '1');
    assert.equal(urls[1].searchParams.has('since'), false);
    assert.equal(store.workspace.id, 2);
});

test('returning to the queue is local and late requests cannot reopen a bill', async () => {
    const request = deferred();
    let calls = 0;
    globalThis.fetch = () => { calls++; return request.promise; };
    const store = startStore();
    const opening = store.select('session', 2);
    await store.clearSelection();
    assert.equal(calls, 1);
    assert.equal(store.selection, null);
    assert.equal(store.refreshing, false);
    request.resolve(billResponse(2));
    await opening;
    assert.equal(store.workspace, null);
});

test('new page state cannot be replaced by an old page request', async () => {
    const request = deferred();
    globalThis.fetch = () => request.promise;
    const store = startStore();
    const opening = store.select('session', 2);
    store.start({ ...state(), workspace: workspace(3), filters: { ...state().filters, session_id: 3 } }, '/state');
    request.resolve(billResponse(2));
    await opening;
    assert.equal(store.workspace.id, 3);
});

test('invalid state response preserves the last usable snapshot', async () => {
    globalThis.fetch = async () => json({ ok: true, data: {} });
    const store = startStore();
    await assert.rejects(store.refresh());
    assert.equal(store.workspace.id, 1);
    assert.notEqual(store.lastError, '');
});

for (const [label, body] of [
    ['HTML', '<html>Login</html>'], ['null JSON', 'null'],
    ['array JSON', '[]'], ['missing acknowledgement', '{"message":"hello"}'],
]) {
    test(`HTTP 200 ${label} is not accepted as a payment acknowledgement`, async () => {
        globalThis.fetch = async () => new Response(body, { status: 200 });
        await assert.rejects(sendCashierCommand('/pay', { token: 'same-retry-token', amount: 12 }));
    });
}

test('valid payment acknowledgement and validation errors keep the existing contract', async () => {
    globalThis.fetch = async () => json({ ok: true, data: { payment_id: 9 } });
    assert.equal((await sendCashierCommand('/pay', {})).ok, true);
    globalThis.fetch = async () => json({ message: 'Invalid amount', errors: { amount: ['Too high'] } }, 422);
    const refused = await sendCashierCommand('/pay', {});
    assert.equal(refused.ok, false);
    assert.equal(refused.status, 422);
    assert.deepEqual(refused.data.errors.amount, ['Too high']);
    globalThis.fetch = async () => json({ ok: false, message: 'Refused' });
    assert.equal((await sendCashierCommand('/pay', {})).ok, false);
});
