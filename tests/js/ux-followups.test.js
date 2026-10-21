import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { computed, effectScope, nextTick, reactive, ref, unref, watch } from 'vue';
import { serverHealth } from '../../resources/js/Support/serverHealth.js';
import { normalizeTaskSearch, searchTasks } from '../../resources/js/Support/taskSearch.js';

test('server health requires a recent successful response, not browser connectivity alone', () => {
    const now = 100000;
    assert.equal(serverHealth({ now }).state, 'unknown');
    assert.equal(serverHealth({ now, lastSuccessfulAt: 'invalid' }).state, 'unknown');
    assert.equal(serverHealth({ now, lastSuccessfulAt: 95000 }).state, 'current');
    assert.equal(serverHealth({ now, lastSuccessfulAt: 50000, refreshing: true }).state, 'stale');
    assert.equal(serverHealth({ now, lastSuccessfulAt: 95000, failed: true }).state, 'failed');
    assert.equal(serverHealth({ now, lastSuccessfulAt: 95000, failed: true, online: false }).state, 'offline');
    const reconnected = serverHealth({ now, online: true, lastSuccessfulAt: 50000 });
    assert.equal(reconnected.state, 'stale');
    assert.equal(reconnected.lastSuccessLabel, 'آخر استجابة منذ 50 ث');
    assert.equal(serverHealth({ now, lastSuccessfulAt: new Date(95000).toISOString() }).ageMs, 5000);
});

test('Arabic task search normalizes spelling and matches synonyms without inventing forbidden destinations', () => {
    assert.equal(normalizeTaskSearch(' إِعــادة المُراجعة '), 'اعاده المراجعه');
    const nav = [{ section: 'إدارة' }, { label: 'المنيو', children: [{ label: 'الأصناف', href: '/items' }] }];
    const tasks = [{ label: 'قواعد الترحيل', keywords: 'ربط الحسابات محاسبة', href: '/mappings' },
        { label: 'فواتير الموردين', keywords: 'فاتورة مورد شراء', href: '/supplier-invoices' }];
    assert.deepEqual(searchTasks(nav, tasks, 'ربط حسابات').map(x => x.href), ['/mappings']);
    assert.deepEqual(searchTasks(nav, tasks, 'فاتوره مورد').map(x => x.href), ['/supplier-invoices']);
    assert.deepEqual(searchTasks(nav, tasks, 'منيو أصناف').map(x => x.href), ['/items']);
    assert.deepEqual(searchTasks(nav, [], 'قواعد الترحيل'), []);
    assert.equal(searchTasks(nav, tasks, ''), nav);
    assert.equal(searchTasks(nav, [...tasks, tasks[0]], 'ربط').length, 1);
});

function setupGuard(forms, guard = true) {
    const mounted = [], unmounted = [], handlers = new Map(), listeners = new Map();
    let confirmed = false, prompts = 0;
    const source = readFileSync(new URL('../../resources/js/Composables/useFormUx.js', import.meta.url), 'utf8')
        .replace(/^import .*$/gm, '').replace('export function useFormUx', 'function useFormUx');
    const window = {
        confirm: () => { prompts++; return confirmed; },
        addEventListener: (name, fn) => listeners.set(name, fn),
        removeEventListener: (name) => listeners.delete(name),
    };
    const imports = { unref, nextTick: async () => {}, window, document: {},
        onMounted: (fn) => mounted.push(fn), onBeforeUnmount: (fn) => unmounted.push(fn),
        router: { on(name, fn) { handlers.set(name, fn); return () => handlers.delete(name); } },
        useConfirm: () => ({ ask: async () => confirmed }) };
    const useFormUx = new Function(...Object.keys(imports), `${source};return useFormUx;`)(...Object.values(imports));
    const ux = useFormUx(forms, { guard });
    mounted.forEach(fn => fn());
    return { ux, listeners, handlers, get prompts() { return prompts; },
        confirm(value) { confirmed = value; },
        visit(method = 'get') { return handlers.get('before')({ detail: { visit: { method } } }); },
        unmount() { unmounted.forEach(fn => fn()); } };
}

test('dirty forms cancel navigation, keep edits, allow saving and unregister on unmount', async () => {
    const form = reactive({ isDirty: true, processing: false, notes: 'unsaved', defaults() { this.isDirty = false; } });
    const ui = setupGuard(form);
    assert.equal(ui.visit(), false);
    assert.equal(form.notes, 'unsaved');
    assert.equal(ui.visit('post'), true);
    assert.equal(ui.prompts, 1);
    let prevented = false;
    const event = { preventDefault() { prevented = true; } };
    ui.listeners.get('beforeunload')(event);
    assert.equal(prevented, true);
    assert.equal(event.returnValue, '');
    assert.equal(await ui.ux.confirmDiscard(), false);
    ui.confirm(true);
    assert.equal(ui.visit(), true);
    ui.ux.markSaved();
    assert.equal(ui.ux.hasUnsavedChanges(), false);
    ui.unmount();
    assert.equal(ui.listeners.size, 0);
    assert.equal(ui.handlers.size, 0);
});

test('one multi-form guard protects inactive sections even while another section saves', () => {
    const forms = reactive([{ isDirty: true, processing: true }, { isDirty: true, processing: false }]);
    let editing = true;
    const ui = setupGuard(() => forms, () => editing);
    assert.equal(ui.visit(), false);
    assert.equal(ui.prompts, 1);
    forms[1].isDirty = false;
    assert.equal(ui.visit(), true);
    forms[1].isDirty = true;
    editing = false;
    assert.equal(ui.visit(), true);
    ui.unmount();
});

function trackingSheet() {
    const props = reactive({ open: true, urls: { trackData: '/data', trackPulse: '/pulse' }, orderingEnabled: true });
    const requests = [], unmounted = [], emitted = [];
    const source = readFileSync(new URL('../../resources/js/Components/CustomerMenu/OrderTrackingSheet.vue', import.meta.url), 'utf8')
        .match(/<script setup>([\s\S]*?)<\/script>/)[1].replace(/^import .*$/gm, '');
    const imports = { computed, ref, watch, AbortController, defineProps: () => props,
        defineEmits: () => (...args) => emitted.push(args), useToast: () => ({}),
        onMounted: () => {}, onBeforeUnmount: fn => unmounted.push(fn), useLiveRefresh: () => {},
        document: { hidden: false, body: { style: {} } }, window: { removeEventListener: () => {} },
        fetch: (url, options) => new Promise(resolve => requests.push({ url, options, resolve })) };
    const scope = effectScope();
    const component = scope.run(() => new Function(...Object.keys(imports), `${source};return { loadOrders, checkPulse, orders, lastSuccessfulAt, serverFailed, loading };`)(...Object.values(imports)));
    return { component, requests, props, emitted, close: async () => { props.open = false; await nextTick(); },
        unmount() { unmounted.forEach(fn => fn()); scope.stop(); } };
}
const response = (data, ok = true) => ({ ok, status: ok ? 200 : 503, json: async () => data });

test('tracking load retains the in-flight lease and ignores responses after closing', async () => {
    const ui = trackingSheet();
    const pending = ui.component.loadOrders();
    await ui.component.loadOrders();
    await ui.component.checkPulse(new AbortController().signal);
    assert.equal(ui.requests.length, 1);
    await ui.close();
    assert.equal(ui.requests[0].options.signal.aborted, true);
    assert.equal(ui.component.loading.value, true);
    ui.requests[0].resolve(response({ orders: [{ id: 1 }], version: 'one' }));
    await pending;
    assert.deepEqual(ui.component.orders.value, []);
    assert.equal(ui.component.lastSuccessfulAt.value, null);
    assert.equal(ui.emitted.length, 0);
    ui.unmount();
});

test('tracking exposes pulse failures and only restores health on valid server data', async () => {
    const ui = trackingSheet();
    let pending = ui.component.checkPulse(new AbortController().signal);
    ui.requests[0].resolve(response({}, false));
    await pending;
    assert.equal(ui.component.serverFailed.value, true);
    assert.equal(ui.component.lastSuccessfulAt.value, null);
    assert.equal(ui.requests.length, 1); // no failing-pulse fallback reload storm
    pending = ui.component.loadOrders();
    ui.requests[1].resolve(response({ orders: [], version: 'one' }));
    await pending;
    assert.equal(ui.component.serverFailed.value, false);
    assert.ok(ui.component.lastSuccessfulAt.value > 0);
    const confirmed = ui.component.lastSuccessfulAt.value;
    pending = ui.component.checkPulse(new AbortController().signal);
    ui.requests[2].resolve(response({ message: 'not a pulse' }));
    await pending;
    assert.equal(ui.component.serverFailed.value, true);
    assert.equal(ui.component.lastSuccessfulAt.value, confirmed);
    ui.unmount();
});
