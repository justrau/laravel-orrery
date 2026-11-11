/**
 * The held navigation of the shell (AppShell.vue): when a navigation
 * changes the screen, the old screen stays visible (as a frozen copy) until
 * the new screen's first reads have settled, then the shell switches, as
 * Inertia or Nuxt do. A thin progress bar shows while it waits.
 *
 * - The first page load holds nothing (there is no old screen), and neither
 *   does a failed or blocked navigation, or a change within one screen
 *   (/queues and /queues/:connection/:queue keep one instance): holdStep().
 * - The first reads of the new screen are the requests that start while it
 *   mounts (captureRequests() over the shared `$http`'s onRequest()), so a
 *   screen needs no change and nothing is fetched twice. A screen that
 *   reads only the shell's live data starts none and shows at once.
 * - A failed read ends the hold like a good one: the screen then shows its
 *   own error or stale state. A slow read ends it after HOLD_TIMEOUT_MS,
 *   and the screen shows its own loading state: navigation never feels stuck.
 * - Rapid clicks: each navigation that changes the screen starts a new hold
 *   (a new token and a new timeout); only the newest hold can end it, so an
 *   older screen's reads never switch to a screen that is not the last one.
 */

/** The longest wait for the next screen's first data, in ms. */
export const HOLD_TIMEOUT_MS = 1600;

/** The wait before the progress bar shows, in ms: a fast switch shows no bar at all. */
export const PROGRESS_DELAY_MS = 120;

/** The screen of a route: the default component of its deepest match, or null. */
export function routeScreen(route) {
    const matched = route && Array.isArray(route.matched) ? route.matched : [];
    const record = matched.length ? matched[matched.length - 1] : null;

    return record && record.components && record.components.default ? record.components.default : null;
}

/**
 * What a finished navigation does to the hold (router.afterEach()):
 * 'none' (no hold: the first load, a failure, or the same screen),
 * 'start' (freeze the old screen and wait), 'restart' (a newer navigation
 * during a hold: keep the frozen copy, wait for the newest screen) or
 * 'keep' (the same screen during a hold: keep waiting).
 */
export function holdStep({from, to, failure, holding}) {
    if (failure) return 'none';

    const fromScreen = routeScreen(from);

    // The first navigation starts from a route without matches (vue-router's START_LOCATION).
    if (fromScreen === null) return 'none';

    if (fromScreen === routeScreen(to)) return holding ? 'keep' : 'none';

    return holding ? 'restart' : 'start';
}

/**
 * The hold's state machine: `begin()` starts a hold and returns its token,
 * `wait(token, requests)` ends it when the requests settle (resolves
 * 'ready'), at the timeout ('timeout'), or never when a newer hold began
 * ('stale'). `release(token)` ends the hold of that token only;
 * `cancel()` ends any hold. `onChange(state, reason)` gets
 * `{held, progress}` on every change.
 */
export function createNavigationHold({
    timeoutMs = HOLD_TIMEOUT_MS,
    progressDelayMs = PROGRESS_DELAY_MS,
    setTimer = (fn, ms) => setTimeout(fn, ms),
    clearTimer = id => clearTimeout(id),
    onChange = () => {},
} = {}) {
    let token = 0;
    let held = false;
    let progress = false;
    let timers = [];
    const waiters = new Map();

    const state = () => ({held, progress});
    const clear = () => {
        timers.forEach(id => clearTimer(id));
        timers = [];
    };

    function release(which, reason = 'ready') {
        if (!held || which !== token) return false;

        clear();
        held = false;
        progress = false;

        const resolve = waiters.get(which);

        waiters.delete(which);
        if (resolve) resolve(reason);

        onChange(state(), reason);

        return true;
    }

    function begin() {
        clear();

        // An older hold's waiter learns that it lost.
        waiters.forEach(resolve => resolve('stale'));
        waiters.clear();

        token += 1;
        held = true;

        const own = token;

        timers.push(setTimer(() => {
            if (own !== token || !held) return;

            progress = true;
            onChange(state(), 'progress');
        }, progressDelayMs));
        timers.push(setTimer(() => release(own, 'timeout'), timeoutMs));
        onChange(state(), 'begin');

        return own;
    }

    function wait(which, requests) {
        if (!held || which !== token) return Promise.resolve('stale');

        return new Promise(resolve => {
            waiters.set(which, resolve);

            Promise.allSettled(Array.isArray(requests) ? requests : []).then(() => release(which, 'ready'));
        });
    }

    return {
        begin,
        wait,
        release,
        cancel: () => release(token, 'cancel'),
        state,
    };
}

/**
 * Collects the requests that start between this call and `stop()`:
 * `subscribe(listener)` adds a listener for each new request's promise and
 * returns its removal (support/http.js onRequest()). `stop()` returns the
 * promises, in start order, and stops listening.
 */
export function captureRequests(subscribe) {
    const requests = [];
    let unsubscribe = subscribe(promise => requests.push(promise));

    return {
        stop() {
            if (unsubscribe) {
                unsubscribe();
                unsubscribe = null;
            }

            return requests.slice();
        },
    };
}

/**
 * Whether the keyboard focus was lost by the switch: on nothing, on the
 * body, or on an element that left the page with the old screen. Then the
 * shell moves it to the new screen's heading; a focus on a live control (a
 * sidebar link) stays.
 */
export function focusLost(active, doc) {
    if (!active) return true;
    if (doc && active === doc.body) return true;

    return active.isConnected === false;
}
