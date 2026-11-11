/**
 * The rules of the Tags screen,
 * and run states"). Pure helpers, so Node can test them
 * (tests/js/tags.test.js); index.vue only renders what they decide.
 *
 * - A tag is byte-exact. The SPA trims it only as PHP's trim() does
 *   (phpTrim(), the TagNormalizer's rule), so a no-break space stays, and
 *   sends it as it is: monitor in the raw JSON body, unmonitor in the query
 *   (`DELETE /api/tags/monitored?tag=`), the tag view's jobs as `tag=`.
 * - The tag view lives at /tags/:tag when one path segment can carry the
 *   tag, else at /tags?tag= (tag-links.js); the global
 *   guard moves such a path there before the address bar shows it.
 * - Unknown is never zero: a count that could not be read is "—", a monitor
 *   state that could not be read is "unknown" and offers no write.
 */

import {failureKind} from '../../errors.js';
import {cappedCount, isUuid, phpTrim} from '../../support/lists.js';
import {timeCell} from '../jobs/jobs.js';
import {timesInNote} from '../../support/time.js';
import {statusChip} from '../jobs/detail.js';
import {codeText} from '../../support/wording.js';
import {tagLocation} from './tag-links.js';

/**
 * The text of the Stop monitoring sheet (decided 2026-11-09):
 * a plain sentence, no extra read and no computed duration.
 */
export const STOP_SHEET_MESSAGE = 'Its jobs then follow the retention of their state instead of the monitored retention. You can still look the tag up.';

const MISSING = '—';

function number(value) {
    return typeof value === 'number' && Number.isFinite(value) ? value : null;
}

/**
 * The tag that a route selects, from the path or else from `?tag=`, or null
 * for none. It is trimmed only as PHP trims it; `valid` is false for a blank
 * tag or one with a NUL (the server refuses both).
 */
export function routeTag(params, query) {
    let raw = null;

    if (params && typeof params.tag === 'string') raw = params.tag;
    else if (query && typeof query.tag === 'string') raw = query.tag;

    if (raw === null) return null;

    const tag = phpTrim(raw);

    // A NUL anywhere, also at an end that the trim strips: the server drops such a tag (TagNormalizer).
    return {tag, valid: tag !== '' && !raw.includes('\0')};
}

/** The tab of the tag view: 'failed' only for `tab=failed`. */
export function tabFromQuery(query) {
    return query && query.tab === 'failed' ? 'failed' : 'all';
}

function failedTone(total) {
    const count = total ? number(total.count) : null;

    return count !== null && count > 0 ? 'danger' : 'muted';
}

/**
 * The rows of the monitored tags table: each tag with its capped counts
 * ("1,000+"), "—" for a count that could not be read, and the failed count
 * in red only above 0.
 */
export function monitoredRows(response) {
    const tags = response && Array.isArray(response.tags) ? response.tags : [];

    return tags
        .filter(row => row && typeof row.tag === 'string')
        .map(row => ({
            tag: row.tag,
            jobs: cappedCount(row.count),
            failed: cappedCount(row.failed),
            failedTone: failedTone(row.failed),
        }));
}

/**
 * The notes under the monitored tags: the truncation (more than 100
 * monitored tags) and the empty state, which invites a write only with the
 * operate ability.
 */
export function monitoredNotes(response, canOperate) {
    const empty = !!response && Array.isArray(response.tags) && response.tags.length === 0;

    return {
        truncated: response && response.truncated === true ? 'Showing the first 100 monitored tags.' : null,
        empty: empty
            ? (canOperate === true ? 'No tags are monitored. Type a tag above and choose Monitor to keep its jobs longer.' : 'No tags are monitored.')
            : null,
    };
}

/**
 * The monitor state of the tag view (the `lookup` of GET /api/tags?tag=)
 * and the write it offers: Stop monitoring for a monitored tag, Monitor for
 * another one, nothing for an unknown state or a viewer.
 */
export function monitorChip(lookup, canOperate) {
    const monitored = lookup && typeof lookup.monitored === 'boolean' ? lookup.monitored : null;

    if (monitored === null) return {state: 'unknown', label: 'Monitor state unknown', action: null};

    const writable = canOperate === true;

    return monitored
        ? {state: 'monitored', label: 'Monitored', action: writable ? 'stop' : null}
        : {state: 'not_monitored', label: 'Not monitored', action: writable ? 'monitor' : null};
}

/** The problem of a typed tag before a write or a lookup, or null. */
export function tagInputProblem(text) {
    const raw = typeof text === 'string' ? text : '';

    // The NUL first, as the server checks it before its trim (which strips a NUL at an end).
    if (raw.includes('\0')) return 'A tag cannot contain a NUL character.';
    if (phpTrim(raw) === '') return 'Type a tag.';

    return null;
}

/**
 * The lookup of the tag view: the `lookup` of a GET /api/tags answer only
 * when that request asked for `tag` (`lookupFor`), else null (unknown). It
 * never compares with `lookup.tag`, the stored form, which a long tag cuts.
 */
export function viewLookup(response, lookupFor, tag) {
    if (!response || typeof tag !== 'string' || lookupFor !== tag) return null;

    return response.lookup && typeof response.lookup === 'object' ? response.lookup : null;
}

/**
 * Where a monitor of the typed tag goes when it answers: the monitored
 * tag's view, or null when the screen is gone or its route changed while
 * the write was pending.
 */
export function afterMonitor(answer, {unmounted = false, routeChanged = false} = {}) {
    if (unmounted || routeChanged || !answer || typeof answer.tag !== 'string') return null;

    return tagLocation(answer.tag);
}

/** The monitor request: the tag in the raw JSON body (the server reads the body, not the trimmed input). */
export function monitorRequest(tag) {
    return {method: 'post', url: '/api/tags/monitored', data: {tag: phpTrim(tag)}};
}

/** The unmonitor request: the query form, which carries '.', '..' and '/' (never the path form). */
export function unmonitorRequest(tag) {
    return {method: 'delete', url: '/api/tags/monitored', params: {tag}};
}

const UNSAVED = 'The change may not have been saved; reload to see the current state.';

/**
 * The words of a monitor ('monitor') or unmonitor ('stop') answer. An error
 * never claims that nothing changed unless the server says so (422).
 */
export function writeResult(action, answer, error = null) {
    if (error === null || error === undefined) {
        const tag = answer && typeof answer.tag === 'string' ? answer.tag : MISSING;
        const changed = !!answer && answer.changed === true;
        let text;

        if (action === 'stop') text = changed ? `Stopped monitoring “${tag}”.` : `“${tag}” was not monitored.`;
        else text = changed ? `Now monitoring “${tag}”.` : `“${tag}” was already monitored.`;

        return {tone: 'success', text};
    }

    const response = error.response || null;
    const body = response && response.data && typeof response.data === 'object' ? response.data : {};

    if (!response) return {tone: 'error', text: `The server did not answer. ${UNSAVED}`};
    if (typeof body.error === 'string' && body.error !== '') return {tone: 'error', text: codeText(body.error)};

    return {tone: 'error', text: `The server answered with an error (HTTP ${response.status}). ${UNSAVED}`};
}

/**
 * The failures that a monitor or unmonitor write shows itself (its
 * `handles`, errors.js): a refused request, a server error, a timeout and no
 * answer. Every other failure (401, 403 and 419 with the session states, and
 * 404, 405, 413, 429 or an aborted request) is the global alert's.
 */
export const WRITE_HANDLES = Object.freeze([422, 500, 502, 503, 504, 'timeout', 'network']);

/**
 * The write's own notice of a failed write (writeResult()), or null when the
 * global alert shows the failure, so the operator never reads it twice.
 */
export function writeFailure(action, error) {
    const response = error && error.response ? error.response : null;
    // A request that the screen cancelled (a CanceledError of support/http.js) is aborted: failureKind() reads it as no answer.
    const cancelled = !!error && (error.__CANCEL__ === true || error.code === 'ERR_CANCELED');
    const key = response ? response.status : cancelled ? 'aborted' : failureKind(error);

    return WRITE_HANDLES.includes(key) ? writeResult(action, null, error || {}) : null;
}

/** The Stop monitoring sheet of a tag; its selection is the tag. */
export function stopSheet(tag) {
    return {
        title: `Stop monitoring “${tag}”?`,
        message: STOP_SHEET_MESSAGE,
        confirmLabel: 'Stop monitoring',
        selection: {tag},
    };
}

/**
 * The list engine read of a tab: status `all` or `failed`
 * with the exact tag, the page's order and cursor, and the page size.
 */
export function tagListRequest(tag, tab, page, perPage) {
    const params = {status: tab === 'failed' ? 'failed' : 'all', tag};

    if (page && page.order === 'asc') params.order = 'asc';
    if (page && typeof page.cursor === 'string') params.cursor = page.cursor;
    if (perPage) params.per_page = perPage;

    return {endpoint: '/api/jobs', params};
}

/** The two tabs with their list totals ("10,000+", "—" while unknown); a failed total above 0 is red. */
export function tabItems(active, allTotal, failedTotal) {
    return [
        {key: 'all', label: 'All', count: cappedCount(allTotal), active: active !== 'failed', tone: null},
        {key: 'failed', label: 'Failed', count: cappedCount(failedTotal), active: active === 'failed', tone: failedTone(failedTotal) === 'danger' ? 'danger' : null},
    ];
}

/**
 * A row of the tag view: the status chip (a silenced completion says
 * Silenced), the tab's time (All: the push, Failed: the failure), the job
 * name, the queue with its connection as the title, and the detail link.
 */
export function tagRow(row, tab, now) {
    const chip = row.status === 'completed' && row.silenced === true
        ? {label: 'Silenced', tone: 'neutral'}
        : statusChip(row.status);

    return {
        chip: {label: chip.label, tone: chip.tone},
        time: timeCell(tab === 'failed' ? row.failed_at : row.pushed_at, now),
        name: typeof row.name === 'string' && row.name !== '' ? row.name : MISSING,
        queue: typeof row.queue === 'string' ? row.queue : MISSING,
        queueTitle: `${row.connection}:${row.queue}`,
        link: isUuid(row.id) ? `/jobs/${encodeURIComponent(row.id)}` : null,
    };
}

/** The empty state of a tab. */
export function tagRowsEmpty(tab) {
    return tab === 'failed' ? 'No failed jobs with this tag are kept.' : 'No jobs with this tag are kept.';
}

/** The Jobs list with this exact tag: the Failed tab from Failed, the default tab from All. */
export function jobsLink(tag, tab) {
    return {path: '/jobs', query: tab === 'failed' ? {status: 'failed', tag} : {tag}};
}

/** The words of a failed read (the monitored tags or a tab's list). */
export function readFailure(error) {
    const response = error && error.response ? error.response : null;
    const body = response && response.data && typeof response.data === 'object' ? response.data : {};

    if (!response) return 'The server did not answer. Try again.';
    if (typeof body.error === 'string' && body.error !== '') return codeText(body.error);

    return `The server answered with an error (HTTP ${response.status}). Try again.`;
}

/** The toolbar subtitle: the tag view's rows show a bare clock, so it names their zone. */
export function tagsSubtitle(now) {
    return `Monitored tags keep their jobs longer · any tag can be looked up · ${timesInNote(now)}`;
}

/** The rows of one page of a tag's list. */
export const TAG_PER_PAGE = 50;

/** The monitored tags read, with the lookup of the selected tag (null: none). */
export function monitoredTagsRead(tag) {
    return {url: '/api/tags', params: tag !== null && tag !== undefined ? {tag} : {}};
}

/** The read of a page of a tag's tab (tagListRequest()), as `{url, params}`; the screen's page size, TAG_PER_PAGE at first. */
export function tagPageRead(tag, tab, page, perPage = TAG_PER_PAGE) {
    const request = tagListRequest(tag, tab, page, perPage);

    return {url: request.endpoint, params: request.params};
}

/**
 * The Tags screen's first reads for a route, as its created() asks them:
 * the monitored tags (with the lookup of the selected tag), and the first
 * page of the selected tag's tab.
 */
export function tagsFirstRequests(params, query) {
    const selected = routeTag(params, query);
    const tag = selected && selected.valid ? selected.tag : null;
    const requests = [monitoredTagsRead(tag)];

    if (tag !== null) requests.push(tagPageRead(tag, tabFromQuery(query), {order: 'desc', cursor: null}));

    return requests;
}
