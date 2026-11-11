/**
 * The held navigation (installed by app.js), as Inertia or Nuxt do it: the
 * URL, the sidebar's highlight and the content stay on the old screen
 * until the next screen's first data has arrived, then everything changes
 * at once. A thin progress bar shows while it waits.
 *
 * - Each screen's route declares its first load (`meta.load(to, context)`,
 *   a pure function of its `screens/<screen>/*.js` module that builds the
 *   requests with the same builders the screen uses). The guard starts them
 *   through the shared `$http` prefetch (support/prefetch.js), and the
 *   screen's own first GET of the same read takes the answer: nothing is
 *   fetched twice, and the screen mounts with its data.
 * - The first page load, a deep link and a change within one screen do not
 *   hold (shouldHold()).
 * - A failed read commits like a good one: the screen then reads again and
 *   shows its own error or stale state. A slow read commits after
 *   HOLD_TIMEOUT_MS and the screen shows its loading state; the read stays
 *   in flight for the screen to take.
 * - The last navigation wins: a newer navigation supersedes the waiting one
 *   (router.beforeEach), whose reads are discarded and aborted.
 * - A refused leave (a screen's beforeRouteLeave) runs before this guard
 *   (beforeResolve), so it still works.
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
 * Whether a navigation holds: a change of screen after the first load (a
 * first load or a deep link has nothing to hold), to a route whose meta
 * declares its first load. A change within one screen (a filter, a tab,
 * /queues to /queues/:connection/:queue) keeps the screen and does not hold.
 */
export function shouldHold(from, to) {
    const fromScreen = routeScreen(from);

    if (fromScreen === null || fromScreen === routeScreen(to)) return false;

    return !!(to && to.meta && typeof to.meta.load === 'function');
}

/**
 * The hold's state machine: `begin()` starts a hold and returns its token,
 * `wait(token, requests)` ends it when the requests settle (resolves
 * 'ready'), at the timeout ('timeout'), when a newer hold began ('stale')
 * or when it was cancelled ('cancel'). `release(token)` ends the hold of that token only;
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
 * The guard of the held navigation, a function for router.beforeResolve():
 * `(to, from)` resolves true to commit, false for a navigation that a newer
 * one superseded. `load(to)` gives the route's requests, `prefetch(requests)`
 * starts them and returns entries with a `promise`, `discard(entries)`
 * drops the entries of a superseded navigation. `guard.supersede()` (for
 * router.beforeEach()) ends a waiting hold: the last navigation wins.
 */
export function createNavigationGuard({hold, load, prefetch, discard}) {
    const guard = async (to, from) => {
        if (!shouldHold(from, to)) return true;

        let requests = [];

        try {
            requests = load(to) || [];
        } catch (error) {
            requests = [];
        }

        if (!Array.isArray(requests) || requests.length === 0) return true;

        const token = hold.begin();
        const entries = prefetch(requests);
        const outcome = await hold.wait(token, entries.map(entry => entry.promise));

        if (outcome === 'ready' || outcome === 'timeout') return true;

        discard(entries);

        return false;
    };

    guard.supersede = () => hold.cancel();

    return guard;
}

/**
 * Whether the keyboard focus was lost by the change of screen: on nothing,
 * on the body, or on an element that left the page with the old screen.
 * Then the shell moves it to the new screen's heading; a focus on a live
 * control (a sidebar link) stays.
 */
export function focusLost(active, doc) {
    if (!active) return true;
    if (doc && active === doc.body) return true;

    return active.isConnected === false;
}
