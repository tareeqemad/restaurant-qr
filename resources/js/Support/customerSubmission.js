import { ref } from 'vue';

const clone = (value) => JSON.parse(JSON.stringify(value));

export function createCustomerSubmission(scope, storage) {
    const key = `customer_pending_submission.${scope}`;
    const pending = ref(null);
    function readStored() {
        const saved = JSON.parse(storage.getItem(key) || 'null');
        if (saved === null) return null;
        if (saved?.scope === scope && typeof saved.payload?._idem === 'string'
            && saved.payload._idem.length > 0 && Array.isArray(saved.rows) && saved.rows.length > 0) return saved;
        throw new Error('Invalid saved customer submission');
    }
    try {
        pending.value = readStored();
    } catch { /* A new submission will require a successful persistent write. */ }

    function begin(payload, rows) {
        if (pending.value) return clone(pending.value.payload);
        // Another tab may have started a request after this page mounted.
        // Adopt that operation instead of overwriting its recovery record.
        try { pending.value = readStored(); }
        catch { throw new Error('تعذّر قراءة تأكيد الطلب المحفوظ. حدّث الصفحة للتحقق قبل الإرسال.'); }
        if (pending.value) return clone(pending.value.payload);
        if (!scope || !payload._idem || !rows.length) throw new Error('حدّث الصفحة قبل إرسال الطلب.');
        const saved = { scope, payload: clone(payload), rows: clone(rows) };
        // Persist before sending; failure must never issue an unprotected write.
        try {
            const encoded = JSON.stringify(saved);
            storage.setItem(key, encoded);
            if (storage.getItem(key) !== encoded) throw new Error('Submission persistence failed');
        }
        catch { throw new Error('تعذّر حفظ تأكيد الطلب على الجهاز. لم يُرسل؛ جرّب مجدداً.'); }
        pending.value = saved;
        return clone(saved.payload);
    }
    function clear() {
        // A failed cleanup is harmless: the saved token can only replay its receipt.
        try {
            const saved = readStored();
            if (saved && pending.value && JSON.stringify(saved.payload) === JSON.stringify(pending.value.payload)) {
                storage.removeItem(key);
            }
        } catch { /* Recover via the durable receipt. */ }
        pending.value = null;
    }
    return { pending, begin, clear, storageKey: key };
}

export async function readCustomerAcknowledgement(response, token) {
    const data = await response.json().catch(() => null);
    const valid = data !== null && typeof data === 'object' && !Array.isArray(data);
    const committed = Boolean(valid && response.ok && data.ok === true && data.submission_state === 'committed'
        && data.receipt?.token === token && Number.isInteger(data.receipt?.orderId) && data.receipt.orderId > 0
        && typeof data.orderNumber === 'string' && data.orderNumber.length > 0
        && typeof data.submitToken === 'string' && data.submitToken.length > 0
        && Array.isArray(data.orders) && Array.isArray(data.cart));
    const rejected = Boolean(valid && !response.ok && (data.submission_state === 'rejected'
        || (response.status === 422 && data.errors && typeof data.errors === 'object')));
    return { committed, rejected, data: valid ? data : null };
}
