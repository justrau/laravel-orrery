/**
 * The global handling of failed API requests: one axios interceptor shows a
 * message for every failed request, and then rejects, so that each screen
 * can clear its own loading state in its catch.
 *
 * A request that handles a status itself names it in its config, for example
 * `{ handles: [404] }` for a detail screen that shows its own not-found state.
 * A background read (a poll) also names 'timeout' and 'network': its section
 * shows its own error and the toolbar says "Stale", so a hung or unreachable
 * server does not raise the global alert on every poll. User-started
 * requests and writes keep the global alert for both.
 */

import {codeText} from './support/wording.js';

/**
 * The authentication answers of Authenticate:
 *
 * - 401, and 419 on a write: the terminal session-expired state;
 * - 403 `forbidden`: the terminal access-lost state;
 * - 403 `forbidden_action`: one alert for that action only;
 * - a 403 without a known `error` code (a host middleware answered it): on a
 *   read (a safe method) the access-lost state, on a write the alert for
 *   that action.
 *
 * - 403 `header_missing` (a write without `X-Requested-With`): no
 *   permission problem, so not FORBIDDEN_ACTION; describeFailure() words it.
 *
 * A terminal state ignores the request's `handles`: every poll stops (the
 * single flights of shell/poll.js start nothing), every pending read is
 * aborted, later reads are refused before they go out, and one alert offers a
 * reload. Writes are never aborted: their outcome would be unknown.
 */
export const SESSION_EXPIRED = 'session_expired';
export const ACCESS_LOST = 'access_lost';
export const FORBIDDEN_ACTION = 'forbidden_action';

const SAFE_METHODS = ['get', 'head', 'options', 'trace'];

/** POSTs that only read (the bulk freeze writes nothing): they get the timeout of a GET (shell/poll.js) and are aborted as reads. */
export const READ_POSTS = ['/api/bulk/freeze'];

const TERMINAL_MESSAGES = {
    [SESSION_EXPIRED]: 'Your session has expired. Reload the page to sign in again.',
    [ACCESS_LOST]: 'You no longer have access to this dashboard. Reload the page after your access is restored.',
};

/** The terminal state of this page, or null. */
let terminal = null;

/** Aborts the pending reads when the page enters a terminal state. */
let reads = null;

function methodOf(config) {
    return String((config && config.method) || 'get').toLowerCase();
}

/**
 * Whether a request only reads: a safe method, or one of READ_POSTS. Only
 * reads are aborted in a terminal state.
 */
export function isReadRequest(config) {
    const method = methodOf(config);

    if (SAFE_METHODS.includes(method)) return true;

    const path = String((config && config.url) || '').split(/[?#]/)[0];

    return method === 'post' && READ_POSTS.some(read => path.endsWith(read));
}

/**
 * The authentication case of a failed request: SESSION_EXPIRED, ACCESS_LOST,
 * FORBIDDEN_ACTION or null. A write is decided by the HTTP method, as the
 * server's operate check is.
 */
export function authFailure(error) {
    const response = error && error.response;
    const status = response ? response.status : null;
    const write = !SAFE_METHODS.includes(methodOf(error && error.config));

    if (status === 401 || (status === 419 && write)) return SESSION_EXPIRED;

    if (status !== 403) return null;

    const code = response.data && typeof response.data === 'object' ? response.data.error : null;

    if (code === 'forbidden') return ACCESS_LOST;

    if (code === 'forbidden_action') return FORBIDDEN_ACTION;

    if (code === 'header_missing') return null;

    return write ? FORBIDDEN_ACTION : ACCESS_LOST;
}

/** The terminal state of the page (SESSION_EXPIRED or ACCESS_LOST), or null. */
export function terminalState() {
    return terminal;
}

/**
 * Enters a terminal state and aborts every pending read. Returns false when
 * the page is already in one (the first state stays).
 */
export function enterTerminal(kind) {
    if (terminal !== null) return false;

    terminal = kind;

    if (reads) reads.abort();

    return true;
}

/** The abort signal that the shared axios instance gives each read. */
export function readSignal() {
    if (!reads) reads = new AbortController();

    return reads.signal;
}

/** Leaves the terminal state (Node tests only: a real page reloads). */
export function resetTerminalForTesting() {
    terminal = null;
    reads = null;
}

/**
 * The `handles` entry that covers every error status (400 and up) except
 * 401, 403 and 419, which always reach the rules of this module: a poll
 * that names it shows no alert for a status that no list foresaw (a
 * proxy's 520 to 526).
 */
export const ANY_ERROR_STATUS = 'any_error_status';

/** The statuses that ANY_ERROR_STATUS leaves to the rules of this module. */
const NEVER_HANDLED = [401, 403, 419];

/** The `handles` entries that cover failures without an HTTP answer. */
export const BACKGROUND_FAILURES = ['timeout', 'network', 'aborted'];

/**
 * The kind of a failure without an HTTP answer: 'timeout' (the request's
 * timeout passed), 'aborted' (the browser aborted the request), 'network'
 * (no answer), or null when the server answered. Axios gives both a timeout
 * and an abort the code ECONNABORTED (ETIMEDOUT with clarifyTimeoutError);
 * only the timeout's message names the timeout.
 */
export function failureKind(error) {
    if (error && error.response) return null;

    const code = error && error.code;

    if (code === 'ETIMEDOUT') return 'timeout';

    if (code === 'ECONNABORTED') {
        return /timeout/i.test(String(error.message || '')) ? 'timeout' : 'aborted';
    }

    return 'network';
}

/** A dismissed message is not shown again for this long (polls repeat a failure every few seconds). */
export const REPEAT_AFTER_MS = 30000;

/**
 * The message and the alert type for a failed request, or null when the
 * request handles the status itself.
 */
export function describeFailure(error) {
    if (error && error.__CANCEL__) {
        return null;
    }

    const auth = authFailure(error);

    // Every 401 and 403 reaches these rules, whatever the request handles.
    if (auth === SESSION_EXPIRED || auth === ACCESS_LOST) {
        return {type: 'reload', message: TERMINAL_MESSAGES[auth], terminal: auth};
    }

    if (auth === FORBIDDEN_ACTION) {
        return {type: 'error', message: 'You are not allowed to do this.', action: true};
    }

    const response = error && error.response;
    const status = response ? response.status : null;
    const handles = (error && error.config && error.config.handles) || [];
    const code = response && response.data && typeof response.data === 'object' ? response.data.error : null;

    // A queue pause or continue whose Laravel part happened (503 `record_failed`): the change is done, and the
    // screen that sent it shows its own warning, so no "request failed" alert.
    if (code === 'record_failed') {
        return null;
    }

    // A write refused for its missing header is not a permission problem; it repeats only with the user's action.
    if (status === 403 && code === 'header_missing') {
        return {type: 'error', message: codeText('header_missing'), action: true};
    }

    if (status !== null && handles.includes(status)) {
        return null;
    }

    if (status !== null && status >= 400 && !NEVER_HANDLED.includes(status) && handles.includes(ANY_ERROR_STATUS)) {
        return null;
    }

    const kind = status === null ? failureKind(error) : null;

    if (kind !== null && handles.includes(kind)) {
        return null;
    }

    // A 419 on a read (a write's 419 is terminal above).
    if (status === 419) {
        return {
            type: 'reload',
            message: 'Your session has expired or the page is out of date. Reload the page to continue.',
        };
    }

    if (kind === 'timeout') {
        return {
            type: 'error',
            message: 'The server did not answer in time. Try again.',
        };
    }

    if (kind === 'aborted') {
        return {
            type: 'error',
            message: 'The request was interrupted. Try again.',
        };
    }

    if (status === null) {
        return {
            type: 'error',
            message: 'The server could not be reached. Check your connection and try again.',
        };
    }

    const serverMessage = response.data && typeof response.data.message === 'string' && response.data.message !== ''
        ? ' ' + response.data.message
        : '';

    return {
        type: 'error',
        message: `The request failed (HTTP ${status}).${serverMessage}`,
    };
}

/**
 * Whether the failure goes to the alert: never over another alert, and not
 * again within REPEAT_AFTER_MS after the same message was dismissed.
 */
export function shouldShow(alert, lastDismissed, message, now) {
    if (alert.type) {
        return false;
    }

    return !(lastDismissed
        && lastDismissed.message === message
        && now - lastDismissed.at < REPEAT_AFTER_MS);
}

/**
 * A caller's own signal combined with readSignal(). A browser without
 * AbortSignal.any (before Chrome 116, Safari 17.4, Firefox 124) keeps the
 * caller's signal alone: the read is then refused at its next start, but not
 * aborted while pending.
 */
function combinedSignal(own) {
    return typeof AbortSignal.any === 'function' ? AbortSignal.any([own, readSignal()]) : own;
}

/**
 * Installs the interceptors on the given axios instance. `root` returns the
 * mounted root component, whose `alert` the layout renders.
 *
 * - Each read gets the abort signal of readSignal(); a read that brings its
 *   own signal gets both (AbortSignal.any), so its caller and a terminal
 *   state can each abort it. In a terminal state a read is refused before it
 *   goes out (a cancel, which raises no alert).
 * - A failure that enters a terminal state replaces any open alert with the
 *   terminal one, also when the same text was dismissed before. In a
 *   terminal state, no other alert is shown; the terminal one is shown again
 *   for a write that fails (not a cancel) while no alert is open.
 * - A refused action (403 forbidden_action) is shown every time no alert is
 *   open: each one is the user's own action, not a repeating poll.
 */
export function installErrorHandling(http, root) {
    http.interceptors.request.use(config => {
        if (!isReadRequest(config)) return config;

        if (terminal !== null) {
            return Promise.reject({__CANCEL__: true, message: 'The page is in the ' + terminal + ' state.', config});
        }

        config.signal = config.signal ? combinedSignal(config.signal) : readSignal();

        return config;
    });

    http.interceptors.response.use(
        response => response,
        error => {
            const failure = describeFailure(error);
            const vm = root();
            const open = !!(vm && vm.alert.type);
            let show = false;

            if (failure && failure.terminal && enterTerminal(failure.terminal)) {
                show = !!vm;
            } else if (terminal !== null) {
                const cancel = !!(error && error.__CANCEL__);

                show = !!vm && !open && !cancel && !isReadRequest(error && error.config);
            } else if (failure && failure.action) {
                show = !!vm && !open;
            } else if (failure) {
                show = !!vm && shouldShow(vm.alert, vm.lastDismissedAlert, failure.message, Date.now());
            }

            if (show) {
                const message = terminal !== null ? TERMINAL_MESSAGES[terminal] : failure.message;
                const type = terminal !== null ? 'reload' : failure.type;

                vm.alert.type = type;
                vm.alert.message = message;
                vm.alert.autoClose = 0;
                vm.alert.confirmationProceed = type === 'reload' ? () => window.location.reload() : null;
                vm.alert.confirmationCancel = null;
            }

            return Promise.reject(error);
        }
    );
}
