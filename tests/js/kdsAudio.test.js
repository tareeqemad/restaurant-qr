import test from 'node:test';
import assert from 'node:assert/strict';
import 'vue';

test('audio readiness remains false until resume succeeds and follows suspension', async () => {
    const priorWindow = globalThis.window;
    const priorDocument = globalThis.document;
    let context;
    class AudioContext {
        state = 'suspended';
        fail = true;
        listeners = new Map();
        constructor() { context = this; }
        addEventListener(name, listener) { this.listeners.set(name, listener); }
        async resume() {
            if (this.fail) throw new Error('Device refused audio');
            this.state = 'running';
            this.listeners.get('statechange')?.();
        }
    }
    globalThis.window = { AudioContext };
    globalThis.document = { addEventListener() {} };
    try {
        const { audioReady, unlockAudio } = await import('../../resources/js/Support/audio.js');
        assert.equal(audioReady.value, false);
        assert.equal(await unlockAudio(), false);
        assert.equal(audioReady.value, false);
        context.fail = false;
        assert.equal(await unlockAudio(), true);
        assert.equal(audioReady.value, true);
        context.state = 'suspended';
        context.listeners.get('statechange')();
        assert.equal(audioReady.value, false);
    } finally {
        globalThis.window = priorWindow;
        globalThis.document = priorDocument;
    }
});
