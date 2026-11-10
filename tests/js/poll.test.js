// Tests of resources/js/shell/poll.js: the single-flight rule of Poll.vue and
// the request timeouts of the shared HTTP client.

import {test} from 'node:test';
import assert from 'node:assert/strict';

import {BACKGROUND_HANDLES, createLiveRefresher, createSingleFlight, LIVE_HANDLES, READ_TIMEOUT_MS, requestTimeout} from '../../resources/js/shell/poll.js';

const never = () => new Promise(() => {});

// Rejects: the 60 s escape of the old Poll.vue (STALLED_MS), and any other time-based escape.
test('a poll never starts while one is pending, however long', t => {
    t.mock.timers.enable({apis: ['Date', 'setTimeout'], now: 0});

    const flight = createSingleFlight();
    let calls = 0;
    const request = () => {
        calls++;

        return never();
    };

    assert.notEqual(flight.run(request), null);
    assert.equal(flight.pending, true);

    for (const step of [1000, 59000, 1000, 3600 * 1000, 24 * 3600 * 1000]) {
        t.mock.timers.tick(step);
        assert.equal(flight.run(request), null);
    }

    assert.equal(calls, 1);
    assert.equal(flight.pending, true);
});

// Rejects: a flight that never clears after a rejection or a throw, and a flight that clears before the request settles.
test('the next poll starts after a rejected request', async () => {
    const flight = createSingleFlight();
    let reject;
    let calls = 0;

    const first = flight.run(() => {
        calls++;

        return new Promise((_, no) => { reject = no; });
    });
    assert.equal(flight.run(() => { calls++; }), null);

    reject(new Error('timeout of 30000ms exceeded'));
    await first;

    assert.equal(flight.pending, false);
    await flight.run(() => {
        calls++;
        throw new Error('sync');
    });
    assert.equal(flight.pending, false);
    await flight.run(() => { calls++; return 'not a promise'; });

    assert.equal(calls, 3);
});

// Rejects: no timeout on reads (a stuck GET blocks every later poll), a timeout on writes (their outcome
// would be unknown), and a read-only POST (the bulk freeze) without the read timeout.
test('requestTimeout gives reads and the freeze POST 30 s, and other writes none', () => {
    assert.equal(READ_TIMEOUT_MS, 30000);
    assert.equal(requestTimeout({method: 'get', url: '/orrery/api/workload', timeout: 0}), 30000);
    assert.equal(requestTimeout({method: 'GET', url: '/orrery/api/workload'}), 30000);
    assert.equal(requestTimeout({url: '/orrery/api/workload'}), 30000, 'reads without a method are GETs');
    assert.equal(requestTimeout({method: 'head', url: '/x'}), 30000);
    assert.equal(requestTimeout({method: 'post', url: '/orrery/api/bulk/freeze'}), 30000);
    assert.equal(requestTimeout({method: 'post', url: '/orrery/api/bulk/freeze?x=1'}), 30000);
    assert.equal(requestTimeout({method: 'post', url: '/orrery/api/bulk/run', timeout: 0}), 0);
    assert.equal(requestTimeout({method: 'post', url: '/orrery/api/bulk/freeze-extra'}), 0);
    // The group selection POST is gone: it is a write path like any other now.
    assert.equal(requestTimeout({method: 'post', url: '/orrery/api/failures/selection'}), 0);
    assert.equal(requestTimeout({method: 'delete', url: '/orrery/api/tags/monitored/x'}), 0);
    assert.equal(requestTimeout({method: 'get', url: '/x', timeout: 5000}), 5000, 'an explicit timeout stays');
});

// Rejects: live or background reads that swallow 401 or 403 (every 401 and 403 reaches the
// terminal rules from every request), and background reads that alert on every hung or failed poll.
test('every read leaves 401, 403 and 419 to the error rules, and handles its other failures', async () => {
    const {describeFailure} = await import('../../resources/js/errors.js');
    const failure = (status, handles, data = {}) => describeFailure({response: {status, data}, config: {method: 'get', handles}});

    for (const status of [401, 403, 419]) {
        assert.ok(!LIVE_HANDLES.includes(status), `live reads handle ${status}`);
        assert.ok(!BACKGROUND_HANDLES.includes(status), `background reads handle ${status}`);
    }
    assert.deepEqual(LIVE_HANDLES, BACKGROUND_HANDLES);
    assert.equal(failure(403, BACKGROUND_HANDLES, {error: 'forbidden'}).terminal, 'access_lost');
    assert.equal(failure(401, BACKGROUND_HANDLES).terminal, 'session_expired');
    assert.equal(failure(500, LIVE_HANDLES), null);
    assert.equal(failure(404, BACKGROUND_HANDLES), null);
    for (const kind of ['timeout', 'network', 'aborted']) {
        assert.ok(LIVE_HANDLES.includes(kind) && BACKGROUND_HANDLES.includes(kind), kind);
    }
});

// Rejects: polls that keep starting after the session expired or the access was lost.
test('no poll and no live refresh starts in a terminal state', async () => {
    const {enterTerminal, resetTerminalForTesting} = await import('../../resources/js/errors.js');

    resetTerminalForTesting();

    try {
        const flight = createSingleFlight();
        let runs = 0;
        const refresher = createLiveRefresher(() => {
            runs++;

            return Promise.resolve();
        });

        assert.equal(enterTerminal('session_expired'), true);
        assert.equal(enterTerminal('access_lost'), false, 'the first terminal state stays');

        assert.equal(flight.run(() => {
            runs++;

            return Promise.resolve();
        }), null);
        await refresher.poll();
        await refresher.request();
        assert.equal(runs, 0);
        assert.equal(refresher.pending, false);
    } finally {
        resetTerminalForTesting();
    }
});

// Rejects: a refresh queued during a pending flight that still runs after the page entered a terminal state.
test('a queued live refresh does not run after a terminal state', async () => {
    const {enterTerminal, resetTerminalForTesting} = await import('../../resources/js/errors.js');

    resetTerminalForTesting();

    try {
        let release;
        let runs = 0;
        const refresher = createLiveRefresher(() => {
            runs++;

            return new Promise(resolve => { release = resolve; });
        });

        const first = refresher.poll();
        refresher.request();
        enterTerminal('access_lost');
        release();
        await first;
        await Promise.resolve();

        assert.equal(runs, 1);
        assert.equal(refresher.pending, false);
    } finally {
        resetTerminalForTesting();
    }
});

// Rejects: a refresh that starts a second run while one is pending (two overlapping shell polls), and a request that is dropped.
test('a live refresh request runs once more after the pending flight', async () => {
    const releases = [];
    let runs = 0;
    const refresher = createLiveRefresher(() => {
        runs++;

        return new Promise(resolve => releases.push(resolve));
    });

    const first = refresher.poll();
    assert.equal(runs, 1);
    assert.equal(refresher.pending, true);

    refresher.request();
    refresher.request();
    assert.equal(refresher.poll(), first, 'a poll joins the pending flight');
    assert.equal(runs, 1, 'no second run while one is pending');

    releases[0]();
    await first;
    await new Promise(resolve => setImmediate(resolve));
    assert.equal(runs, 2, 'the queued request runs once, after the flight');

    releases[1]();
    await new Promise(resolve => setImmediate(resolve));
    assert.equal(refresher.pending, false);
    assert.equal(runs, 2);
});

// Rejects: a request that waits for a poll when nothing is pending, and a flight that a throwing run never ends.
test('a live refresh request runs at once when idle, and a failed run ends its flight', async () => {
    let runs = 0;
    const refresher = createLiveRefresher(() => {
        runs++;
        throw new Error('boom');
    });

    await refresher.request();
    assert.equal(runs, 1);
    assert.equal(refresher.pending, false);
    await refresher.poll();
    assert.equal(runs, 2);
});

// Rejects: a fixed list of statuses for the background reads, so a status off the list (Cloudflare's 520 to
// 526, a proxy's 507) opens the modal alert on every poll, and a handler that also swallows 401, 403 or 419.
test('background reads handle every error status except 401, 403 and 419', async () => {
    const {describeFailure} = await import('../../resources/js/errors.js');
    const failure = (status, data = {}) => describeFailure({response: {status, data}, config: {method: 'get', handles: BACKGROUND_HANDLES}});

    for (const status of [400, 418, 451, 501, 507, 520, 522, 526, 599]) {
        assert.equal(failure(status), null, `status ${status}`);
    }
    assert.equal(failure(401).terminal, 'session_expired');
    assert.equal(failure(403, {error: 'forbidden'}).terminal, 'access_lost');
    assert.equal(failure(419).type, 'reload');
});
