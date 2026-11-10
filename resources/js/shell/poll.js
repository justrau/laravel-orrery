/**
 * The polling rules of the SPA, as pure helpers so that
 * Node can test them without a browser.
 */

import {ANY_ERROR_STATUS, BACKGROUND_FAILURES, isReadRequest, terminalState} from '../errors.js';

/**
 * The `handles` (errors.js) of the shell's background reads (the failure
 * groups, the counts, the batches): every error status except 401, 403 and
 * 419 (ANY_ERROR_STATUS, so a status that no list names is handled too), and
 * every failure without an answer. The part then shows as unknown,
 * without a global alert on every poll. Every 401 and 403 reaches the
 * terminal rules of errors.js; errors.js decides them before
 * `handles` anyway.
 */
export const BACKGROUND_HANDLES = [ANY_ERROR_STATUS, ...BACKGROUND_FAILURES];

/**
 * The `handles` of the shell's live reads (workload, workers, health): the
 * same as the background reads. There is no special case for 403: the
 * terminal rules of errors.js now cover every 401 and 403.
 */
export const LIVE_HANDLES = BACKGROUND_HANDLES;

/** Reads of the shared HTTP client time out after this long. */
export const READ_TIMEOUT_MS = 30000;

/**
 * The single-flight rule of a poll: a new run never starts while the
 * previous one is pending, however long it takes. Every read settles within
 * READ_TIMEOUT_MS, so every flight ends.
 *
 * `run(request)` calls `request()` and returns a promise that resolves (never
 * rejects) when the request's result settles, or null when a run is pending.
 * A request that throws, rejects or returns a value that is not a promise
 * ends its flight too.
 */
export function createSingleFlight() {
    let current = null;

    return {
        get pending() {
            return current !== null;
        },

        run(request) {
            // A terminal state (errors.js: session expired or access lost) stops every poll.
            if (current !== null || terminalState() !== null) return null;

            let result;

            try {
                result = request();
            } catch (error) {
                result = null;
            }

            const flight = Promise.resolve(result).then(() => undefined, () => undefined).then(() => {
                if (current === flight) current = null;
            });

            current = flight;

            return flight;
        },
    };
}

/**
 * The shell's live reads with an extra refresh on request:
 * a screen asks for fresh live data after a control
 * write, without breaking the single-flight rule.
 *
 * - `poll()` starts `run()` when nothing is pending, else joins the pending
 *   flight (Poll.vue's own single flight then waits for it);
 * - `request()` starts `run()` when nothing is pending; else it queues ONE
 *   more run that starts when the pending flight ends (several requests
 *   during one flight give one run);
 * - a run that throws or rejects ends its flight too.
 *
 * Both return the promise of the flight they start or join, which never
 * rejects.
 */
export function createLiveRefresher(run) {
    let current = null;
    let queued = false;

    const start = () => {
        let result;

        try {
            result = run();
        } catch (error) {
            result = null;
        }

        const flight = Promise.resolve(result)
            .then(() => undefined, () => undefined)
            .then(() => {
                current = null;

                if (queued) {
                    queued = false;

                    if (terminalState() === null) start();
                }
            });

        current = flight;

        return flight;
    };

    return {
        get pending() {
            return current !== null;
        },

        poll() {
            if (!current && terminalState() !== null) return Promise.resolve();

            return current || start();
        },

        request() {
            if (!current && terminalState() !== null) return Promise.resolve();

            if (current) {
                queued = true;

                return current;
            }

            return start();
        },
    };
}

/**
 * The timeout (ms) of a request config (support/http.js): READ_TIMEOUT_MS for a read
 * (errors.js isReadRequest(): a safe method, or a POST of READ_POSTS),
 * unless the request sets its own. Other
 * writes keep no timeout (0): their outcome would be unknown, and the
 * screens already say so on an error.
 */
export function requestTimeout(config) {
    const timeout = config && typeof config.timeout === 'number' ? config.timeout : 0;

    if (timeout > 0) return timeout;

    return isReadRequest(config) ? READ_TIMEOUT_MS : 0;
}
