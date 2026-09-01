/**
 * Phase-3 wiring (MIGRATION-PILOT.md — Claude lane): the ONLY network
 * surface of the Vue POS. Components never fetch on their own — one file
 * owns the URLs, the CSRF header, and the response envelope, so the §7
 * contracts have exactly one client-side counterpart to audit.
 *
 * Envelope: every call resolves (never throws on HTTP errors) to
 *   { ok, status, data } — `ok` is true only when the transport succeeded
 * AND the server didn't refuse (§7 refusals carry data.ok === false).
 * Network failures (offline, server down) REJECT — callers use that to
 * keep the cart and tell the waiter it's saved locally.
 */

function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.content ?? '';
}

async function postJson(url, body) {
    const response = await fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrfToken(),
        },
        body: JSON.stringify(body),
    });

    let data = {};
    try {
        data = await response.json();
    } catch {
        // A non-JSON body (proxy error page, session expiry redirect) must
        // read as a refusal, not a success with empty data.
    }

    return {
        ok: response.ok && data.ok !== false,
        status: response.status,
        data,
    };
}

/** §7 — { token, notes, cart: CartLine[] } → { ok, order_number, fired, warning? }. */
export function submitOrder(tableId, payload) {
    return postJson(`/admin/waiter-orders/table/${tableId}/submit`, payload);
}

/** Replace a pending QR round with the at-table version and approve it once. */
export function reviewOrder(tableId, orderId, payload) {
    return postJson(`/admin/waiter-orders/table/${tableId}/review/${orderId}`, payload);
}

/** §7 — { cart } → { issues: [{ingredient, available}] }. Advisory only. */
export function previewStock(tableId, cart) {
    return postJson(`/admin/waiter-orders/table/${tableId}/preview-stock`, { cart });
}

/** ربط/فك زبون — { phone?, name?, create_if_missing?, detach? } → { ok, customer }. */
export function linkCustomer(tableId, payload) {
    return postJson(`/admin/waiter-orders/table/${tableId}/customer`, payload);
}

/** إعلان حوالة — { amount, sender_name, customer_phone?, notes? } → { ok }. */
export function recordTransfer(tableId, payload) {
    return postJson(`/admin/waiter-orders/table/${tableId}/transfer`, payload);
}

/**
 * The server's replayed-token refusal (§7). Matched by VALUE on purpose:
 * it means the order was already created and only the response got lost —
 * the client must treat it as delivered (clear + leave), never as an error
 * that invites a resend.
 */
export const REPLAYED_TOKEN_MESSAGE = 'الطلب انبعت من قبل — ما انبعت مرتين.';
