import { beep } from './audio';

/**
 * KDS sound signatures + the ID-SET diff rules:
 *
 *   - a NEW order id appears on the board        → bright rising two-tone
 *   - an id vanishes WITHOUT having been ready   → short falling pair (cancel)
 *   - the red-card count increases               → low square warning
 *
 * Tracking ID SETS (not counts): one ticket cancelled + one created in the
 * same refresh still chimes. Baseline on first sight and whenever the
 * table FILTER changes — ids leaving because the chef narrowed the view
 * are not cancellations. The mute choice persists in localStorage so it
 * survives reloads on the kitchen tablet.
 */
const state = {
    prevIds: null,          // Set|null — null = not baselined yet
    prevReadyIds: new Set(),
    prevChangeIds: new Set(),
    prevRed: 0,
    prevFilter: '',
};

export function kdsSoundEnabled() {
    try {
        return localStorage.getItem('kdsSoundMuted') !== '1';
    } catch {
        return true;
    }
}

export function setKdsSound(enabled) {
    try {
        localStorage.setItem('kdsSoundMuted', enabled ? '0' : '1');
    } catch { /* private mode — session-only */ }
}

export function playNewOrder() {
    beep(880, 0.18, 'sine', 0.35);
    setTimeout(() => beep(1175, 0.22, 'sine', 0.35), 180);
}

export function playCancelled() {
    beep(660, 0.1, 'sine', 0.3);
    setTimeout(() => beep(440, 0.12, 'sine', 0.3), 110);
}

export function playWarning() {
    beep(440, 0.18, 'square', 0.22);
    setTimeout(() => beep(440, 0.18, 'square', 0.22), 280);
}

export function playChangeRequest() {
    beep(988, 0.12, 'triangle', 0.34);
    setTimeout(() => beep(740, 0.12, 'triangle', 0.34), 130);
    setTimeout(() => beep(988, 0.16, 'triangle', 0.34), 260);
}

/** Feed every refresh's id lists through the diff; chimes when enabled. */
export function checkKdsChanges({ orderIds, readyIds, changeRequestIds = [], redCount, filter, enabled }) {
    const current = new Set([...orderIds, ...readyIds]);

    if (! (state.prevIds instanceof Set) || filter !== state.prevFilter) {
        state.prevIds = current;
        state.prevReadyIds = new Set(readyIds);
        state.prevChangeIds = new Set(changeRequestIds);
        state.prevRed = redCount;
        state.prevFilter = filter;

        return;
    }

    const added = [...current].filter((id) => ! state.prevIds.has(id));
    // Vanished without ever reaching the pass = cancelled; ids that WERE
    // on the ready strip left by being served — silent.
    const cancelled = [...state.prevIds].filter((id) => ! current.has(id) && ! state.prevReadyIds.has(id));
    const newChanges = changeRequestIds.filter((id) => ! state.prevChangeIds.has(id));

    if (enabled) {
        if (newChanges.length) playChangeRequest();
        else if (added.length) playNewOrder();
        else if (cancelled.length) playCancelled();
        else if (redCount > state.prevRed) playWarning();
    }

    state.prevIds = current;
    state.prevReadyIds = new Set(readyIds);
    state.prevChangeIds = new Set(changeRequestIds);
    state.prevRed = redCount;
    state.prevFilter = filter;
}
