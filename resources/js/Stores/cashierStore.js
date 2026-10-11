import { computed, ref } from 'vue';
import { defineStore } from 'pinia';
import { fetchCashierState } from '../Api/cashierApi.js';

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
    const financialUpdate = ref(null);
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

    function workspaceMoneyState(value) {
        if (!value) return null;

        const total = value.invoice
            ? Number(value.invoice.balance || 0)
            : (value.orders ?? [])
                .filter((order) => order.status !== 'cancelled')
                .reduce((sum, order) => sum + Number(order.total || 0), 0);
        const lines = (value.orders ?? []).flatMap((order) =>
            (order.items ?? []).map((item) => [
                Number(item.id),
                item.status === 'cancelled' ? 'cancelled' : 'billable',
                Number(item.quantity || 0),
                Number(item.subtotal || 0),
            ]),
        );

        return { total, signature: JSON.stringify(lines) };
    }

    function start(initialState, endpoint) {
        stop();
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
        lastError.value = '';
        financialUpdate.value = null;
    }

    function stop() {
        requestSequence++;
        activeRequest?.abort();
        activeRequest = null;
        refreshing.value = false;
    }

    async function refresh({ force = false, source = 'manual', scope = 'all' } = {}) {
        if (!stateEndpoint.value) return { changed: false, skipped: true };
        // A background pulse must never cancel a bill the cashier is opening.
        if (source === 'poll' && activeRequest) return { changed: false, skipped: true };

        const filtersChanged = snapshot.value?.filters?.mode !== mode.value
            || snapshot.value?.filters?.filter !== filter.value
            || snapshot.value?.filters?.search !== search.value;
        const needsFullRead = force || filtersChanged || (selection.value && !workspace.value);

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
                since: needsFullRead ? null : snapshot.value?.version,
                full: needsFullRead ? 1 : null,
                scope: scope === 'workspace' ? 'workspace' : null,
            }, { signal: activeRequest.signal });

            if (sequence !== requestSequence) return { changed: false, stale: true };

            if (!result.ok) {
                throw new Error(safeRefreshMessage(result.data.message));
            }

            const data = result.data.data;
            if (!data || typeof data.changed !== 'boolean'
                || (data.changed && !Object.hasOwn(data, 'workspace'))) {
                throw new Error('تعذّر قراءة بيانات الكاشير. أعد التحديث.');
            }

            if (data.changed) {
                const previousMoney = workspaceMoneyState(workspace.value);
                // A bill-only response leaves queue data AND its version alone.
                // Advancing that version would hide changes from the next poll.
                snapshot.value = data.scope === 'workspace'
                    ? { ...snapshot.value, workspace: data.workspace }
                    : data;

                // A payment can close a session while it is open. Returning to
                // the queue is clearer than leaving a stale receipt on screen.
                if (selection.value && !data.workspace) selection.value = null;

                const currentMoney = workspaceMoneyState(workspace.value);
                if (source === 'poll'
                    && previousMoney
                    && currentMoney
                    && (previousMoney.total !== currentMoney.total
                        || previousMoney.signature !== currentMoney.signature)) {
                    financialUpdate.value = {
                        id: Date.now(),
                        previousTotal: previousMoney.total,
                        currentTotal: currentMoney.total,
                    };
                }
            }

            lastSuccessfulRefresh.value = data.generated_at ?? lastSuccessfulRefresh.value;
            return { changed: data.changed, skipped: false };
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
        await refresh({ force: true, scope: 'workspace' });
    }

    async function clearSelection() {
        stop();
        selection.value = null;
        if (snapshot.value) snapshot.value = { ...snapshot.value, workspace: null };
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
        financialUpdate,
        attention,
        sessions,
        remoteOrders,
        workspace,
        counts,
        today,
        abilities,
        start,
        stop,
        refresh,
        select,
        clearSelection,
    };
});
