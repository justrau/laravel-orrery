/**
 * The client rules of the list engine: the tab and the
 * filters in the URL, the request of a tab, the capped totals, the budget
 * notice, the keyset pager and the "New entries" banner. Pure helpers, so
 * Node can test them; the Jobs list and the shared pager and filter
 * bar use them.
 *
 * The rules of the SPA hold: an unknown count shows as "—", never 0; a
 * capped count says "at least" or "+"; the cursor never goes into the URL (a
 * reload starts at the first page).
 */

import {formatCount} from './time.js';

/** The tabs of the Jobs list, in order. `stuck` reads its own endpoint. */
export const LIST_TABS = Object.freeze(['pending', 'running', 'completed', 'silenced', 'failed', 'retrying', 'unknown', 'stuck']);

/** The filters of the URL and of the request, besides the tab and the order, in one order. */
const FILTERS = ['connection', 'queue', 'class', 'tag', 'batch', 'from', 'to', 'q'];

/**
 * The byte-exact filters: the server compares them byte for
 * byte, so a queue "emails " is not "emails", and they are never trimmed.
 */
const EXACT_FILTERS = ['connection', 'queue', 'class', 'batch'];

/** The longest batch id that the server accepts. */
const BATCH_MAX = 255;

/** The text of `q`: a full uuid, or 3 to 100 characters. */
const Q_MIN = 3;
const Q_MAX = 100;

const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;

/** Whether a value is a full uuid, in any case (no prefix). */
export function isUuid(value) {
    return typeof value === 'string' && UUID.test(value);
}

/** A query value of the URL as it is: only a non-empty string counts (`tag[]=x` is absent). */
function exact(value) {
    return typeof value === 'string' && value !== '' ? value : null;
}

/**
 * A value trimmed as PHP's trim() does (space, tab, newline, carriage
 * return, NUL, vertical tab), and nothing else: the server trims the tag
 * (TagNormalizer) and the search text that way, and keeps a no-break space,
 * an ideographic space or a byte order mark. A value that is not a string
 * comes back as it is.
 */
export function phpTrim(value) {
    return typeof value === 'string' ? value.replace(/^[ \t\n\r\0\x0B]+|[ \t\n\r\0\x0B]+$/g, '') : value;
}

/** A trimmed query value of the URL, for the values that are not byte-exact. */
function text(value) {
    if (typeof value !== 'string') return null;

    const trimmed = phpTrim(value);

    return trimmed === '' ? null : trimmed;
}

function epoch(value) {
    const raw = text(value);

    if (raw === null || !/^-?\d+(\.\d+)?$/.test(raw)) return null;

    const number = Number(raw);

    return Number.isFinite(number) ? number : null;
}

/**
 * The tab and the filters of a route query. An unknown tab is `pending`; a
 * queue without its connection is dropped (the server refuses it); the
 * cursor is never read from the URL.
 */
export function filtersFromQuery(query) {
    const source = query && typeof query === 'object' ? query : {};
    const status = text(source.status);
    const filters = {status: LIST_TABS.includes(status) ? status : 'pending'};

    for (const key of EXACT_FILTERS) {
        filters[key] = exact(source[key]);
    }

    // A tag is normalised as stored (TagNormalizer), so a trimmed tag is the same tag.
    filters.tag = text(source.tag);

    if (filters.connection === null) filters.queue = null;
    if (filters.batch !== null && [...filters.batch].length > BATCH_MAX) filters.batch = null;

    for (const key of ['from', 'to']) {
        filters[key] = epoch(source[key]);
    }

    filters.q = text(source.q);
    filters.order = text(source.order) === 'asc' ? 'asc' : 'desc';

    return filters;
}

/**
 * The first filter of a route query that the screen cannot apply, or null:
 * `{code, field, values}` with a code of support/wording.js. filtersFromQuery()
 * drops such a filter (a queue without its connection, a batch id longer
 * than the server takes, a time that is not epoch seconds, a repeated key),
 * so a screen that shows the list anyway would show, and a bulk run would
 * act on, more jobs than the link asked for. An empty value is absent, not
 * invalid.
 */
export function queryProblem(query) {
    const source = query && typeof query === 'object' ? query : {};
    const filters = filtersFromQuery(source);
    const invalid = field => ({code: 'filter_invalid', field, values: {field}});

    for (const key of FILTERS) {
        if (Array.isArray(source[key])) return invalid(key);
    }

    if (exact(source.queue) !== null && filters.connection === null) {
        return {code: 'queue_needs_connection', field: 'queue', values: {queue: source.queue}};
    }

    if (exact(source.batch) !== null && filters.batch === null) return invalid('batch');

    for (const key of ['from', 'to']) {
        if (text(source[key]) !== null && filters[key] === null) return invalid(key);
    }

    return null;
}

/** The route query of the filters: the tab, and only the filters that are set. */
export function queryFromFilters(filters) {
    const query = {status: filters.status};

    for (const key of FILTERS) {
        const value = filters[key];

        if (value !== null && value !== undefined && value !== '') query[key] = String(value);
    }

    if (filters.order === 'asc') query.order = 'asc';

    // The keys in one order, so a route comparison does not see a change.
    return Object.fromEntries(['status', ...FILTERS, 'order']
        .filter(key => key in query)
        .map(key => [key, query[key]]));
}

/** The endpoint of a tab. */
export function listEndpoint(status) {
    return status === 'stuck' ? '/api/jobs/stuck' : '/api/jobs';
}

/** The filters that the stuck endpoint takes. */
const STUCK_FILTERS = ['connection', 'queue', 'class'];

/**
 * The query parameters of a list request; the order is sent only when it is
 * `asc`. `stuck` sends no status (its endpoint is its own): it sends its
 * `signal` sub-tab and only the connection, the queue and the class.
 */
export function listParams(filters, {cursor = null, newerThan = null, perPage = null, signal = null} = {}) {
    const params = {};

    if (filters.status === 'stuck') {
        if (signal !== null) params.signal = signal;

        for (const key of STUCK_FILTERS) {
            const value = filters[key];

            if (value !== null && value !== undefined && value !== '') params[key] = value;
        }

        if (cursor !== null) params.cursor = cursor;
        if (perPage !== null) params.per_page = perPage;

        return params;
    }

    params.status = filters.status;

    for (const key of FILTERS) {
        const value = filters[key];

        if (value !== null && value !== undefined && value !== '') params[key] = value;
    }

    if (filters.order === 'asc') params.order = 'asc';
    if (cursor !== null) params.cursor = cursor;
    if (newerThan !== null) params.newer_than = newerThan;
    if (perPage !== null) params.per_page = perPage;

    return params;
}

/**
 * A stable key of the tab, the filters and the order. When it changes, the
 * list drops its cursor and its head: the server refuses a cursor of another
 * list (`cursor_mismatch`).
 */
export function filterKey(filters) {
    const given = {status: filters.status, order: filters.order};

    for (const key of FILTERS) {
        given[key] = filters[key] ?? null;
    }

    const normal = filtersFromQuery(queryFromFilters(given));

    return JSON.stringify(['status', ...FILTERS, 'order'].map(key => normal[key]));
}

function knownCount(total) {
    return total && typeof total.count === 'number' && Number.isFinite(total.count) ? total.count : null;
}

/** A capped count for a tab: "1,000,000+", "12", or "—" when unknown. */
export function cappedCount(total) {
    const count = knownCount(total);

    if (count === null) return '—';

    return formatCount(count) + (total.at_least === true ? '+' : '');
}

/** The filtered total of a list: "at least 10,000", "12", or "—" when unknown. */
export function totalText(total) {
    const count = knownCount(total);

    if (count === null) return '—';

    return (total.at_least === true ? 'at least ' : '') + formatCount(count);
}

/**
 * The notice of a scan that reached the candidate budget, or null. `budget`
 * is `orrery.dashboard.search_candidates` when the page knows it.
 */
export function searchLimitedText(response, budget = null) {
    if (!response || response.search_limited !== true) return null;

    const known = typeof budget === 'number' && Number.isFinite(budget);
    const first = typeof response.prev_cursor !== 'string';
    let rows = 'only part of the rows';

    if (known && !first) {
        // A later page continues the scan of the page before it.
        rows = `${formatCount(budget)} more rows`;
    } else if (known) {
        rows = `the ${response.order === 'asc' ? 'oldest' : 'newest'} ${formatCount(budget)} rows`;
    }

    return `Searched ${rows} in this order. Narrow the search with a time range.`;
}

/**
 * Which keyset pager buttons a response allows: Newest, Newer, Older,
 * Oldest. `prev_cursor` leads to the earlier pages of the list's order and
 * `next_cursor` to the later ones; in the order `asc` (oldest first) the
 * earlier pages hold older rows, so the labels swap. `order` defaults to the
 * response's own order.
 */
export function pagerActions(response, order = null) {
    const earlier = !!response && typeof response.prev_cursor === 'string';
    const later = !!response && typeof response.next_cursor === 'string';
    const ascending = (order || (response && response.order)) === 'asc';
    const newer = ascending ? later : earlier;
    const older = ascending ? earlier : later;

    // An ascending read is never the newest page: Newest reads the order desc again.
    return {newest: ascending ? !!response : newer, newer, older, oldest: older};
}

/**
 * What a list does with `has_newer`: 'banner' shows "New
 * entries", 'reload' loads the first page again (the auto-load toggle), and
 * null does nothing. Only `true` acts: null is unknown, not "nothing new".
 */
export function newEntriesAction(response, autoLoad) {
    if (!response || response.has_newer !== true) return null;

    return autoLoad ? 'reload' : 'banner';
}

/** The problem of a search text, or null when the server accepts it. */
export function qProblem(q) {
    const value = text(q);

    if (value === null || isUuid(value)) return null;

    // Characters, as the server counts them (mb_strlen), not UTF-16 units.
    const length = [...value].length;

    if (length < Q_MIN) return `Type at least ${Q_MIN} characters.`;
    if (length > Q_MAX) return `Type at most ${Q_MAX} characters.`;

    return null;
}

/** The page sizes of the pager (the server takes 1–100). */
export const PER_PAGE_CHOICES = Object.freeze([25, 50, 100]);

/**
 * The request of a pager button:
 * Newest is the first page in the order `desc`, Oldest the first page in
 * the order `asc` (shown reversed, pageRows()); Newer and Older follow the
 * cursors of the current page in that page's own request order (a cursor
 * belongs to one order: the server refuses it in another). `page` is
 * {order, response} of the current page. Null when the button has no page.
 */
export function pagerRequest(action, page) {
    if (action === 'newest') return {order: 'desc', cursor: null};
    if (action === 'oldest') return {order: 'asc', cursor: null};
    if ((action !== 'newer' && action !== 'older') || !page) return null;

    const order = page.order === 'asc' ? 'asc' : 'desc';
    const response = page.response || {};
    const earlier = typeof response.prev_cursor === 'string' ? response.prev_cursor : null;
    const later = typeof response.next_cursor === 'string' ? response.next_cursor : null;
    // In the order asc the later pages hold newer rows.
    const cursor = (action === 'newer') === (order === 'asc') ? later : earlier;

    return cursor === null ? null : {order, cursor};
}

/**
 * The labels of the Newer and Older buttons: a pager without Newest and
 * Oldest (`edges` false: a list in one fixed order, such as a stuck signal
 * read by a count) says Previous and Next, because its pages are not newer
 * or older.
 */
export function pagerLabels(edges) {
    return edges === false ? {newer: 'Previous', older: 'Next'} : {newer: 'Newer', older: 'Older'};
}

/** The rows of a page in the display order: a page read in the other order is reversed. */
export function pageRows(rows, requestOrder, displayOrder) {
    const list = Array.isArray(rows) ? [...rows] : [];

    return (requestOrder === 'asc') === (displayOrder === 'asc') ? list : list.reverse();
}

/**
 * The text beside the pager: where the page is (first or last in the
 * display order; a keyset page has no number) and how many rows it shows of
 * the filtered total. `actions` is pagerActions() of the page; `short`
 * drops "shown" (the narrow pager).
 */
export function pagerSummary({rows, total, actions, displayOrder = 'desc', short = false}) {
    const before = displayOrder === 'asc' ? actions.older : actions.newer;
    const after = displayOrder === 'asc' ? actions.newer : actions.older;
    const count = knownCount(total);
    const shown = short ? '' : ' shown';

    if (count !== null && total.at_least !== true && !before && !after && rows === count) {
        return `All ${formatCount(count)}${shown}`;
    }

    const parts = [];

    if (!before) parts.push('First page');
    else if (!after) parts.push('Last page');

    if (count === null) {
        parts.push(`${formatCount(rows)}${shown}`, 'total unknown');
    } else {
        parts.push(`${formatCount(rows)} of ${totalText(total)}${shown}`);
    }

    return parts.join(' · ');
}

const TAB_LABELS = {
    pending: 'Pending', running: 'Running', completed: 'Completed', silenced: 'Silenced',
    failed: 'Failed', retrying: 'Retrying', unknown: 'Unknown', stuck: 'Stuck',
};

/**
 * The tabs with their global counts (`GET /api/counts`; the filters do not
 * change them). Retrying shows while its count is above 0 or unknown, and
 * while it is open. Stuck has no count in /api/counts, and its
 * list total counts only the open signal sub-tab ("Stuck 0" while other
 * signals hold jobs), so it shows no count (null); the open sub-tab shows
 * its own total (jobs.js signalTabs()).
 */
export function tabItems(counts, status) {
    return LIST_TABS
        .filter(key => {
            if (key !== 'retrying' || key === status) return true;

            const count = knownCount(counts ? counts.retrying : null);

            return count === null || count > 0;
        })
        .map(key => {
            let count;

            if (key === 'stuck') count = null;
            else count = cappedCount(counts ? counts[key] : null);

            return {key, label: TAB_LABELS[key], count, active: key === status};
        });
}
