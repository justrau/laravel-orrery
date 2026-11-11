// Tests of resources/js/support/navigation.js: the rules of the held
// navigation (the URL, the sidebar and the content wait together until the
// next screen's first data arrived, or until a timeout).

import {test} from 'node:test';
import assert from 'node:assert/strict';
import {createMemoryHistory, createRouter} from 'vue-router';

import {
    HOLD_TIMEOUT_MS,
    PROGRESS_DELAY_MS,
    createNavigationGuard,
    createNavigationHold,
    focusLost,
    inertWhileHeld,
    routeScreen,
    shouldHold,
} from '../../resources/js/support/navigation.js';

const Overview = {name: 'overview'};
const Jobs = {name: 'jobs'};
const START = {matched: []};
const load = () => [{url: '/api/x', params: {}}];
const route = (component, {path = '/x', meta = {load}} = {}) => ({path, meta, matched: [{components: {default: component}}]});

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
    assert.equal(routeScreen({matched: [{components: {default: Overview}}, {components: {default: Jobs}}]}), Jobs);
    assert.equal(routeScreen(START), null);
    assert.equal(routeScreen(null), null);
    assert.equal(routeScreen({matched: [{}]}), null);
});

// Rejects: a hold on the first page load or a deep link (nothing to hold), on a change within one screen (a filter
// or a tab: today's behaviour), or for a route without a first load.
// Still passes: a rule that holds a route whose load returns no request (the guard then commits at once).
test('shouldHold: only a change of screen after the first load, to a route with a first load', () => {
    assert.equal(shouldHold(START, route(Overview)), false);
    assert.equal(shouldHold(undefined, route(Overview)), false);
    assert.equal(shouldHold(route(Overview), route(Jobs)), true);
    assert.equal(shouldHold(route(Jobs, {path: '/jobs'}), route(Jobs, {path: '/jobs?status=failed'})), false);
    assert.equal(shouldHold(route(Overview), route(Jobs, {meta: {}})), false);
});

// Rejects: a hold that ends before its reads settle, that ignores a failed read (an error commits too), or that
// shows the bar at once (a flash on every fast navigation).
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
test('a slow read ends the hold at the timeout', async () => {
    const timers = fakeTimers();
    const hold = createNavigationHold(timers);
    const waited = hold.wait(hold.begin(), [new Promise(() => {})]);

    timers.run(HOLD_TIMEOUT_MS - 1);
    assert.equal(hold.state().held, true);
    timers.run(1);
    assert.equal(hold.state().held, false);
    assert.equal(await waited, 'timeout');
    assert.equal(timers.pending(), 0);
});

// Rejects: an older navigation's reads ending the newer hold, or a restart that keeps the first timeout.
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
    assert.equal(hold.state().held, true);
    timers.run(HOLD_TIMEOUT_MS - 1000);
    assert.equal(hold.state().held, true, 'the second hold has its own timeout');
    second.resolve();
    assert.equal(await secondWait, 'ready');
    assert.equal(hold.release(one), false, 'a stale token releases nothing');
});

// Rejects: a cancel that leaves the hold, its timers, or its waiter (a superseded guard would wait forever).
test('cancel ends the current hold and tells its waiter', async () => {
    const timers = fakeTimers();
    const hold = createNavigationHold(timers);
    const waited = hold.wait(hold.begin(), [new Promise(() => {})]);

    hold.cancel();
    assert.equal(hold.state().held, false);
    assert.equal(timers.pending(), 0);
    assert.equal(await waited, 'cancel');
});

/** A guard with fake prefetching: each request becomes an entry whose promise the test settles. */
function guardWith(timers) {
    const hold = createNavigationHold(timers);
    const started = [];
    const discarded = [];
    const armed = [];
    const guard = createNavigationGuard({
        hold,
        load: to => to.meta.load(to),
        prefetch: requests => requests.map(request => {
            const answer = deferred();
            const entry = {request, promise: answer.promise, answer};

            started.push(entry);

            return entry;
        }),
        discard: entries => discarded.push(...entries),
        arm: entries => armed.push(...entries),
    });

    return {hold, guard, started, discarded, armed};
}

// Rejects: a guard that commits before the first data arrived, that blocks on a failed read, or that holds the
// first load.
// Still passes: a guard that starts the reads in another order (one request per entry is checked).
test('the guard starts the next screen\'s reads, waits for them, then commits', async () => {
    const timers = fakeTimers();
    const {guard, started} = guardWith(timers);

    assert.equal(await guard(route(Overview), START), true, 'the first load commits at once');
    assert.equal(started.length, 0);

    let done = null;
    const pending = guard(route(Jobs), route(Overview)).then(result => {
        done = result;
    });

    assert.equal(started.length, 1);
    await settle();
    assert.equal(done, null, 'waits for the read');
    started[0].answer.reject(new Error('503'));
    await pending;
    assert.equal(done, true, 'a failed read commits, and the screen shows its error');
});

// Rejects: a guard that never commits on a slow read.
test('the guard commits at the timeout and leaves the read in flight for the screen', async () => {
    const timers = fakeTimers();
    const {guard, discarded} = guardWith(timers);
    const pending = guard(route(Jobs), route(Overview));

    timers.run(HOLD_TIMEOUT_MS);
    assert.equal(await pending, true);
    assert.deepEqual(discarded, [], 'the screen takes the read when it answers');
});

// Rejects: a superseded guard that returns false (vue-router then aborts it and, for Back and Forward, moves the
// history back: the URL and the screen part), or whose prefetched answers stay for a later screen.
// Still passes: a guard that also aborts the request (the cache's discard does).
test('a newer navigation supersedes the older one: its reads are discarded, and the router cancels it', async () => {
    const timers = fakeTimers();
    const {guard, started, discarded} = guardWith(timers);
    const older = guard(route(Jobs), route(Overview));

    guard.supersede();
    assert.equal(await older, true);
    assert.deepEqual(discarded, [started[0]]);
});

// Rejects: entries that a request can take before the navigation committed (an old screen's poll would take the
// next screen's prefetch), or entries of a cancelled navigation that are armed when another one commits.
test('the entries of a navigation become takeable only when it commits', async () => {
    const timers = fakeTimers();
    const {guard, started, armed} = guardWith(timers);
    const jobs = route(Jobs);
    const pending = guard(jobs, route(Overview));

    started[0].answer.resolve({});
    assert.equal(await pending, true);
    assert.deepEqual(armed, [], 'not before the commit');
    guard.committed(jobs, null);
    assert.deepEqual(armed, [started[0]]);

    const failedTo = route(Overview);
    const next = guard(failedTo, jobs);

    started[1].answer.resolve({});
    await next;
    guard.committed(failedTo, {type: 4});
    assert.equal(armed.length, 1, 'a failed navigation arms nothing');
});

/** A real router (vue-router, memory history) with the guard installed as app.js installs it. */
function realRouter() {
    const answers = [];
    const load = () => [{url: '/api/x', params: {}}];
    const routes = ['/x', '/y', '/z'].map(path => ({path, component: {name: path}, meta: {load}}));
    const history = createMemoryHistory();
    const router = createRouter({history, routes});
    const hold = createNavigationHold({timeoutMs: 5000});
    const guard = createNavigationGuard({
        hold,
        load: to => to.meta.load(to),
        prefetch: requests => requests.map(() => {
            const answer = deferred();

            answers.push(answer);

            return {promise: answer.promise};
        }),
        discard: () => {},
        arm: () => {},
    });

    router.beforeEach(() => guard.supersede());
    router.beforeResolve(guard);
    router.afterEach((to, from, failure) => guard.committed(to, failure));


    const answerAll = () => answers.forEach(answer => answer.resolve({}));

    return {router, history, answerAll, hold};
}

// Rejects: a guard that aborts a superseded Back (vue-router moves the history again and the URL ends on another
// route than the screen), or pushes during a hold that end anywhere but on the last one.
// Still passes: a router that ends on the same route through other steps.
test('with a real router, a double Back during a hold and A, B, C pushes end on the last route, URL and screen alike', async () => {
    const {router, history, answerAll} = realRouter();
    const wait = ms => new Promise(resolve => setTimeout(resolve, ms));
    const finish = async () => {
        for (let round = 0; round < 5; round++) {
            await wait(5);
            answerAll();
        }

        await wait(10);
    };

    await router.push('/x');
    router.push('/y');
    await finish();
    router.push('/z');
    await finish();
    assert.equal(router.currentRoute.value.fullPath, '/z');
    assert.equal(history.location, '/z');

    // Two Back presses while the first one waits for its data.
    router.back();
    await wait(2);
    router.back();
    await finish();
    assert.equal(router.currentRoute.value.fullPath, '/x');
    assert.equal(history.location, router.currentRoute.value.fullPath);

    // A, B, C: B waits, C supersedes it.
    router.push('/y');
    await wait(2);
    router.push('/z');
    await finish();
    assert.equal(router.currentRoute.value.fullPath, '/z');
    assert.equal(history.location, '/z');
});

// Rejects: a click on the current screen's link during a hold that supersedes nothing (vue-router reports it as
// duplicated and runs no beforeEach), so the held navigation still commits.
test('with a real router, the current screen\'s link during a hold gives up the held navigation', async () => {
    const {router, history, answerAll, hold} = realRouter();
    const wait = ms => new Promise(resolve => setTimeout(resolve, ms));

    await router.push('/x');
    const held = router.push('/y');

    await wait(2);
    assert.equal(hold.state().held, true);
    await router.push('/x');
    assert.equal(hold.state().held, false, 'the hold and its bar end at once');
    answerAll();
    await held;
    await wait(10);
    assert.equal(router.currentRoute.value.fullPath, '/x');
    assert.equal(history.location, '/x');
});

// Rejects: a rule that leaves the old screen usable during a hold (it could start a write after its leave guard
// passed), or one that blocks it after the hold.
test('inertWhileHeld: the routed content is inert only while a navigation is held', () => {
    assert.equal(inertWhileHeld({held: true}), true);
    assert.equal(inertWhileHeld({held: false}), null);
    assert.equal(inertWhileHeld(null), null);
});

// Rejects: a load that throws and so breaks the navigation.
test('a load that throws holds nothing', async () => {
    const timers = fakeTimers();
    const {guard} = guardWith(timers);
    const broken = route(Jobs, {meta: {load: () => {
        throw new Error('bad');
    }}});

    assert.equal(await guard(broken, route(Overview)), true);
});

// Rejects: a focus move while the focus is still on a live control (a sidebar link: it would jump), or none when
// the focused element went away with the old screen.
test('focusLost: the focus is on nothing, on the body, or on a removed element', () => {
    const body = {isConnected: true};
    const doc = {body};

    assert.equal(focusLost(null, doc), true);
    assert.equal(focusLost(body, doc), true);
    assert.equal(focusLost({isConnected: false}, doc), true);
    assert.equal(focusLost({isConnected: true}, doc), false);
});
