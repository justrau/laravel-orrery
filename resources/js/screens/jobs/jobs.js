/**
 * The rules of the Jobs list screen.
 * Pure helpers, so Node can test them (tests/js/jobs.test.js); index.vue
 * only renders what they decide.
 *
 * The rules of the SPA hold: every time goes through toSeconds() and is
 * shown in local time; an unknown value is "—" or "unknown", never 0; a
 * capped count says "at least" or "+"; every code is worded through
 * support/wording.js; every text is a template filled with stored data and
 * rendered as text.
 */

import {UNREADABLE_ANSWER, isShapeError} from '../../shell/data.js';
import {bulkBlocker, listSource} from '../../support/bulk.js';
import {clearedFilters, filterSummary, mergeQuery} from '../../support/filters.js';
import {cappedCount, filterKey, isUuid, listEndpoint, listParams, newEntriesAction, pagerRequest, totalText} from '../../support/lists.js';
import {formatClock, formatCount, formatDate, formatDuration, formatHourMinute, formatLocal, isSameDisplayDay, nowSeconds, relativeTime, toSeconds, zoneSuffix} from '../../support/time.js';
import {awaitingText, codeText, fill, stateLabel, stateText} from '../../support/wording.js';

/** The stuck signals, in the order of the sub-tabs. */
export const STUCK_SIGNALS = Object.freeze(['reservation_expired', 'outcome_not_observed', 'repeated_releases', 'attempts_not_capped']);

const SIGNAL_LABELS = {
    reservation_expired: 'Reservation expired',
    outcome_not_observed: 'Outcome not observed',
    repeated_releases: 'Repeated releases',
    attempts_not_capped: 'Attempts not capped',
};

/** The signals that point at a job that may not run again by itself: a warning tint. */
const WARNING_SIGNALS = ['reservation_expired', 'outcome_not_observed'];

/** The noun of each tab's rows: [one, many]. */
const NOUNS = {
    pending: ['pending job', 'pending jobs'],
    running: ['running job', 'running jobs'],
    completed: ['completed job', 'completed jobs'],
    silenced: ['silenced job', 'silenced jobs'],
    failed: ['failed job', 'failed jobs'],
    retrying: ['job being retried', 'jobs being retried'],
    unknown: ['job with an unknown outcome', 'jobs with an unknown outcome'],
    stuck: ['stuck job', 'stuck jobs'],
};

/** The order column of each tab (4.3; stuck: the reservation of its rows). */
const TIME_COLUMNS = {
    pending: 'pushed_at',
    running: 'reserved_at',
    completed: 'finished_at',
    silenced: 'finished_at',
    unknown: 'finished_at',
    failed: 'failed_at',
    retrying: 'failed_at',
    stuck: 'reserved_at',
};

const TIME_LABELS = {pushed_at: 'Pushed at', reserved_at: 'Reserved at', finished_at: 'Finished at', failed_at: 'Failed at'};

/** The event of the order column, for "newest failure first". */
const ORDER_EVENTS = {pushed_at: 'push', reserved_at: 'reservation', finished_at: 'finish', failed_at: 'failure'};

const MISSING = '—';

function number(value) {
    return typeof value === 'number' && Number.isFinite(value) ? value : null;
}

function plural(count, one, many) {
    return count === 1 ? one : many;
}

/**
 * A time in words: the clock for a time of the current local day, else the
 * date and minute, so a time of another day never reads as today's.
 */
function moment(at, now) {
    // The day of the display time zone (support/time.js), never the browser's when a zone is configured.
    // Its own zone label when a daylight-saving change lies between it and the toolbar's "times in" note.
    const zone = zoneSuffix(at, now);

    if (isSameDisplayDay(at, now)) return `${formatClock(at)}${zone}`;

    return `${formatDate(at)} ${formatHourMinute(at)}${zone}`;
}

/** The signal sub-tab of a route query: a known signal, else the first one. */
export function signalFromQuery(query) {
    const value = query && typeof query === 'object' ? query.signal : null;

    return typeof value === 'string' && STUCK_SIGNALS.includes(value) ? value : STUCK_SIGNALS[0];
}

/**
 * The signal sub-tabs. Only the open one shows a count: its list total
 * (there is no count per signal); a total that is not loaded yet shows
 * nothing.
 */
export function signalTabs(active, total) {
    return STUCK_SIGNALS.map(key => ({
        key,
        label: SIGNAL_LABELS[key],
        count: key === active && total ? cappedCount(total) : null,
        active: key === active,
    }));
}

/** The columns of each tab; `time` is the tab's order column. */
export function columnsOf(status) {
    switch (status) {
        case 'failed':
            return ['job', 'exception', 'queue', 'attempts', 'runtime', 'time'];
        case 'pending':
            return ['job', 'queue', 'attempts', 'available', 'time'];
        case 'running':
            return ['job', 'queue', 'worker', 'lease', 'attempt', 'time'];
        case 'stuck':
            return ['job', 'queue', 'signals', 'rule', 'time'];
        case 'retrying':
            return ['job', 'operation', 'time'];
        default:
            return ['job', 'attempts', 'runtime', 'time'];
    }
}

/** The field of the tab's time column. */
export function timeColumn(status) {
    return TIME_COLUMNS[status] || 'finished_at';
}

/**
 * The header of the time column with the order arrow. The stuck list is in
 * its signal's read order, not in this column's order: no arrow, and its
 * pending rows show their push time (stuckTime()).
 */
export function timeHeader(status, displayOrder) {
    if (status === 'stuck') return 'Reserved or pushed at';

    const label = TIME_LABELS[timeColumn(status)];

    return `${label} ${displayOrder === 'asc' ? '▴' : '▾'}`;
}

/** The filter bar fields of a tab: the stuck endpoint takes only the queue and the class (4.4). */
export function filterFields(status) {
    return status === 'stuck' ? ['queue', 'class'] : ['q', 'queue', 'class', 'tag', 'range'];
}

/**
 * The request of a page: the endpoint and its query. `page` is {order,
 * cursor}. `newer_than` goes only with the first page in the
 * order desc of a status tab, where the server answers `has_newer`.
 */
export function requestFor(filters, page, {perPage = null, head = null, signal = null} = {}) {
    const order = page && page.order === 'asc' ? 'asc' : 'desc';
    const cursor = page && typeof page.cursor === 'string' ? page.cursor : null;
    const first = cursor === null && order === 'desc' && filters.status !== 'stuck';

    return {
        endpoint: listEndpoint(filters.status),
        params: listParams({...filters, order}, {
            cursor,
            newerThan: first && typeof head === 'string' && head !== '' ? head : null,
            perPage,
            signal: filters.status === 'stuck' ? signal : null,
        }),
    };
}

/** The tabs whose rows are a current state (a lease, a heartbeat, an availability): a poll always replaces them. */
const STATE_TABS = ['pending', 'running', 'retrying'];

/**
 * What a poll answer does: 'banner' keeps the rows on screen and shows "New
 * entries" (has_newer true, auto-load off); 'maybe' keeps them and shows
 * "There may be new entries" (the request sent `newer_than`, the server
 * could not tell: has_newer null, auto-load off); 'apply' shows the answer
 * (nothing new, no `newer_than` sent, auto-load on, or a state tab: its
 * kept rows would show a state that has passed, against the live clock).
 */
export function pollAction(response, autoLoad, {sentNewerThan = false, status = null} = {}) {
    if (STATE_TABS.includes(status)) return 'apply';

    if (newEntriesAction(response, autoLoad) === 'banner') return 'banner';

    const unknown = sentNewerThan && !!response && (response.has_newer === null || response.has_newer === undefined);

    return unknown && autoLoad !== true ? 'maybe' : 'apply';
}

/**
 * Whether the "Auto-load new" switch shows: only where it acts, on a list
 * of finished rows (not the stuck list, not a state tab, whose polls always
 * replace the page: pollAction()) read newest first.
 */
export function autoLoadApplies(status, order) {
    return status !== 'stuck' && !STATE_TABS.includes(status) && order === 'desc';
}

/** The order of a list: the stuck endpoint has one fixed order (its signal's read order, sent as desc). */
export function listOrder(filters) {
    return filters.status === 'stuck' ? 'desc' : (filters.order === 'asc' ? 'asc' : 'desc');
}

/**
 * The request of a pager button for a tab: the stuck list has no Newest or
 * Oldest (one order only) and pages by its cursors in that order; the other
 * tabs follow support/lists.js pagerRequest().
 */
export function pagerRequestFor(status, action, page) {
    if (status !== 'stuck') return pagerRequest(action, page);
    if (action !== 'newer' && action !== 'older') return null;

    return pagerRequest(action, page ? {...page, order: 'desc'} : page);
}

/**
 * The page and the head after an answer. Every first page (no `prev_cursor`,
 * also one answered to a `before` cursor) becomes the head of the list: its
 * cursor is dropped and its `head` (a string, else null) is kept, so the
 * poll sends `newer_than` again. A later page keeps its cursor and the head.
 */
export function pageAfter(page, data, head) {
    const order = page && page.order === 'asc' ? 'asc' : 'desc';
    const first = !data || typeof data.prev_cursor !== 'string';

    if (!first) return {page: {order, cursor: page && typeof page.cursor === 'string' ? page.cursor : null}, head};

    return {page: {order, cursor: null}, head: data && typeof data.head === 'string' ? data.head : null};
}

/**
 * Whether an answer is an empty page behind the end of the list: the request
 * carried a cursor, and the answer has no row and no `prev_cursor` (the rows
 * after the cursor left the list). Such a page is no first page and no
 * proof of an empty list, so the screen reads the first page again instead
 * of saying "No … are kept".
 */
export function pastTheEnd(page, data) {
    return !!page && typeof page.cursor === 'string'
        && !!data && Array.isArray(data.jobs) && data.jobs.length === 0
        && typeof data.prev_cursor !== 'string';
}

/**
 * What a `cursor_mismatch` answer does: 'reload' the first page without the
 * head when the request carried a cursor or a head of this screen (the list
 * changed under it), else 'error' (never a reload loop).
 */
export function mismatchAction(params) {
    const given = params && typeof params === 'object' ? params : {};

    return typeof given.cursor === 'string' || typeof given.newer_than === 'string' ? 'reload' : 'error';
}

/**
 * The total of the result bar and the bulk labels: the answer's own total
 * on a first page, and on a later page when it is exact (no residual
 * filter: the live total of the whole list); else the total of the list's
 * first page (a residual count of a later page starts at that page).
 */
export function shownTotal(response, firstTotal) {
    if (!response) return null;
    if (typeof response.prev_cursor !== 'string') return response.total || null;

    const total = response.total;

    // A later page without a residual filter counts the whole list: its total is the live one.
    if (total && typeof total.count === 'number' && total.at_least !== true) return total;

    return firstTotal || total || null;
}

/** The filters that the stuck filter bar shows: the stuck endpoint sends only the queue and the class. */
export function stuckBarFilters(filters) {
    return {...filters, tag: null, batch: null, from: null, to: null, q: null};
}

/**
 * The route query after "Clear filters" of an empty list: the filters that
 * the bar shows are cleared, as FilterBar's own "Clear filters" does; on the
 * stuck tab the filters its bar hides stay (mergeBarChange()), and every
 * other key of the screen (the signal, the order) stays.
 */
export function clearedFiltersQuery(query, filters) {
    const next = filters.status === 'stuck'
        ? mergeBarChange(filters, clearedFilters(stuckBarFilters(filters)))
        : clearedFilters(filters);

    return mergeQuery(query, next);
}

/**
 * The filters after a change of the stuck filter bar: its own fields and
 * the tab from the bar, every filter it does not show kept as it was (they
 * apply again on the other tabs).
 */
export function mergeBarChange(filters, next) {
    return {...filters, status: next.status, connection: next.connection, queue: next.queue, class: next.class};
}

/** The result bar title: the filtered total with the tab's noun. */
export function resultTitle(status, total) {
    const [one, many] = NOUNS[status] || ['job', 'jobs'];
    const count = total ? number(total.count) : null;

    if (count === null) return `${many.charAt(0).toUpperCase()}${many.slice(1)} · total unknown`;

    const text = totalText(total);

    return `${text.charAt(0).toUpperCase()}${text.slice(1)} ${total.at_least === true ? many : plural(count, one, many)}`;
}

/** "newest failure first", "oldest push first". */
function orderWords(status, order) {
    return `${order === 'asc' ? 'oldest' : 'newest'} ${ORDER_EVENTS[timeColumn(status)]} first`;
}

/** The filters of a tab in words: the stuck endpoint ignores the search, the tag, the batch and the time. */
function filterWords(filters, choice) {
    if (filters.status === 'stuck') {
        return filterSummary({...filters, tag: null, batch: null, from: null, to: null}, null);
    }

    const parts = filterSummary(filters, choice);

    if (typeof filters.q === 'string' && filters.q !== '') parts.unshift(`the search “${filters.q}”`);

    return parts;
}

/**
 * The text beside the result bar title: the active filters and the order.
 * The stuck tab names its signal and is in the signal's read order.
 */
export function resultDetail(filters, choice, signal = null) {
    const parts = filterWords(filters, choice);
    const match = parts.length ? `match ${parts.join(' · ')}` : null;

    if (filters.status === 'stuck') {
        return [SIGNAL_LABELS[signal] || SIGNAL_LABELS[STUCK_SIGNALS[0]], match].filter(Boolean).join(' · ');
    }

    return [match, orderWords(filters.status, filters.order)].filter(Boolean).join(' · ');
}

function decodeBase64Url(text) {
    const normal = text.replace(/-/g, '+').replace(/_/g, '/');
    const padded = normal + '='.repeat((4 - (normal.length % 4)) % 4);

    if (typeof atob === 'function') {
        const binary = atob(padded);
        const bytes = Uint8Array.from(binary, character => character.charCodeAt(0));

        return new TextDecoder().decode(bytes);
    }

    return Buffer.from(padded, 'base64').toString('utf8');
}

/**
 * "Searched back to <time>" ("Searched up to" in the order asc) from
 * `scanned_to` (the key of the last row that a limited search examined),
 * or null when the key holds no time (a stuck signal read by a count). The
 * cursor is opaque to every other rule: only this display line reads it,
 * and a cursor that does not decode shows nothing.
 */
export function scannedToText(scannedTo, {status = null, signal = null, order = 'desc'} = {}) {
    if (typeof scannedTo !== 'string' || scannedTo === '') return null;

    // The other stuck signals are read in the order of a count (gap, releases, attempts), not a time.
    if (status === 'stuck' && signal !== 'reservation_expired') return null;

    try {
        const cursor = JSON.parse(decodeBase64Url(scannedTo));
        const at = cursor && Array.isArray(cursor.k) ? toSeconds(cursor.k[0]) : null;

        if (at === null) return null;

        return `${order === 'asc' ? 'Searched up to' : 'Searched back to'} ${formatLocal(at)}`;
    } catch (error) {
        return null;
    }
}

/**
 * The "last verified" line of the pending and running tabs: one part per
 * database queue connection, its last complete reconciliation sweep or
 * "never checked". A connection-level time, never a check of a row. Null
 * without connections.
 */
export function lastVerifiedParts(lastVerified, now = nowSeconds()) {
    if (!lastVerified || typeof lastVerified !== 'object' || Array.isArray(lastVerified)) return null;

    const parts = Object.entries(lastVerified).map(([connection, value]) => {
        const at = toSeconds(value);

        return {connection, text: at === null ? 'never checked' : `at ${moment(at, now)}`};
    });

    return parts.length ? parts : null;
}

/** When a pending job can run: "now", "delayed until 10:00:00", or "—". */
export function availableText(availableAt, now) {
    const at = toSeconds(availableAt);

    if (at === null) return MISSING;

    return at > now ? `delayed until ${moment(at, now)}` : 'now';
}

/** A runtime: "412 ms", "30.0 s", "2 min 5 s"; null is unknown ("—"), never 0. */
export function runtimeText(ms) {
    const value = number(ms);

    if (value === null || value < 0) return MISSING;
    if (value < 1000) return `${Math.round(value)} ms`;
    if (value < 60000) return `${(value / 1000).toFixed(1)} s`;

    return formatDuration(value / 1000);
}

/** "1 attempt", "3 attempts". */
export function attemptsText(attempts) {
    const value = number(attempts);

    return value === null ? 'attempts unknown' : `${formatCount(value)} ${plural(value, 'attempt', 'attempts')}`;
}

/** "retried once", "retried 4 times (inferred)", or null for a job never retried. */
export function retriedText(row) {
    const count = row ? number(row.retried_count) : null;

    if (count === null || count < 1) return null;

    const text = count === 1 ? 'retried once' : `retried ${formatCount(count)} times`;

    return row.retried_count_inferred === true ? `${text} (inferred)` : text;
}

/** At most `limit` tag chips, a "+N" for the rest, and every tag in the title. */
export function tagChips(tags, truncated, limit = 2) {
    const list = Array.isArray(tags) ? tags.filter(tag => typeof tag === 'string') : [];
    const rest = list.length - Math.min(limit, list.length);
    let more = rest > 0 ? `+${rest}` : null;

    if (truncated === true) more = more === null ? 'more not kept' : `${more} and more`;

    const title = list.join(', ') + (truncated === true ? `${list.length ? ', ' : ''}and more tags that were not kept` : '');

    return {shown: list.slice(0, limit), more, title};
}

/**
 * The worker of a running row: its id ("not identified"
 * without one) and its liveness in words. Live and silent are facts with a
 * heartbeat; unknown is never shown as live or idle.
 */
export function workerCell(row, now) {
    const worker = row ? row.worker : null;

    if (!worker || typeof worker !== 'object') return {id: MISSING, tone: 'unknown', text: null};

    const id = typeof worker.id === 'string' && worker.id !== '' ? worker.id : 'not identified';
    const heartbeat = toSeconds(worker.heartbeat_at);

    if (worker.liveness === 'live') {
        return {id, tone: 'live', text: heartbeat === null ? '● live' : `● live · heartbeat ${relativeTime(heartbeat, now)}`};
    }

    if (worker.liveness === 'silent') {
        return {id, tone: 'silent', text: heartbeat === null ? '● silent' : `● silent · heartbeat ${moment(heartbeat, now)}`};
    }

    return {id, tone: 'unknown', text: 'liveness unknown'};
}

/**
 * The lease of a running row (P-1): "expires in …" with its end, or "Lease
 * expired … ago" with the worker's silence when it is silent. Unknown is
 * "—".
 */
export function leaseCell(row, now) {
    const lease = toSeconds(row ? row.lease_expires_at : null);

    if (lease === null) return {expired: false, text: MISSING, detail: null};

    if (lease > now) return {expired: false, text: `expires in ${formatDuration(lease - now)}`, detail: `until ${moment(lease, now)}`};

    const worker = row && row.worker ? row.worker : null;
    const silent = worker && worker.liveness === 'silent' ? toSeconds(worker.silent_since) : null;

    return {
        expired: true,
        text: `Lease expired ${relativeTime(lease, now)}`,
        detail: silent === null ? null : `Worker silent since ${moment(silent, now)}`,
    };
}

const ACTIONS = {retry: 'Retry', forget: 'Forget'};

/**
 * The operation that holds a retrying row (P-4): a chip label, its tone,
 * the operator sentence of its awaiting reason or state, and who started
 * it when. The actor is text.
 */
export function operationCell(operation, now = nowSeconds()) {
    if (!operation || typeof operation !== 'object') {
        return {label: 'Operation unknown', tone: 'neutral', text: 'The operation that holds this job could not be read.', started: null};
    }

    const action = ACTIONS[operation.action] || 'Operation';
    const awaiting = typeof operation.awaiting_reason === 'string' && operation.awaiting_reason !== '';
    let label;

    if (awaiting) label = `${action} · awaiting operator`;
    else label = `${action} · ${stateLabel(operation.state)}`;

    let text = null;

    if (awaiting) text = awaitingText(operation.awaiting_reason, {id: operation.id});
    else if (typeof operation.state === 'string' && operation.state !== '') text = stateText(operation.state);

    const at = toSeconds(operation.created_at);
    const actor = typeof operation.actor === 'string' && operation.actor !== '' ? operation.actor : null;

    return {
        label,
        tone: awaiting ? 'warning' : 'accent',
        text,
        started: `Started ${at === null ? 'at an unknown time' : moment(at, now)} by ${actor === null ? 'an unknown actor' : actor}`,
    };
}

/** The chip of a signal: its label and tone. */
export function signalChip(signal) {
    return {label: SIGNAL_LABELS[signal] || String(signal), tone: WARNING_SIGNALS.includes(signal) ? 'warning' : 'neutral'};
}

/**
 * The fact sentence of a signal (without the swap-off text):
 * evidence only, never a crash, a process death or a pickup.
 */
export function signalText(signal, facts, row, now = nowSeconds()) {
    const given = facts && typeof facts === 'object' ? facts : {};

    switch (signal) {
        case 'reservation_expired': {
            const reserved = toSeconds(row ? row.reserved_at : null);
            const end = toSeconds(given.lease_expires_at);
            const retryAfter = number(given.retry_after);

            return fill('Reserved at {reserved}; the {lease} lease ended at {end}.', {
                reserved: reserved === null ? null : moment(reserved, now),
                lease: retryAfter === null ? null : `${formatCount(retryAfter)} s`,
                end: end === null ? null : moment(end, now),
            });
        }
        case 'outcome_not_observed': {
            const gap = number(given.gap);

            return gap === null
                ? 'Attempts ended without an observed outcome.'
                : `${formatCount(gap)} ${plural(gap, 'attempt', 'attempts')} ended without an observed outcome (gap ${formatCount(gap)}).`;
        }
        case 'repeated_releases': {
            const releases = number(given.releases);

            return fill('Released {releases} times in this cycle.', {releases: releases === null ? null : formatCount(releases)});
        }
        case 'attempts_not_capped': {
            const attempts = number(given.attempts);
            const count = attempts === null ? MISSING : `${formatCount(attempts)} ${plural(attempts, 'attempt', 'attempts')}`;

            if (given.rule === 'deadline') {
                const deadline = toSeconds(given.deadline);

                return `${count}; no attempt limit applies before its retryUntil deadline ${deadline === null ? MISSING : moment(deadline, now)}.`;
            }

            return `${count}, and no attempt limit applies (tries 0).`;
        }
        default:
            return 'This signal holds for the job.';
    }
}

const TRIES_SOURCES = {payload: 'from the payload', worker: "from the worker's --tries"};

/** The enforced rule of a stuck row (4.4) in words. */
export function enforcedRuleText(rule, now = nowSeconds()) {
    if (!rule || typeof rule !== 'object') return MISSING;

    const deadline = toSeconds(rule.deadline);
    const tries = number(rule.tries);

    switch (rule.kind) {
        case 'deadline':
            return `Runs until ${deadline === null ? MISSING : moment(deadline, now)}, then fails (retryUntil)`;
        case 'deadline_passed':
            return `Its retryUntil deadline ${deadline === null ? MISSING : moment(deadline, now)} has passed: it fails at its next attempt`;
        case 'attempt_limit': {
            const source = TRIES_SOURCES[rule.tries_source];

            return `Fails after ${tries === null ? MISSING : attemptsText(tries)}${source ? ` (${source})` : ''}`;
        }
        case 'none':
            return 'No attempt limit (tries 0)';
        default:
            return 'Attempt limit unknown (no payload or worker value)';
    }
}

/**
 * A failed list read: `index_missing` (the stuck signals that need a
 * deferred index; the hint is the response's own), `reset` (a cursor of
 * another list: load the first page), or `error` with the operator text.
 */
export function listFailure(error) {
    const response = error && error.response ? error.response : null;
    const body = response && response.data && typeof response.data === 'object' ? response.data : {};
    const code = typeof body.error === 'string' && body.error !== '' ? body.error : null;

    if (code === 'index_missing') {
        return {
            kind: 'index_missing',
            title: 'This list needs an index that is not built yet',
            text: 'Reservation expired still works; the other signals wait for the index.',
            hint: typeof body.hint === 'string' && body.hint !== '' ? body.hint : null,
        };
    }

    if (code === 'cursor_mismatch') return {kind: 'reset', title: null, text: null, hint: null};

    // The server refused a filter of the link (ListRequest): name it, never as a generic error.
    if (response && response.status === 422 && code === 'invalid' && FILTER_FIELDS.includes(body.field)) {
        return filterFailure({code: 'filter_invalid', field: body.field, values: {field: body.field}});
    }

    let text;

    if (isShapeError(error)) text = UNREADABLE_ANSWER;
    else if (!response) text = 'The server did not answer. Try again.';
    else if (code !== null) text = codeText(code);
    else text = `The server answered with an error (HTTP ${response.status}). Try again.`;

    return {kind: 'error', title: 'The jobs list could not be loaded', text, hint: null};
}

/** The filters of a list request that a 422 can name (src/Http/Lists/ListRequest.php). */
const FILTER_FIELDS = ['connection', 'queue', 'class', 'tag', 'batch', 'from', 'to'];

/**
 * The state of a list whose link filter cannot be applied (a queryProblem()
 * or the server's 422 on a filter): the list is not shown, never the
 * unfiltered list in its place, and the screen offers to clear the filters.
 */
export function filterFailure(problem) {
    return {
        kind: 'filter',
        title: 'This link has a filter that cannot be applied',
        text: codeText(problem.code, problem.values || {}),
        hint: null,
    };
}

const hasCursor = (response, key) => !!response && typeof response[key] === 'string' && response[key] !== '';

/**
 * Whether the pager shows: with rows, or on an empty page that has a cursor
 * (a limited search answers an empty page with a `next_cursor`: the pager is
 * the only way on).
 */
export function pagerShown(response, rowCount) {
    if (!response) return false;

    return rowCount > 0 || hasCursor(response, 'prev_cursor') || hasCursor(response, 'next_cursor');
}

/**
 * The empty state: no rows kept, or no rows that match the filters (with the
 * filters in words). With the page's `response`, an empty page of a limited
 * search (`search_limited`) or a page with a cursor is `partial`: the list
 * may hold matches beyond it, so it never says "none".
 */
export function emptyState(filters, choice, signal = null, response = null) {
    const parts = filterWords(filters, choice);
    const [, many] = NOUNS[filters.status] || ['job', 'jobs'];
    const noun = filters.status === 'stuck' ? 'stuck jobs' : many;

    if (response && response.search_limited === true) {
        const on = hasCursor(response, 'next_cursor') ? [`use ${response.order === 'asc' ? 'Newer' : 'Older'} to search on`] : [];

        return {filtered: parts.length > 0, partial: true, title: 'No match in the rows searched so far', text: [...parts, ...on].join(' · ')};
    }

    if (hasCursor(response, 'prev_cursor') || hasCursor(response, 'next_cursor')) {
        return {filtered: parts.length > 0, partial: true, title: `No ${noun} on this page`, text: parts.length ? parts.join(' · ') : 'Use the pager to read the other pages.'};
    }

    if (parts.length) {
        return {filtered: true, partial: false, title: `No ${noun} match these filters`, text: parts.join(' · ')};
    }

    if (filters.status === 'stuck') {
        return {filtered: false, partial: false, title: 'No job shows this signal now', text: `${SIGNAL_LABELS[signal] || SIGNAL_LABELS[STUCK_SIGNALS[0]]}: no pending or running job matches.`};
    }

    return {filtered: false, partial: false, title: `No ${many} are kept`, text: 'None are in this list right now.'};
}

/**
 * The bulk actions of the Failed tab (4.9, 5.1): shown only with the operate
 * ability and a source that a run can freeze. A search text blocks them
 * (the server's list source has no search: the run would act on more jobs
 * than the list shows), and so does a batch filter (a batch is its own
 * source), a list known to be empty, and a read-only failed-record driver
 * (support/failed-records.js; its message shows once). The labels carry the
 * list total.
 */
export function bulkState(status, canOperate, filters, total, {readOnly = null, filterProblem = null} = {}) {
    const hidden = {visible: false, blocker: null, readOnlyText: null, filterText: null, retryLabel: null, forgetLabel: null, menuLabel: null};

    if (status !== 'failed') return hidden;

    // A refused link filter: the run would act on more jobs than the link asked for (queryProblem(), or
    // the list's 422 state). Its text answers "Start a new run…"; the list's own notice already shows it.
    if (filterProblem) {
        const filterText = typeof filterProblem.code === 'string' ? codeText(filterProblem.code, filterProblem.values || {}) : filterProblem.text || null;

        return {...hidden, filterText};
    }

    // A read-only failed-record driver: no Retry or Forget, and its message once.
    const readOnlyText = readOnly && typeof readOnly.message === 'string' && readOnly.message !== '' ? readOnly.message : null;

    if (canOperate !== true || readOnly) return {...hidden, readOnlyText};

    let blocker = null;

    try {
        blocker = bulkBlocker('retry', listSource(filters));
    } catch (error) {
        blocker = {code: error.code || null, text: error.message};
    }

    if (blocker !== null) return {...hidden, blocker: blocker.code === 'search_not_in_bulk' ? blocker.text : null};

    const known = total ? number(total.count) : null;

    // A list known to be empty offers nothing to act on.
    if (known === 0 && total.at_least !== true) return hidden;

    const count = known !== null ? cappedCount(total) : null;

    return {
        visible: true,
        blocker: null,
        readOnlyText: null,
        filterText: null,
        retryLabel: count === null ? 'Retry all…' : `Retry all ${count}…`,
        forgetLabel: count === null ? 'Forget all…' : `Forget all ${count}…`,
        menuLabel: count === null ? 'Act on all' : `Act on all ${count}`,
    };
}

const RESTART_FIELDS = ['connection', 'queue', 'class', 'tag'];

/** The note of a recovered run whose source this list cannot take. */
export const RESTART_OTHER_NOTE = 'That run acted on failure groups or a batch: start it again from the Failure groups or the batch page.';

/** The note after "Start a new run…" applied the recovered run's filters. */
export const RESTART_APPLIED_NOTE = 'The filters of that run are applied. Check the list, then use Retry all or Forget all.';

/**
 * What "Start a new run…" of a recovered run does on the Jobs list (`source`
 * from `GET /api/bulk/{ref}`): { open, query, note }. It opens the sheet
 * (`open`) only when the Failed tab shows exactly that run's list source (no
 * search, no batch, the same connection, queue, class, tag and window), so
 * the sheet never freezes the tab's own filters in place of the run's.
 * Otherwise `query` is the route query of the run's filters, to apply first,
 * with a note; a source of another kind, or one that this list cannot read,
 * gives only a note.
 */
export function restartPlan(source, filters) {
    const other = {open: false, query: null, note: RESTART_OTHER_NOTE};

    if (!source || typeof source !== 'object' || source.kind !== 'list') return other;

    const value = key => (source[key] === undefined || source[key] === '' ? null : source[key]);

    if (RESTART_FIELDS.some(key => value(key) !== null && typeof value(key) !== 'string')
        || ['from', 'to'].some(key => value(key) !== null && !Number.isFinite(value(key)))) {
        return other;
    }

    const current = filters && typeof filters === 'object' ? filters : {};
    const empty = key => current[key] === null || current[key] === undefined || current[key] === '';
    const same = current.status === 'failed' && empty('q') && empty('batch')
        && [...RESTART_FIELDS, 'from', 'to'].every(key => (empty(key) ? null : current[key]) === value(key));

    if (same) return {open: true, query: null, note: null};

    const query = {status: 'failed'};

    RESTART_FIELDS.forEach(key => {
        if (value(key) !== null) query[key] = value(key);
    });

    ['from', 'to'].forEach(key => {
        if (value(key) !== null) query[key] = String(value(key));
    });

    return {open: false, query, note: RESTART_APPLIED_NOTE};
}

/** The `selectionKey` of the bulk sheet: it changes with every change of the filters or the action. */
export function bulkSelectionKey(filters, action) {
    return `${action}:${filterKey(filters)}`;
}

/**
 * The time cell of a row: the clock for a time of the current local day,
 * else the date and minute; the relative time below; the full time with
 * its zone as the title. Unknown is "—".
 */
export function timeCell(value, now) {
    const at = toSeconds(value);

    if (at === null) return {primary: MISSING, relative: null, title: ''};

    return {
        primary: moment(at, now),
        relative: relativeTime(at, now),
        title: formatLocal(at),
    };
}

/**
 * The time cell of a stuck row: its reservation, or for a pending row (also
 * a released one, which keeps its last reservation) its push, named as such.
 */
export function stuckTime(row, now) {
    if (row && row.status === 'pending') {
        const cell = timeCell(row.pushed_at, now);

        return {...cell, primary: `pushed ${cell.primary}`, title: `Pushed ${cell.title}`};
    }

    return timeCell(row ? row.reserved_at : null, now);
}

/** The detail route of a row (/jobs/:uuid), or null when its id is not a uuid. */
export function jobLink(row) {
    return row && isUuid(row.id) ? `/jobs/${encodeURIComponent(row.id)}` : null;
}

/** The first 8 characters of a uuid. */
export function shortId(id) {
    return typeof id === 'string' && id !== '' ? id.slice(0, 8) : MISSING;
}

/** The class name without its namespace. */
export function shortName(name) {
    if (typeof name !== 'string' || name === '') return MISSING;

    const parts = name.split('\\');

    return parts[parts.length - 1];
}

const MARKERS = /(\[redacted\]|\[redaction failed\])/;

/** A message split into text and redaction markers, so a marker keeps its own style (rendered as text). */
export function markedText(text) {
    if (typeof text !== 'string' || text === '') return [];

    return text.split(MARKERS).filter(part => part !== '').map(part => ({text: part, marker: MARKERS.test(part)}));
}

/** The third line of a row below 768 px: queue · attempts · runtime (where the tab has it) · first tag. */
export function compactLine(row, status) {
    const parts = [row.queue, attemptsText(row.attempts)];

    if (columnsOf(status).includes('runtime')) {
        const runtime = runtimeText(row.runtime_ms);

        parts.push(runtime === MISSING ? 'runtime —' : runtime);
    }

    if (Array.isArray(row.tags) && row.tags.length) parts.push(row.tags[0]);

    return parts.join(' · ');
}
