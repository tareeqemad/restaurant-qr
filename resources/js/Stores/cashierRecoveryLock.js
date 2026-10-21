/** Serialize the short local journal mutation across tabs. IndexedDB also works on HTTP LANs. */
export function withCashierRecoveryLock(key, callback, locks = globalThis.navigator?.locks, database = globalThis.indexedDB) {
    if (locks?.request) return locks.request(key, callback);
    if (!database) return Promise.reject(new Error('تعذّر تأمين سجل استعادة الدفع على هذا المتصفح. جرّب متصفحًا يدعم تخزين الجهاز.'));
    return new Promise((resolve, reject) => {
        const request = database.open('cashier-recovery-locks', 1);
        request.onupgradeneeded = () => request.result.createObjectStore('locks');
        request.onerror = () => reject(new Error('تعذّر تأمين سجل الاستعادة؛ لم تُرسل العملية.'));
        request.onsuccess = () => {
            const connection = request.result;
            const transaction = connection.transaction('locks', 'readwrite');
            const store = transaction.objectStore('locks');
            let result;
            let failure;
            store.get(key).onsuccess = () => {
                try { result = callback(); store.put(Date.now(), key); }
                catch (error) { failure = error; transaction.abort(); }
            };
            transaction.oncomplete = () => { connection.close(); resolve(result); };
            transaction.onabort = transaction.onerror = () => { connection.close(); reject(failure || new Error('تعذّر تأمين سجل الاستعادة؛ لم تُرسل العملية.')); };
        };
    });
}
