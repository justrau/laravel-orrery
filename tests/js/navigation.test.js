// Tests of resources/js/support/navigation.js: the rules of the held
// navigation (the old screen stays until the next screen's first data
// arrived, or until a timeout).

import {test} from 'node:test';
import assert from 'node:assert/strict';

import {
    HOLD_TIMEOUT_MS,
    PROGRESS_DELAY_MS,
    captureRequests,
    createNavigationHold,
    focusLost,
    holdStep,
    routeScreen,
} from '../../resources/js/support/navigation.js';

const Overview = {name: 'overview'};
const Jobs = {name: 'jobs'};
const START = {matched: []};
const route = (component, path = '/x') => ({path, matched: [{components: {default: component}}]});

/** Fake timers: `run(ms)` advances the clock and fires every due timer in order. */
function fakeTimers() {
    let now = 0;
    let next = 1;
    const timers = new Map();

    return {
        setTimer(fn, ms) {
            const id = next++;

            timers.set(id, {fn, at: now + ms});

            return id;
        },
        clearTimer(id) {
            timers.delete(id);
        },
        run(ms) {
            const end = now + ms;

            for (;;) {
                const due = [...timers.entries()].filter(([, timer]) => timer.at <= end).sort((a, b) => a[1].at - b[1].at)[0];

                if (!due) break;

                timers.delete(due[0]);
                now = due[1].at;
                due[1].fn();
            }

            now = end;
        },
        pending: () => timers.size,
    };
}

/** A promise with its resolve and reject. */
function deferred() {
    let resolve;
    let reject;
    const promise = new Promise((yes, no) => {
        resolve = yes;
        reject = no;
    });

    return {promise, resolve, reject};
}

const settle = () => new Promise(resolve => setImmediate(resolve));

// Rejects: a timeout so short that a normal read never holds, or so long that navigation feels stuck (> 2 s).
// Still passes: any value inside the band; the band itself is the brief's.
test('the hold times out between 1.5 and 2 seconds, and the bar waits a moment before it shows', () => {
    assert.ok(HOLD_TIMEOUT_MS >= 1500 && HOLD_TIMEOUT_MS <= 2000);
    assert.ok(PROGRESS_DELAY_MS > 0 && PROGRESS_DELAY_MS < HOLD_TIMEOUT_MS);
});

// Rejects: a screen read from the first match (a nested route's parent), or a throw on a route without matches.
// Still passes: a route whose deepest match has only named views (not used by the SPA).
test('routeScreen is the default component of the deepest match, or null', () => {
    const parent = {components: {default: Overview}};

    assert.equal(routeScreen({matched: [parent, {components: {default: Jobs}}]}), Jobs);
    assert.equal(routeScreen(START), null);
    assert.equal(routeScreen(null), null);
    assert.equal(routeScreen({matched: [{}]}), null);
});

// Rejects: a hold on the first page load (nothing to hold), on a failed or blocked navigation, or on a change
// within one screen (/queues to /queues/database/default keeps its instance); a second hold that does not restart.
// Still passes: a rule that holds a navigation to an unknown component (no route has none).
test('holdStep holds only a finished change of screen after the first load', () => {
    const overview = route(Overview, '/overview');
    const jobs = route(Jobs, '/jobs');

    assert.equal(holdStep({from: START, to: overview, failure: null, holding: false}), 'none');
    assert.equal(holdStep({from: undefined, to: overview, failure: null, holding: false}), 'none');
    assert.equal(holdStep({from: overview, to: jobs, failure: null, holding: false}), 'start');
    assert.equal(holdStep({from: overview, to: jobs, failure: {type: 4}, holding: false}), 'none');
    assert.equal(holdStep({from: overview, to: route(Overview, '/overview?a=1'), failure: null, holding: false}), 'none');
    // During a hold: another screen restarts the wait (the last navigation wins), the same screen keeps it.
    assert.equal(holdStep({from: jobs, to: overview, failure: null, holding: true}), 'restart');
    assert.equal(holdStep({from: jobs, to: route(Jobs, '/jobs?status=failed'), failure: null, holding: true}), 'keep');
    assert.equal(holdStep({from: jobs, to: overview, failure: {type: 8}, holding: true}), 'none');
});

// Rejects: a hold that ends before its reads settle, that ignores a failed read (an error switches too), or
// that shows the bar at once (a flash on every fast navigation).
// Still passes: a hold whose onChange is called twice with the same state (not observed by the shell).
test('a hold ends when its reads settle, and the bar shows only after the delay', async () => {
    const timers = fakeTimers();
    const changes = [];
    const hold = createNavigationHold({...timers, onChange: (state, reason) => changes.push({...state, reason})});
    const read = deferred();
    const failing = deferred();

    const token = hold.begin();

    assert.deepEqual(hold.state(), {held: true, progress: false});
    timers.run(PROGRESS_DELAY_MS - 1);
    assert.equal(hold.state().progress, false);
    timers.run(1);
    assert.deepEqual(hold.state(), {held: true, progress: true});

    const waited = hold.wait(token, [read.promise, failing.promise]);

    failing.reject(new Error('503'));
    await settle();
    assert.equal(hold.state().held, true, 'one read is still pending');

    read.resolve({});
    assert.equal(await waited, 'ready');
    assert.deepEqual(hold.state(), {held: false, progress: false});
    assert.equal(timers.pending(), 0, 'no timer is left behind');
    assert.deepEqual(changes.at(-1), {held: false, progress: false, reason: 'ready'});
});

// Rejects: a hold that waits forever on a slow read (navigation feels stuck), or that keeps its timers.
// Still passes: a hold that times out a few ms late (the fake clock is exact).
test('a slow read ends the hold at the timeout', async () => {
    const timers = fakeTimers();
    const hold = createNavigationHold(timers);
    const token = hold.begin();
    const waited = hold.wait(token, [new Promise(() => {})]);

    timers.run(HOLD_TIMEOUT_MS - 1);
    assert.equal(hold.state().held, true);
    timers.run(1);
    assert.equal(hold.state().held, false);
    assert.equal(await waited, 'timeout');
    assert.equal(timers.pending(), 0);
});

// Rejects: an older navigation's reads ending the newer hold (rapid clicks: only the last one wins), or a
// restart that keeps the first timeout (the new screen would get less time).
// Still passes: a hold that restarts the bar delay (the bar may blink once on a restart).
test('only the newest hold can end it', async () => {
    const timers = fakeTimers();
    const hold = createNavigationHold(timers);
    const first = deferred();
    const second = deferred();

    const one = hold.begin();
    const firstWait = hold.wait(one, [first.promise]);

    timers.run(1000);

    const two = hold.begin();
    const secondWait = hold.wait(two, [second.promise]);

    first.resolve();
    assert.equal(await firstWait, 'stale');
    assert.equal(hold.state().held, true, 'the first screen\'s reads do not end the second hold');

    timers.run(HOLD_TIMEOUT_MS - 1000);
    assert.equal(hold.state().held, true, 'the second hold has its own timeout');

    second.resolve();
    assert.equal(await secondWait, 'ready');
    assert.equal(hold.state().held, false);
    assert.equal(hold.release(one), false, 'a stale token releases nothing');
});

// Rejects: a hold without reads that waits for the timeout (a screen that reads only the shell's data).
// Still passes: a hold that ends one microtask later than necessary.
test('a hold without reads ends at once', async () => {
    const timers = fakeTimers();
    const hold = createNavigationHold(timers);

    assert.equal(await hold.wait(hold.begin(), []), 'ready');
    assert.equal(hold.state().held, false);
});

// Rejects: a cancel that leaves the hold (the shell unmounted) or its timers.
// Still passes: a cancel without a hold that reports a change (onChange is called with the same state).
test('cancel ends the current hold', () => {
    const timers = fakeTimers();
    const hold = createNavigationHold(timers);

    hold.begin();
    hold.cancel();
    assert.equal(hold.state().held, false);
    assert.equal(timers.pending(), 0);
});

// Rejects: a capture that misses a request started inside the window, keeps one started after stop(), or keeps
// listening after stop().
// Still passes: a capture of a request that another part of the page started in the window (the shell's poll).
test('captureRequests keeps the requests started between start and stop', () => {
    const listeners = new Set();
    const subscribe = listener => {
        listeners.add(listener);

        return () => listeners.delete(listener);
    };
    const start = promise => listeners.forEach(listener => listener(promise));
    const a = Promise.resolve('a');
    const b = Promise.resolve('b');

    start(Promise.resolve('before'));

    const capture = captureRequests(subscribe);

    start(a);
    start(b);

    const requests = capture.stop();

    start(Promise.resolve('after'));
    assert.deepEqual(requests, [a, b]);
    assert.equal(listeners.size, 0);
    assert.deepEqual(capture.stop(), [a, b], 'a second stop returns the same list');
});

// Rejects: a focus move while the focus is still on a live control (a sidebar link: it would jump), or none when
// the focused element went away with the old screen.
// Still passes: a rule that moves the focus from a hidden but connected element (not observed).
test('focusLost: the focus is on nothing, on the body, or on a removed element', () => {
    const body = {isConnected: true};
    const doc = {body};

    assert.equal(focusLost(null, doc), true);
    assert.equal(focusLost(body, doc), true);
    assert.equal(focusLost({isConnected: false}, doc), true);
    assert.equal(focusLost({isConnected: true}, doc), false);
});
