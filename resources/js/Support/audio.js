/**
 * Notification chime for Vue boards — the window.playNotify port.
 *
 * Same two-tone signature as the Blade layout's audio system (880Hz then
 * 1320Hz, 120ms apart) so the floor keeps its familiar sound. AudioContext
 * unlocks on the first user gesture (autoplay policy); until then
 * playNotify() silently no-ops — that's browser law, don't "fix" it.
 *
 * Registered once per page load; safe to import from multiple components.
 */
import { readonly, ref } from 'vue';

let ctx = null;
let unlocked = false;
const readiness = ref(false);
export const audioReady = readonly(readiness);

function syncReadiness() {
    readiness.value = Boolean(unlocked && ctx?.state === 'running');
}

function ensureContext() {
    if (! ctx) {
        const AC = window.AudioContext || window.webkitAudioContext;
        if (! AC) return null;
        try {
            ctx = new AC();
            ctx.addEventListener('statechange', syncReadiness);
        } catch {
            return null;
        }
    }
    return ctx;
}

export async function unlockAudio() {
    const c = ensureContext();
    if (! c) return false;
    try {
        if (c.state === 'suspended') await c.resume();
        unlocked = c.state === 'running';
    } catch {
        unlocked = false;
    }
    syncReadiness();
    return readiness.value;
}

['pointerdown', 'keydown', 'touchstart', 'click'].forEach((evt) => {
    document.addEventListener(evt, unlockAudio, { passive: true });
});

function tone(c, freq, at, duration = 0.18, peak = 0.18) {
    const osc = c.createOscillator();
    const gain = c.createGain();
    osc.type = 'sine';
    osc.frequency.value = freq;
    gain.gain.setValueAtTime(0.0001, at);
    gain.gain.exponentialRampToValueAtTime(peak, at + 0.02);
    gain.gain.exponentialRampToValueAtTime(0.0001, at + duration);
    osc.connect(gain).connect(c.destination);
    osc.start(at);
    osc.stop(at + duration + 0.05);
}

export function playNotify() {
    const c = ensureContext();
    if (! c || ! unlocked || c.state !== 'running') return;
    const now = c.currentTime;
    tone(c, 880, now);
    tone(c, 1320, now + 0.12);
}

/**
 * Raw beep for board-specific sound signatures (the KDS chimes). Same
 * shared AudioContext and unlock rules as playNotify.
 */
export function beep(frequency, duration, type = 'sine', volume = 0.25) {
    const c = ensureContext();
    if (! c || ! unlocked || c.state !== 'running') return;
    const osc = c.createOscillator();
    const gain = c.createGain();
    osc.type = type;
    osc.frequency.setValueAtTime(frequency, c.currentTime);
    gain.gain.setValueAtTime(0.0001, c.currentTime);
    gain.gain.exponentialRampToValueAtTime(volume, c.currentTime + 0.01);
    gain.gain.exponentialRampToValueAtTime(0.0001, c.currentTime + duration);
    osc.connect(gain).connect(c.destination);
    osc.start();
    osc.stop(c.currentTime + duration + 0.05);
}
