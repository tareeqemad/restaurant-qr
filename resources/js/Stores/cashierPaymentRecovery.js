/** One immutable unresolved payment per staff/branch workspace, persisted BEFORE sending. */
import { withCashierRecoveryLock } from './cashierRecoveryLock.js';
export function createPaymentRecovery(storage, scope, locks = globalThis.navigator?.locks) {
    const key = `cashier-payment-recovery:v1:${scope}`;
    const read = () => {
        const raw = storage.getItem(key);
        if (!raw) return null;
        const operation = JSON.parse(raw);
        if (operation.version !== 1 || !operation.token || !Number.isInteger(operation.invoiceId)
            || !(Number(operation.payload?.amount) > 0) || !operation.payload?.method) {
            throw new Error('تعذّر قراءة عملية الدفع المحفوظة. لا تبدأ دفعة بديلة؛ راجع السجل مع مسؤول النظام.');
        }
        return operation;
    };
    const journal = {
        read,
        save(operation) {
            const existing = read();
            if (existing && existing.token !== operation.token) {
                throw new Error('توجد دفعة سابقة بانتظار التحقق. تحقّق منها قبل بدء دفعة جديدة.');
            }
            if (existing && (existing.invoiceId !== operation.invoiceId || JSON.stringify(existing.payload) !== JSON.stringify(operation.payload))) {
                throw new Error('لا يمكن تغيير دفعة قيد التحقق. استعد نتيجة العملية الأصلية أولاً.');
            }
            const value = JSON.stringify({ ...operation, version: 1 });
            storage.setItem(key, value);
            if (storage.getItem(key) !== value) throw new Error('تعذّر حفظ معلومات استعادة الدفع على هذا الجهاز. لم تُرسل الدفعة.');
            return JSON.parse(value);
        },
        clear(token) {
            if (read()?.token === token) storage.removeItem(key);
        },
    };
    const exclusive = (callback) => withCashierRecoveryLock(key, callback, locks);
    return { ...journal, reserve: operation => exclusive(() => journal.save(operation)), clearConfirmed: token => exclusive(() => journal.clear(token)) };
}
