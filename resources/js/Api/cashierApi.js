/**
 * One network seam for the cashier workspace.
 *
 * Financial commands will use the same envelope as polling. Components never
 * call fetch directly, which keeps CSRF, session-expiry handling, and Arabic
 * refusal messages consistent across every sheet.
 */

function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.content ?? '';
}

async function parseResponse(response) {
    let data;

    try {
        data = await response.json();
    } catch {
        throw new Error('لم يصل تأكيد من الخادم. أعد المحاولة من نفس النافذة.');
    }

    // A successful HTTP status alone does not acknowledge a financial write.
    // A proxy/login HTML page, null JSON, or an unrelated JSON response must
    // keep the command form and its retry token intact.
    if (!data || typeof data !== 'object' || Array.isArray(data)
        || (response.ok && typeof data.ok !== 'boolean')) {
        throw new Error('لم يصل تأكيد من الخادم. أعد المحاولة من نفس النافذة.');
    }

    return {
        ok: response.ok && data.ok === true,
        status: response.status,
        data,
    };
}

export async function fetchCashierState(endpoint, query, { signal } = {}) {
    const url = new URL(endpoint, window.location.origin);

    Object.entries(query).forEach(([key, value]) => {
        if (value !== null && value !== undefined && value !== '') {
            url.searchParams.set(key, String(value));
        }
    });

    const response = await fetch(url, {
        credentials: 'same-origin',
        headers: { Accept: 'application/json' },
        signal,
    });

    return parseResponse(response);
}

export async function sendCashierCommand(endpoint, body, method = 'POST') {
    const response = await fetch(endpoint, {
        method,
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrfToken(),
        },
        body: JSON.stringify(body),
    });

    return parseResponse(response);
}
