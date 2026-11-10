/**
 * The rules of the Batches screen.
 * Pure helpers, so Node can test them (tests/js/batches.test.js);
 * BatchesScreen.vue only renders what they decide.
 *
 * The rules of the SPA hold: every time goes through toSeconds() and is
 * shown in local time with a zone label; an unknown value is "—", never 0;
 * a capped count says "at least"; every code is worded through
 * support/wording.js; every text is a template filled with stored data and
 * rendered as text.
 *
 * The batch's member lists are the Jobs list with `?batch=<id>` (one link
 * per member tab); this screen does not repeat the list. The retry of the
 * failed members is the shared BulkRun with a batch source.
 */

import {UNREADABLE_ANSWER, isShapeError} from '../../shell/data.js';
import {totalText} from '../../support/lists.js';
import {formatClock, formatCount, formatHourMinute, formatLocal, nowSeconds, timeZoneLabel, toSeconds, zoneSuffix, zonedParts} from '../../support/time.js';
import {codeText} from '../../support/wording.js';

/** The server's page size of `GET /api/batches` (BatchesController, 50). */
export const PAGE_SIZE = 50;

/** The longest search text and `before_id` that the server accepts (BatchesController::PARAMETER_CHARACTERS). */
export const QUERY_MAX = 255;

/** The search waits this long after the last keystroke. */
export const SEARCH_DEBOUNCE_MS = 300;

/** The member tabs of `GET /api/batches/{id}` (BatchesController::MEMBER_TABS), in this order. */
export const MEMBER_TABS = Object.freeze(['pending', 'running', 'completed', 'silenced', 'failed', 'retrying', 'unknown']);

const MEMBER_LABELS = {
    pending: 'Pending',
    running: 'Running',
    completed: 'Completed',
    silenced: 'Silenced',
    failed: 'Failed',
    retrying: 'Retrying',
    unknown: 'Unknown',
};

/** The batch callbacks of Laravel's PendingBatch (JobProjection::BATCH_CALLBACKS), in the order shown. */
const CALLBACKS = ['before', 'progress', 'then', 'catch', 'finally', 'failure'];

/** The options shown on their own (the rest are named as other options). */
const KNOWN_OPTIONS = ['connection', 'queue', 'allowFailures', ...CALLBACKS];

const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

/** The text a batch store that cannot be read (`unknown_reason`) shows (D18 and unsupported repositories). */
const UNKNOWN_CODES = {
    sqlite_reads_not_allowed: 'batches_sqlite_reads_not_allowed',
    unsupported_repository: 'unsupported_repository',
};

function count(value) {
    return typeof value === 'number' && Number.isInteger(value) && value >= 0 ? value : null;
}

function text(value) {
    return typeof value === 'string' && value !== '' ? value : null;
}

function percent(part, whole) {
    return Math.max(0, Math.min(100, (part / whole) * 100));
}

function sameDay(a, b) {
    return a.year === b.year && a.month === b.month && a.day === b.day;
}

/**
 * The state of a batch: `cancelled` when it has `cancelled_at`, else
 * `finished` with `finished_at`, else `failures` when its pending jobs do
 * not pass its failures, else `running`. Unknown counts read as running.
 * `failures` is "open with failures", never "ran": Laravel keeps a failed
 * job pending and sets `finished_at` only when no job is pending, so the
 * batch waits for a retry, but it also keeps counting a failure after its
 * retry succeeded, so some pending jobs may still run.
 */
export function batchState(batch) {
    if (batch && toSeconds(batch.cancelled_at) !== null) return 'cancelled';
    if (batch && toSeconds(batch.finished_at) !== null) return 'finished';

    const pending = count(batch && batch.pending_jobs);
    const failed = count(batch && batch.failed_jobs);

    if (pending !== null && failed !== null && failed > 0 && pending <= failed) return 'failures';

    return 'running';
}

/** The chip of a state: its label and tone. */
export function stateChip(state) {
    if (state === 'cancelled') return {label: 'Cancelled', tone: 'neutral'};
    if (state === 'finished') return {label: 'Finished', tone: 'success'};
    if (state === 'failures') return {label: 'Open with failures', tone: 'warning'};

    return {label: 'Running', tone: 'accent'};
}

/** The word of a state in a list row. */
const STATE_WORDS = {running: 'running', finished: 'finished', cancelled: 'cancelled', failures: 'open with failures'};

/** A long id shortened to its first and last four characters ("9a3f…e1c0"); a short one as it is. */
export function shortBatchId(id) {
    const chars = [...String(id === null || id === undefined ? '' : id)];

    return chars.length > 12 ? `${chars.slice(0, 4).join('')}…${chars.slice(-4).join('')}` : chars.join('');
}

/** The title of a batch: its name, or "Unnamed batch · <short id>". */
export function batchTitle(batch) {
    const name = batch ? text(batch.name) : null;

    if (name !== null) return {text: name, unnamed: false};

    return {text: `Unnamed batch · ${shortBatchId(batch ? batch.id : '')}`, unnamed: true};
}

/**
 * A time of a list row: "09:00" today, "Yesterday 23:10", else "8 Nov
 * 23:10"; "—" when unknown.
 */
export function shortTime(epoch, now = nowSeconds()) {
    const at = toSeconds(epoch);

    if (at === null) return '—';

    // The days of the display time zone (support/time.js zonedParts()).
    const date = zonedParts(at);
    const today = zonedParts(now);
    const yesterday = zonedParts(now - 86400);

    // Its own zone label when a daylight-saving change lies between it and the toolbar's "times in" note.
    const time = `${formatHourMinute(at)}${zoneSuffix(at, now)}`;

    if (sameDay(date, today)) return time;
    if (sameDay(date, yesterday)) return `Yesterday ${time}`;

    return `${date.day} ${MONTHS[date.month - 1]} ${time}`;
}

/**
 * The second line of a list row:
 * the short id with the full id as a title, and "created 09:00"
 * when the row's time is not already the creation (finished, cancelled;
 * open with failures shows the creation itself).
 */
export function rowMeta(batch, now = nowSeconds()) {
    const id = String(batch && batch.id !== undefined && batch.id !== null ? batch.id : '');
    const state = batchState(batch);

    return {
        id: shortBatchId(id),
        title: id,
        created: state === 'finished' || state === 'cancelled' ? `created ${shortTime(batch.created_at, now)}` : null,
    };
}

/** The time of a list row: the state and its moment ("running · 09:00"), with the full local time as a title. */
export function rowTime(batch, now = nowSeconds()) {
    const state = batchState(batch);
    const at = state === 'cancelled' ? batch.cancelled_at : state === 'finished' ? batch.finished_at : batch && batch.created_at;

    return {text: `${STATE_WORDS[state]} · ${shortTime(at, now)}`, title: formatLocal(at)};
}

/**
 * The parts of a row's count line: "412 of 540 processed", "23
 * failed" (danger when above 0), "128 pending" while it runs, "cancelled"
 * for a cancelled batch. A count that is not a number shows as "—".
 */
export function countParts(batch) {
    const total = count(batch && batch.total_jobs);
    const processed = count(batch && batch.processed_jobs);
    const failed = count(batch && batch.failed_jobs);
    const pending = count(batch && batch.pending_jobs);
    const state = batchState(batch);
    const value = number => (number === null ? '—' : formatCount(number));
    const parts = [
        {text: `${value(processed)} of ${value(total)} processed`, danger: false},
        {text: `${value(failed)} failed`, danger: failed !== null && failed > 0},
    ];

    if (state === 'running' && (pending === null || pending > 0)) parts.push({text: `${value(pending)} pending`, danger: false});
    if (state === 'cancelled') parts.push({text: 'cancelled', danger: false});

    return parts;
}

/**
 * The progress bar: the processed share in the tone of the state (accent
 * while it runs or is open with failures, success when finished, grey when
 * cancelled) and the failed
 * share in danger, each in percent of `total_jobs`. Laravel counts a failed
 * job as still pending, so the two shares never overlap; their sum is held
 * at 100. Null when the counts are unknown or the batch has no jobs.
 */
export function progressBar(batch) {
    const total = count(batch && batch.total_jobs);
    const processed = count(batch && batch.processed_jobs);
    const failed = count(batch && batch.failed_jobs);

    if (total === null || total === 0 || processed === null) return null;

    const state = batchState(batch);
    const done = percent(processed, total);
    const failedShare = failed === null ? 0 : Math.min(percent(failed, total), 100 - done);
    const tone = state === 'finished' ? 'success' : state === 'cancelled' ? 'paused' : 'accent';

    return [{tone, percent: done}, {tone: 'danger', percent: failedShare}].filter(segment => segment.percent > 0);
}

/** The progress figure: "76%", or "—" when `progress` is not known. */
export function progressText(batch) {
    const value = count(batch && batch.progress);

    return value === null ? '—' : `${value}%`;
}

/** A count of Laravel's batch columns as text: "1,200", or "—". */
export function countText(value) {
    const known = count(value);

    return known === null ? '—' : formatCount(known);
}

/**
 * The second line of the detail header: the creation time, then the end
 * ("finished …", "cancelled …", "not finished"), each with its zone label.
 */
export function headerTimes(batch) {
    const parts = [`created ${formatLocal(batch && batch.created_at)}`];
    const state = batchState(batch);

    if (state === 'cancelled') parts.push(`cancelled ${formatLocal(batch.cancelled_at)}`);
    if (toSeconds(batch && batch.finished_at) !== null) parts.push(`finished ${formatLocal(batch.finished_at)}`);
    else if (state === 'running') parts.push('not finished');
    else if (state === 'failures') parts.push(failuresSentence(count(batch.failed_jobs)));

    return parts.join(' · ');
}

/**
 * Why a batch with failures is still open (Laravel keeps a failed job
 * pending). Laravel's `failed_jobs` counts every failure, also two of one
 * job and one whose retry succeeded since, so the sentence counts failures,
 * not jobs, and never says that every pending job failed.
 */
function failuresSentence(failed) {
    const failures = failed === 1 ? '1 failure' : `${formatCount(failed)} failures`;

    return `not finished: ${failures}. A failed job stays pending until a retry succeeds; other jobs may still run.`;
}

/**
 * The shapes of the parser's markers (JobProjection, SerializedValueParser,
 * ProjectionLimit): a node that is not plain data, so the options cannot be
 * shown (`{array}` is an array node that JobProjection::plain() could not
 * make plain). Options are a marker only when their keys are exactly one shape:
 * an option named `class` or `omitted` beside plain options is an option.
 */
const MARKER_SHAPES = [
    ['array'],
    ['unavailable'],
    ['limit', 'unavailable'],
    ['encrypted'],
    ['omitted'],
    ['bytes', 'omitted'],
    ['class', 'properties'],
    ['bytes', 'class', 'custom'],
].map(shape => shape.join(','));

function isMarker(options) {
    return MARKER_SHAPES.includes(Object.keys(options).sort().join(','));
}

/**
 * The batch options as the screen shows them (projected by
 * the server, every callback `{closure: true}`, the rest redacted):
 * `connection`, `queue` and `allowFailures` as text ("—" when absent), the
 * callbacks that are set (their code is never shown), and the names of the
 * other options. Options over the server's size limit say so; options that
 * are not an object give `unreadable`.
 */
export function optionsView(options) {
    const empty = {connection: '—', queue: '—', allowFailures: '—', callbacks: [], others: [], omitted: null, unreadable: false};

    if (options === null || options === undefined) return empty;

    // An empty PHP array encodes as [] (no options at all): Laravel's default, so failures are not allowed.
    if (Array.isArray(options) && options.length === 0) return {...empty, allowFailures: 'no'};

    if (typeof options !== 'object' || Array.isArray(options)) return {...empty, unreadable: true};

    if (isMarker(options)) {
        if (options.omitted !== 'too_large') return {...empty, unreadable: true};

        const bytes = count(options.bytes);

        return {...empty, omitted: `Options not shown: ${bytes === null ? 'more than 64 KB' : `${formatCount(bytes)} bytes`}, over the size that Orrery reads.`};
    }

    const plain = value => (typeof value === 'string' && value !== '' ? value : typeof value === 'number' ? String(value) : '—');
    // Laravel's PendingBatch sets allowFailures only when it is asked to: absent reads "no".
    const allow = options.allowFailures === true ? 'yes' : options.allowFailures === false || options.allowFailures === undefined ? 'no' : '—';
    const callbacks = CALLBACKS.filter(key => Array.isArray(options[key]) ? options[key].length > 0 : options[key] !== undefined && options[key] !== null);
    const others = Object.keys(options).filter(key => !KNOWN_OPTIONS.includes(key));

    return {connection: plain(options.connection), queue: plain(options.queue), allowFailures: allow, callbacks, others, omitted: null, unreadable: false};
}

/**
 * The pager of the batch list: under the rows, and under an empty or other
 * state of a later page (`cursors` holds the pages before it), so the way
 * back to newer batches stays; never over the first load.
 */
export function pagerShown(listKind, cursorCount) {
    return listKind === 'rows' || (listKind !== 'loading' && cursorCount > 0);
}

/**
 * The batch's content (the member tabs): shown for a chosen, known batch,
 * also while it is stale after a failed read that is not a 404; never after
 * a failed first read (`failure`).
 */
export function contentShown({batchId, batch, failure}) {
    return batchId !== null && batchId !== undefined && batch !== null && batch !== undefined && !failure;
}

/**
 * The member tabs of the detail: one per mirror status,
 * each with its capped count ("at least" when capped, "—" when unknown) and
 * the link to the Jobs list of that status filtered by the batch. A failed
 * count above 0 is in danger.
 */
export function memberTabs(members, batchId) {
    const source = members && typeof members === 'object' ? members : {};

    return MEMBER_TABS.map(status => {
        const total = source[status] && typeof source[status] === 'object' ? source[status] : null;
        const known = total ? count(total.count) : null;

        return {
            key: status,
            label: MEMBER_LABELS[status],
            count: totalText(total),
            unknown: known === null,
            danger: status === 'failed' && known !== null && known > 0,
            to: {path: '/jobs', query: {status, batch: String(batchId)}},
        };
    });
}

/**
 * The retry of the failed members: shown with the operate
 * ability, unless the failed-record driver is read-only (its message
 * instead) or the failed member count is known to be 0. An unknown count
 * keeps it: the freeze counts the members itself.
 */
export function retryState(canOperate, members, {readOnly = null} = {}) {
    const readOnlyText = readOnly && typeof readOnly.message === 'string' && readOnly.message !== '' ? readOnly.message : null;

    if (canOperate !== true || readOnly) return {visible: false, readOnlyText: canOperate === true ? readOnlyText : null};

    const failed = members && members.failed && typeof members.failed === 'object' ? members.failed : null;
    const known = failed ? count(failed.count) : null;

    if (known === 0 && failed.at_least !== true) return {visible: false, readOnlyText: null};

    return {visible: true, readOnlyText: null};
}

/**
 * Whether the BulkRun of the detail is mounted (host rules 3 and "keep it
 * mounted while a run is shown"): with the operate ability and a batch in
 * the route, while the batch is known, and also while a run is running or
 * shown when the batch read failed or the batch is gone, so a failed read
 * never stops a run.
 */
export function bulkPanelMounted({canOperate, batchId, batchKnown, running, shown}) {
    if (canOperate !== true || batchId === null || batchId === undefined) return false;

    return batchKnown === true || running === true || shown === true;
}

/**
 * What a route change does while this screen is open: 'ask' before a
 * change of batch (or leaving) would stop a running bulk run, else
 * 'allow'. A confirmed leave is allowed once.
 */
export function leaveDecision({sameBatch, running, confirmed}) {
    return sameBatch || !running || confirmed ? 'allow' : 'ask';
}

/**
 * The reads of the 10 s poll: the list unless a user read of it runs; the
 * detail when a batch is open, no user read of it runs and it is not gone
 * (a 404 stops its polls).
 */
export function pollReads({listLoading, batchId, detailLoading, detailGone}) {
    return {
        list: !listLoading,
        detail: batchId !== null && batchId !== undefined && !detailLoading && !detailGone,
    };
}

/** The Stale banner of the list ('batches') or the detail ('batch'), with the time of the last data and its zone. */
export function staleText(kind, at) {
    const time = toSeconds(at) === null ? '—' : `${formatClock(at)} ${timeZoneLabel(toSeconds(at))}`;

    return kind === 'batch'
        ? `Stale: the last refresh failed. Showing this batch as of ${time}.`
        : `Stale: the last refresh failed. Showing batches from ${time}.`;
}

/**
 * The summary line of the detail below 768 px: the failed and
 * pending parts of countParts(), the queue (with its connection only when
 * that is known) and "allows failures".
 */
export function narrowParts(batch, options) {
    const parts = countParts(batch).slice(1).filter(part => part.text !== 'cancelled');
    const view = options || {connection: '—', queue: '—', allowFailures: '—'};

    if (view.queue !== '—') parts.push({text: view.connection !== '—' ? `${view.connection}:${view.queue}` : view.queue, danger: false});
    if (view.allowFailures === 'yes') parts.push({text: 'allows failures', danger: false});

    return parts;
}

/** The source of the batch's bulk run. */
export function batchSource(batchId) {
    return {kind: 'batch', batch_id: String(batchId)};
}

/**
 * The localStorage key of the bulk run of one batch (BulkRun host rule 2):
 * a run of another batch is never shown as this batch's last run.
 */
export function bulkStorageKey(batchId) {
    return `orreryBulkRun:batch:${String(batchId)}`;
}

/** The request of a list page: `query` when set, `before_id` for a later page. */
export function listParams(query, beforeId = null) {
    const params = {};

    if (typeof query === 'string' && query !== '') params.query = query;
    if (typeof beforeId === 'string' && beforeId !== '') params.before_id = beforeId;

    return params;
}

/** The search text of the route's query (`?query=`), cut to what the server accepts. */
export function queryFromRoute(query) {
    const value = query && typeof query.query === 'string' ? query.query : '';

    return [...value].slice(0, QUERY_MAX).join('');
}

/**
 * The `before_id` of the next older page: the id of the last row, only
 * when the page is full (a shorter page is the last one).
 */
export function olderCursor(response) {
    const rows = response && Array.isArray(response.batches) ? response.batches : [];

    return rows.length >= PAGE_SIZE ? String(rows[rows.length - 1].id) : null;
}

/**
 * What the list shows: `unknown` (`batches: null` with its reason through
 * wording.js), `unavailable` ("Batching is not set up"), `empty` (with or
 * without a search; `paged` for a later page), or `rows`.
 */
export function listState(response, query = '', {paged = false} = {}) {
    if (!response || typeof response !== 'object') return {kind: 'loading', title: null, text: null};

    if (response.batches === null) {
        return {kind: 'unknown', title: 'Batches unknown', text: unknownReasonText(response.unknown_reason)};
    }

    if (response.available === false) {
        return {kind: 'unavailable', title: 'Batching is not set up', text: codeText('batching_unavailable')};
    }

    if (!Array.isArray(response.batches) || response.batches.length === 0) {
        // A later page can be empty when the batches older than the last row shown were pruned meanwhile.
        if (paged) return {kind: 'empty', title: 'No older batches', text: 'No batch is older than the last one shown.'};

        return query !== ''
            ? {kind: 'empty', title: 'No batch matches this search', text: `Searched the names and ids for “${query}”.`}
            : {kind: 'empty', title: 'No batches', text: 'Laravel has no batches in its batch table.'};
    }

    return {kind: 'rows', title: null, text: null};
}

/** The sentence of an `unknown_reason` of the batch reads. */
export function unknownReasonText(reason) {
    if (typeof reason !== 'string' || reason === '') return codeText('batches_unknown');

    return codeText(UNKNOWN_CODES[reason] || reason);
}

/**
 * What a failed read shows. The detail: 404 `not_found` "This batch is no
 * longer kept", 404 `batching_unavailable` "Batching is not set up", other
 * codes through wording.js. `gone` stops the detail's poll.
 */
export function readFailure(error, {detail = false} = {}) {
    const response = error && error.response ? error.response : null;
    const body = response && response.data && typeof response.data === 'object' ? response.data : {};
    const code = typeof body.error === 'string' && body.error !== '' ? body.error : null;
    const what = detail ? 'The batch' : 'The batches';

    if (detail && response && response.status === 404 && code === 'batching_unavailable') {
        return {kind: 'unavailable', gone: true, title: 'Batching is not set up', text: codeText('batching_unavailable')};
    }

    if (detail && response && response.status === 404) {
        return {kind: 'not_found', gone: true, title: 'This batch is no longer kept', text: 'Laravel no longer has this batch: it was pruned, or the id is wrong.'};
    }

    let sentence;

    if (isShapeError(error)) sentence = UNREADABLE_ANSWER;
    else if (!response) sentence = 'The server did not answer. Try again.';
    else if (code !== null) sentence = codeText(code);
    else sentence = `The server answered with an error (HTTP ${response.status}). Try again.`;

    return {kind: 'error', gone: false, title: `${what} could not be loaded`, text: sentence};
}

/**
 * The caption of the member tabs: they count Orrery's records
 * that are still kept, which can differ from Laravel's counts above.
 */
export const MEMBERS_CAPTION = "Orrery's records that are still kept; the counts above are Laravel's.";
