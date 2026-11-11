/**
 * The cache of the reads that a held navigation starts for the next screen
 * (support/navigation.js): the screen's own first GET of the same path and
 * params takes the prefetched answer, so nothing is fetched twice and the
 * screen mounts with its data.
 *
 * - Only GET. An entry is taken once; a later read (a poll) goes to the
 *   network.
 * - An entry can be taken only once it is armed: the guard arms the entries
 *   of a navigation when it commits (support/navigation.js). So a read that
 *   starts before (the old screen's poll, the shell's poll of the same
 *   read) never takes the next screen's answer.
 * - A new prefetch of the same read replaces and aborts an untaken older
 *   one.
 * - An entry in flight stays until it settles; a settled one stays
 *   PREFETCH_TTL_MS. A failed prefetch is dropped at once: its error went
 *   through the prefetch's own error handling, so the screen reads again and
 *   shows its own error.
 * - Params match as text, key by key (null and undefined are not sent, as
 *   http.js queryUrl() leaves them out). The keys of a time window that the
 *   screen computes from the clock (`from`, `to`, `since`) match within
 *   TIME_TOLERANCE_SECONDS: the screen computes them again a moment later.
 */

/** How long a settled prefetched answer can be taken, in ms. */
export const PREFETCH_TTL_MS = 3000;

/** The tolerance of a time key (epoch seconds) between the prefetch and the screen's read. */
export const TIME_TOLERANCE_SECONDS = 10;

const sent = params => Object.entries(params || {}).filter(([, value]) => value !== null && value !== undefined);

/** Whether two query objects ask the same read: every sent key as text, `timeKeys` within the tolerance. */
export function paramsMatch(stored, asked, timeKeys = []) {
    const a = new Map(sent(stored).map(([key, value]) => [key, value]));
    const b = new Map(sent(asked).map(([key, value]) => [key, value]));

    if (a.size !== b.size) return false;

    for (const [key, value] of a) {
        if (!b.has(key)) return false;

        const other = b.get(key);

        if (timeKeys.includes(key)) {
            if (!(Math.abs(Number(value) - Number(other)) <= TIME_TOLERANCE_SECONDS)) return false;
        } else if (String(value) !== String(other)) {
            return false;
        }
    }

    return true;
}

/**
 * A cache: `put(url, params, timeKeys, promise, abort)` adds an entry and
 * returns it; `arm(entries)` makes entries takeable; `take(method, url,
 * params)` gives the matching armed promise once, or null;
 * `discard(entries)` removes and aborts entries; `clear()`.
 */
export function createPrefetchCache({now = () => Date.now(), ttlMs = PREFETCH_TTL_MS} = {}) {
    let entries = [];

    const remove = entry => {
        entries = entries.filter(other => other !== entry);
    };
    const prune = () => {
        const at = now();

        entries = entries.filter(entry => entry.settledAt === null || at - entry.settledAt <= ttlMs);
    };

    return {
        put(url, params, timeKeys, promise, abort = null) {
            const entry = {url, params: {...(params || {})}, timeKeys: Array.isArray(timeKeys) ? timeKeys : [], promise, abort, settledAt: null, armed: false};

            entries
                .filter(other => other.url === url && paramsMatch(other.params, entry.params, []))
                .forEach(other => {
                    remove(other);

                    if (typeof other.abort === 'function') other.abort();
                });
            entries.push(entry);
            promise.then(
                () => {
                    entry.settledAt = now();
                },
                () => remove(entry),
            );

            return entry;
        },

        take(method, url, params) {
            if (String(method || '').toLowerCase() !== 'get') return null;

            prune();

            const entry = entries.find(candidate => candidate.armed && candidate.url === url && paramsMatch(candidate.params, params, candidate.timeKeys));

            if (!entry) return null;

            remove(entry);

            return entry.promise;
        },

        arm(list) {
            (Array.isArray(list) ? list : []).forEach(entry => {
                entry.armed = true;
            });
        },

        discard(list) {
            (Array.isArray(list) ? list : []).forEach(entry => {
                if (!entries.includes(entry)) return;

                remove(entry);

                if (typeof entry.abort === 'function') entry.abort();
            });
        },

        clear() {
            entries = [];
        },
    };
}
