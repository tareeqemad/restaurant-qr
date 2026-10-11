const stageStatus = { waiting: 'approved', cooking: 'preparing', ready: 'ready' };
const urgencyRank = { red: 4, orange: 3, amber: 2, green: 1 };

export function kdsDisconnected({ networkOnline, serverAvailable, lastContactAt, now }) {
    return !networkOnline || !serverAvailable || now - lastContactAt > 15000;
}

export function kdsCards(board) {
    return Object.keys(stageStatus).flatMap((stage) =>
        (board[stage] ?? []).map((card) => ({ ...card, _column: stage })),
    );
}

export function kdsCounts(board) {
    const cards = kdsCards(board);
    return {
        total: cards.length,
        ...Object.fromEntries(Object.entries(stageStatus).map(([stage, status]) =>
            [stage, cards.filter((card) => card.items.some((item) => item.status === status)).length],
        )),
    };
}

export function selectKdsCards(board, { focus = 'all', table = '', sort = 'urgency' } = {}) {
    let cards = kdsCards(board);
    if (table !== '') cards = cards.filter((card) => String(card.tableNum) === String(table));

    if (stageStatus[focus]) {
        cards = cards.flatMap((card) => {
            const items = card.items.filter((item) => item.status === stageStatus[focus]);
            if (!items.length) return [];
            const ready = focus === 'ready';
            const urgency = ready ? card.readyUrgency : card.urgency;
            return [{ ...card, items, _column: focus, urgency,
                urgencyRank: urgencyRank[urgency] ?? 0,
                ageMin: ready ? card.readyAgeMin : card.ageMin,
                etaMin: ready ? null : card.etaMin,
                pieceCount: String(Number(items.reduce((total, item) => total + Number(item.rawQty), 0).toFixed(2))),
            }];
        });
    }

    return cards.sort((a, b) => {
        if (sort === 'table') {
            if (!a.tableNum && b.tableNum) return 1;
            if (a.tableNum && !b.tableNum) return -1;
            return String(a.tableNum).localeCompare(String(b.tableNum), 'ar', { numeric: true }) || b.ageMin - a.ageMin;
        }
        if (sort === 'time') return b.ageMin - a.ageMin;
        return b.urgencyRank - a.urgencyRank || b.ageMin - a.ageMin;
    });
}
