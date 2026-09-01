import { computed, ref } from 'vue';
import { defineStore } from 'pinia';
import { fetchCashierState } from '../Api/cashierApi';

export const useCashierStore = defineStore('cashier-workspace', () => {
    const snapshot = ref(null);
    const stateEndpoint = ref('');
    const search = ref('');
    const mode = ref('all');
    const filter = ref('checkout');
    const selection = ref(null);
    const refreshing = ref(false);
    const lastError = ref('');
    const lastSuccessfulRefresh = ref(null);
    let requestSequence = 0;
    let activeRequest = null;

    const attention = computed(() => snapshot.value?.attention ?? []);
    const sessions = computed(() => snapshot.value?.queues?.sessions ?? []);
    const remoteOrders = computed(() => snapshot.value?.queues?.remote_orders ?? []);
    const workspace = computed(() => {
        const current = snapshot.value?.workspace ?? null;
        if (!selection.value || !current) return null;

        return current.kind === selection.value.kind
            && Number(current.id) === Number(selection.value.id)
            ? current
            : null;
    });
    const counts = computed(() => snapshot.value?.counts ?? {});
    const today = computed(() => snapshot.value?.today ?? {});
    const abilities = computed(() => snapshot.value?.abilities ?? {});

    function safeRefreshMessage(message) {
        const text = String(message || '').trim();
        if (!text || text.length > 240 || /SQLSTATE|\bselect\b|\binsert\b|\bupdate\b|stack trace|connection:/i.test(text)) {
            return 'تعذّر تحديث بيانات الكاشير. أعد المحاولة، وإن استمرت المشكلة أبلغ مدير النظام.';
        }

        return text;
    }

    function start(initialState, endpoint) {
        snapshot.value = initialState;
        stateEndpoint.value = endpoint;
        search.value = initialState.filters?.search ?? '';
        mode.value = initialState.filters?.mode ?? 'all';
        filter.value = initialState.filters?.filter ?? 'checkout';
        selection.value = null;

        if (initialState.filters?.session_id) {
            selection.value = { kind: 'session', id: Number(initialState.filters.session_id) };
        } else if (initialState.filters?.order_id) {
            selection.value = { kind: 'order', id: Number(initialState.filters.order_id) };
        }

        lastSuccessfulRefresh.value = initialState.generated_at ?? null;
    }

    async function refresh({ force = false } = {}) {
        if (!stateEndpoint.value) return { changed: false, skipped: true };

        const sequence = ++requestSequence;
        activeRequest?.abort();
        activeRequest = new AbortController();

        refreshing.value = true;
        lastError.value = '';

        try {
            const result = await fetchCashierState(stateEndpoint.value, {
                mode: mode.value,
                filter: filter.value,
                search: search.value,
                session_id: selection.value?.kind === 'session' ? selection.value.id : null,
                order_id: selection.value?.kind === 'order' ? selection.value.id : null,
                since: force ? null : snapshot.value?.version,
                full: force ? 1 : null,
            }, { signal: activeRequest.signal });

            if (sequence !== requestSequence) return { changed: false, stale: true };

            if (!result.ok) {
                throw new Error(safeRefreshMessage(result.data.message));
            }

            if (result.data.data?.changed) {
                snapshot.value = result.data.data;

                // A payment can close a session while it is open. Returning to
                // the queue is clearer than leaving a stale receipt on screen.
                if (selection.value && !result.data.data.workspace) selection.value = null;
            }

            lastSuccessfulRefresh.value = result.data.data?.generated_at ?? lastSuccessfulRefresh.value;
            return { changed: Boolean(result.data.data?.changed), skipped: false };
        } catch (error) {
            if (error?.name === 'AbortError' || sequence !== requestSequence) {
                return { changed: false, stale: true };
            }

            lastError.value = safeRefreshMessage(error.message || 'انقطع الاتصال بالخادم.');
            throw error;
        } finally {
            if (sequence === requestSequence) {
                refreshing.value = false;
                activeRequest = null;
            }
        }
    }

    async function select(kind, id) {
        const numericId = Number(id);
        if (!numericId || !['session', 'order'].includes(kind)) return;

        selection.value = { kind, id: numericId };
        await refresh({ force: true });
    }

    async function clearSelection() {
        selection.value = null;
        await refresh({ force: true });
    }

    return {
        snapshot,
        search,
        mode,
        filter,
        selection,
        refreshing,
        lastError,
        lastSuccessfulRefresh,
        attention,
        sessions,
        remoteOrders,
        workspace,
        counts,
        today,
        abilities,
        start,
        refresh,
        select,
        clearSelection,
    };
});
