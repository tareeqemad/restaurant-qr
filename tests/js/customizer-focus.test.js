import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { computed, effectScope, nextTick, reactive, ref, watch } from 'vue';

// Run each component's actual setup against a small focus-capable DOM double.
// Browser QA separately verifies native tab order and rendering.
function setupCustomizer(path, customer) {
    const document = { activeElement: null, body: { style: { overflow: 'clip' } } };
    class Element {
        constructor(name, options = {}) {
            Object.assign(this, { name, tabIndex: 0, disabled: false, visible: true,
                visibility: 'visible', isConnected: true, children: [], focusCount: 0 }, options);
        }
        focus() { this.focusCount += 1; document.activeElement = this; }
        getClientRects() { return this.visible ? [{}] : []; }
        contains(element) { return element === this || this.children.includes(element); }
        querySelectorAll(selector) { assert.ok(selector.includes('summary')); return this.children; }
    }
    const trigger = new Element('trigger');
    trigger.focus();
    const item = { id: 1, price: 10, modifier_groups: [], removable_ingredients: [] };
    const props = reactive({ item: customer ? null : item, line: null, currency: {}, orderingEnabled: true,
        open: false, sessionInfo: {}, cart: { rows: [], total: { value: 0 } }, money: {} });
    const mounted = [], unmounted = [], emitted = [];
    const imports = { computed, nextTick, reactive, ref, watch, document, HTMLElement: Element,
        getComputedStyle: (element) => ({ visibility: element.visibility }),
        onMounted: (callback) => mounted.push(callback), onBeforeUnmount: (callback) => unmounted.push(callback),
        defineProps: () => props, defineEmits: () => (...args) => emitted.push(args), formatMoney: String };
    const source = readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
    const script = source.match(/<script setup>([\s\S]*?)<\/script>/)[1].replace(/^import .*$/gm, '');
    const scope = effectScope();
    const component = scope.run(() => new Function(...Object.keys(imports),
        `${script}\nreturn { dialogPanel, closeButton, handleDialogKeydown };`)(...Object.values(imports)));
    const close = new Element('close');
    const summary = new Element('ingredient summary');
    const last = new Element('save');
    const panel = new Element('panel', { tabIndex: -1, children: [close, summary, last,
        new Element('closed disclosure option', { visible: false }),
        new Element('disabled option', { disabled: true }),
        new Element('CSS hidden option', { visibility: 'hidden' })] });
    component.dialogPanel.value = panel;
    component.closeButton.value = close;
    return { component, document, trigger, close, summary, last, panel, emitted, scope,
        open() {
            if (customer === 'cart') props.open = true;
            else if (customer) props.item = item;
            else mounted.forEach((callback) => callback());
        },
        closeDialog() {
            if (customer === 'cart') props.open = false;
            else if (customer) props.item = null;
            else unmounted.forEach((callback) => callback());
        },
        unmount() { unmounted.forEach((callback) => callback()); scope.stop(); } };
}

function key(key, shiftKey = false) {
    return { key, shiftKey, prevented: false, stopped: false,
        preventDefault() { this.prevented = true; }, stopPropagation() { this.stopped = true; } };
}
async function flush() { await nextTick(); await nextTick(); }

for (const [label, path, customer] of [
    ['customer', 'resources/js/Components/CustomerMenu/ItemSheet.vue', true],
    ['waiter', 'resources/js/Components/WaiterPos/LineSheet.vue', false],
    ['customer cart', 'resources/js/Components/CustomerMenu/CartSheet.vue', 'cart'],
]) {
    test(`${label} dialog focuses, traps both directions, supports disclosures, and restores its trigger`, async () => {
        const ui = setupCustomizer(path, customer);
        try {
            ui.open();
            await flush();
            assert.equal(ui.document.activeElement.name, 'close');
            let event = key('Tab', true);
            ui.component.handleDialogKeydown(event);
            assert.equal(event.prevented, true);
            assert.equal(ui.document.activeElement.name, 'save');
            event = key('Tab');
            ui.component.handleDialogKeydown(event);
            assert.equal(event.prevented, true);
            assert.equal(ui.document.activeElement.name, 'close');
            ui.summary.focus();
            event = key('Tab');
            ui.component.handleDialogKeydown(event);
            assert.equal(event.prevented, false, 'middle disclosure keeps native keyboard navigation');
            event = key('Escape');
            ui.component.handleDialogKeydown(event);
            assert.equal(event.prevented, true);
            assert.equal(event.stopped, true, 'Escape must not also close a parent cart');
            assert.deepEqual(ui.emitted.at(-1), ['close']);
            ui.closeDialog();
            await flush();
            assert.equal(ui.document.activeElement.name, 'trigger');
            assert.equal(ui.document.body.style.overflow, 'clip', 'existing body lock is restored');
        } finally { ui.unmount(); await flush(); }
    });

    test(`${label} dialog handles no focusable controls and does not focus a removed trigger`, async () => {
        const ui = setupCustomizer(path, customer);
        try {
            ui.open();
            await flush();
            ui.panel.children = [];
            const event = key('Tab');
            ui.component.handleDialogKeydown(event);
            assert.equal(event.prevented, true);
            assert.equal(ui.document.activeElement.name, 'panel');
            ui.trigger.isConnected = false;
            const focusCount = ui.trigger.focusCount;
            ui.closeDialog();
            await flush();
            assert.equal(ui.trigger.focusCount, focusCount);
        } finally { ui.unmount(); await flush(); }
    });
}
