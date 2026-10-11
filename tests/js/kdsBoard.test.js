import test from 'node:test';
import assert from 'node:assert/strict';
import { kdsCounts, kdsDisconnected, selectKdsCards } from '../../resources/js/Support/kdsBoard.js';

const mixed = {
    orderId: 1, tableNum: 'A1', ageMin: 20, urgency: 'red', urgencyRank: 4,
    readyAgeMin: 2, readyUrgency: 'green', etaMin: 4,
    items: [
        { id: 11, status: 'approved', rawQty: 1 },
        { id: 12, status: 'preparing', rawQty: 2 },
        { id: 13, status: 'ready', rawQty: 1.5 },
    ],
};
const board = { waiting: [mixed], cooking: [], ready: [] };

test('a mixed ticket is counted in each relevant stage without duplicating All', () => {
    assert.deepEqual(kdsCounts(board), { total: 1, waiting: 1, cooking: 1, ready: 1 });
    assert.equal(selectKdsCards(board).length, 1);
});

test('Ready exposes partial readiness, its quantity, and time on the pass', () => {
    const [card] = selectKdsCards(board, { focus: 'ready' });
    assert.deepEqual(card.items.map((item) => item.id), [13]);
    assert.equal(card._column, 'ready');
    assert.equal(card.pieceCount, '1.5');
    assert.equal(card.ageMin, 2);
    assert.equal(card.urgency, 'green');
    assert.equal(card.etaMin, null);
    assert.equal(mixed.items.length, 3, 'filtering must not mutate the all-stage ticket');
});

test('Cooking exposes only preparation lines to its bulk action', () => {
    const [card] = selectKdsCards(board, { focus: 'cooking' });
    assert.deepEqual(card.items.map((item) => item.id), [12]);
});

test('table matching preserves prefixes, suffixes and leading zeroes and can be cleared', () => {
    const labels = ['A1', 'B1', '12A', '12B', '01', '1', '0'];
    const data = { waiting: labels.map((tableNum, orderId) => ({ ...mixed, tableNum, orderId })), cooking: [], ready: [] };
    for (const table of labels) {
        assert.deepEqual(selectKdsCards(data, { table }).map((card) => card.tableNum), [table]);
    }
    assert.equal(selectKdsCards(data, { table: '' }).length, labels.length);
});

test('table sorting is natural and keeps off-table tickets after named tables', () => {
    const data = { waiting: ['', 'A10', 'A2'].map((tableNum) => ({ ...mixed, tableNum })), cooking: [], ready: [] };
    assert.deepEqual(selectKdsCards(data, { sort: 'table' }).map((card) => card.tableNum), ['A2', 'A10', '']);
});

test('connection health requires recent server contact, not just working Wi-Fi', () => {
    const state = { networkOnline: true, serverAvailable: true, lastContactAt: 1000, now: 2000 };
    assert.equal(kdsDisconnected(state), false);
    assert.equal(kdsDisconnected({ ...state, serverAvailable: false }), true);
    assert.equal(kdsDisconnected({ ...state, now: 16001 }), true);
    assert.equal(kdsDisconnected({ ...state, networkOnline: false }), true);
    assert.equal(kdsDisconnected({ ...state, now: 20000, lastContactAt: 19900 }), false);
});
