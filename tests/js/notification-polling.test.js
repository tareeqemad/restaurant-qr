import test from 'node:test';
import assert from 'node:assert/strict';
import { createNotificationPolling } from '../../resources/js/Support/notificationPolling.js';

function setup() {
    let time = 0;
    let visible = true;
    let nextId = 0;
    const timers = new Map();
    const requests = [];
    const poller = createNotificationPolling({
        refresh: (signal) => new Promise((resolve) => requests.push({ signal, resolve })),
        canPoll: () => visible,
        now: () => time,
        setTimer: (callback, delay) => {
            const id = ++nextId;
            timers.set(id, { at: time + delay, callback });
            return id;
        },
        clearTimer: (id) => timers.delete(id),
    });
    return {
        poller, requests, timers,
        setVisible(value) { visible = value; },
        advance(ms) {
            time += ms;
            for (const [id, timer] of timers) {
                if (timer.at <= time) {
                    timers.delete(id);
                    timer.callback();
                }
            }
        },
    };
}

test('slow notification responses never overlap or catch up with interval ticks', async () => {
    const { poller, requests, timers, advance } = setup();
    poller.start();
    advance(8000);
    await poller.request();
    assert.equal(requests.length, 1);
    assert.equal(timers.size, 0, 'No queued interval while the response is outstanding.');
    requests[0].resolve();
    await Promise.resolve();
    advance(7999);
    assert.equal(requests.length, 1, 'A slow response gets an equally long recovery gap.');
    advance(1);
    assert.equal(requests.length, 2);
    poller.stop();
    requests[1].resolve();
});

test('hiding or navigating aborts the current refresh and blocks hidden polling', async () => {
    const { poller, requests, timers, setVisible, advance } = setup();
    poller.start();
    setVisible(false);
    poller.pause();
    assert.equal(requests[0].signal.aborted, true);
    requests[0].resolve();
    await Promise.resolve();
    advance(60000);
    await poller.request();
    assert.equal(requests.length, 1);
    assert.equal(timers.size, 0);
    setVisible(true);
    poller.resume();
    assert.equal(requests.length, 2);
    poller.stop();
    requests[1].resolve();
});

test('unmount stops timers and an old response cannot revive notification polling', async () => {
    const { poller, requests, timers, advance } = setup();
    poller.start();
    poller.stop();
    assert.equal(requests[0].signal.aborted, true);
    requests[0].resolve();
    await Promise.resolve();
    poller.resume();
    await poller.request();
    advance(60000);
    assert.equal(requests.length, 1);
    assert.equal(timers.size, 0);
});

test('pause and resume retain the in-flight lease until a fetch ignoring abort settles', async () => {
    const { poller, requests, timers, advance } = setup();
    poller.start();
    poller.pause();
    poller.resume();
    await poller.request();
    advance(60000);
    assert.equal(requests[0].signal.aborted, true);
    assert.equal(requests.length, 1, 'Abort alone must not release the outstanding request.');
    assert.equal(timers.size, 0);
    requests[0].resolve();
    await Promise.resolve();
    void poller.request();
    assert.equal(requests.length, 2, 'A new request is allowed only after the old fetch settles.');
    poller.stop();
    requests[1].resolve();
});

test('a hidden initial mount waits for visibility and removes a scheduled timer on pause', async () => {
    const { poller, requests, timers, setVisible, advance } = setup();
    setVisible(false);
    poller.start();
    assert.equal(requests.length, 0);
    setVisible(true);
    poller.resume();
    requests[0].resolve();
    await Promise.resolve();
    assert.equal(timers.size, 1);
    poller.pause();
    assert.equal(timers.size, 0);
    advance(60000);
    assert.equal(requests.length, 1);
    poller.stop();
});
