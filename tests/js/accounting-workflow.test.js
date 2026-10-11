import test from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { computed, effectScope, nextTick, reactive, ref, watch } from 'vue'
import { localDateInput } from '../../resources/js/Utils/dateInput.js'
import { createAccountingToken } from '../../resources/js/Components/Accounting/accountingToken.js'

test('accounting forms get valid unique UUIDs on LAN browsers without randomUUID', () => {
    const uuid = /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/
    assert.match(createAccountingToken({ getRandomValues(bytes) { bytes.fill(1) } }), uuid)
    const tokens = Array.from({ length: 50 }, () => createAccountingToken({}))
    tokens.forEach(token => assert.match(token, uuid))
    assert.equal(new Set(tokens).size, tokens.length)
})

// Execute the actual setup code with a reactive Inertia props object. This
// exercises a preserved page receiving a replacement report without a DOM.
function setupPage(name, props, exposed, extras = {}) {
    const source = readFileSync(new URL(`../../resources/js/Pages/Admin/Accounting/${name}.vue`, import.meta.url), 'utf8')
    const script = source.match(/<script setup>([\s\S]*?)<\/script>/)[1]
        .replace(/^import .*$/gm, '')
        .replace(/defineOptions\(\{.*?\}\);?/g, '')
    const imports = { computed, reactive, ref, watch, localDateInput, defineProps: () => props, ...extras }
    return new Function(...Object.keys(imports), `${script}\nreturn { ${exposed.join(',')} }`)(...Object.values(imports))
}

function report(month, sales, costs) {
    return {
        period: { from: `2026-${month}-01`, to: `2026-${month}-28` },
        sales: { gross_sales: sales, discounts_total: 0, net_sales: sales },
        costs: { cogs: costs, waste: 0, expenses: 0, refunds: 0 },
        profit: { gross_profit: sales - costs, net_profit: sales - costs },
    }
}

test('profit and loss replaces displayed totals and period after a preserved Inertia visit', async () => {
    const props = reactive({ report: report('08', 100, 40), currency: { symbol: 'ILS' }, urls: { index: '/profit-loss' } })
    const scope = effectScope()
    const page = scope.run(() => setupPage('ProfitLoss', props, ['r', 'rows', 'totalCosts', 'form'], { router: { get() {} } }))
    try {
        assert.equal(page.totalCosts.value, 40)
        props.report = report('09', 500, 150)
        await nextTick()
        assert.equal(page.r.value.profit.net_profit, 350)
        assert.equal(page.rows.value[0].value, 500)
        assert.equal(page.totalCosts.value, 150)
        assert.equal(page.form.from, '2026-09-01')
    } finally {
        scope.stop()
    }
})

test('previous month shortcut on March 31 selects February instead of overflowing into March', (t) => {
    t.mock.timers.enable({ apis: ['Date'], now: new Date(2026, 2, 31, 12) })
    const props = reactive({ report: report('03', 1, 0), currency: { symbol: 'ILS' }, urls: { index: '/profit-loss' } })
    let request
    const scope = effectScope()
    const page = scope.run(() => setupPage('ProfitLoss', props, ['month'], { router: { get(url, data) { request = { ...data } } } }))
    try {
        page.month(-1)
        assert.deepEqual(request, { from: '2026-02-01', to: '2026-02-28' })
    } finally {
        scope.stop()
    }
})

test('profit and loss uses a neutral result for zero and updates the label with the result', () => {
    const props = reactive({ report: report('10', 100, 100), currency: { symbol: 'ILS' }, urls: { index: '/profit-loss' } })
    const scope = effectScope()
    const page = scope.run(() => setupPage('ProfitLoss', props, ['resultState', 'resultLabel', 'resultMessage'], { router: { get() {} } }))
    try {
        assert.equal(page.resultState.value, 'neutral')
        assert.equal(page.resultLabel.value, 'صافي النتيجة')
        assert.equal(page.resultMessage.value, 'صافي النتيجة صفر في هذه الفترة.')
        props.report = report('10', 100, 40)
        assert.equal(page.resultState.value, 'profit')
        assert.equal(page.resultLabel.value, 'صافي الربح')
        props.report = report('10', 100, 140)
        assert.equal(page.resultState.value, 'loss')
        assert.equal(page.resultLabel.value, 'صافي الخسارة')
    } finally {
        scope.stop()
    }
})

test('journal export uses the applied displayed filters, including search, rather than an unsubmitted draft', () => {
    const props = reactive({ filters: { from: null, to: '2026-10-11', search: 'JE-old', eventType: 'manual_journal' }, urls: { export: '/journal.csv' } })
    const window = { location: { href: '' } }
    const page = setupPage('Journal', props, ['form', 'exportCsv'], { window, router: {} })
    page.form.search = 'unsaved search'
    page.form.from = '2026-10-01'
    page.exportCsv()
    const url = new URL(window.location.href, 'http://localhost')
    assert.equal(url.searchParams.get('search'), 'JE-old')
    assert.equal(url.searchParams.get('from'), '')
    assert.equal(url.searchParams.get('event_type'), 'manual_journal')
})
