/** Browser connectivity is only a prerequisite; freshness requires a server response. */
export function serverHealth({ online = true, lastSuccessfulAt = null, failed = false, refreshing = false, now = Date.now(), staleAfterMs = 20000 } = {}) {
    const timestamp = typeof lastSuccessfulAt === 'number' ? lastSuccessfulAt : Date.parse(lastSuccessfulAt);
    const ageMs = Number.isFinite(timestamp) && timestamp > 0 ? Math.max(0, now - timestamp) : null;
    let state = 'current';
    if (!online) state = 'offline';
    else if (failed) state = 'failed';
    else if (ageMs === null) state = 'unknown';
    else if (ageMs > staleAfterMs) state = 'stale';
    return {
        state, ageMs,
        label: {
            offline: 'الجهاز غير متصل', failed: 'تعذّر الوصول للخادم',
            unknown: refreshing ? 'جارٍ التحقق من الخادم' : 'لم يتم التحقق من الخادم',
            stale: refreshing ? 'جارٍ تحديث البيانات القديمة' : 'البيانات تحتاج تحديثاً',
            current: 'الخادم يستجيب',
        }[state],
        lastSuccessLabel: ageMs === null ? 'لا توجد استجابة مؤكدة بعد' : ageMs < 60000
            ? `آخر استجابة منذ ${Math.floor(ageMs / 1000)} ث` : `آخر استجابة منذ ${Math.floor(ageMs / 60000)} د`,
    };
}
