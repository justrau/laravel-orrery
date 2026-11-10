/**
 * The rules of the shared filter bar (components/ui/FilterBar.vue):
 * the time presets, the "Filters N" count, the active filters in
 * words, the queue choices and the URL query. Pure helpers, so Node can test
 * them. The filters are the object of support/lists.js `filtersFromQuery()`.
 *
 * The byte-exact filters (connection, queue, class, batch) are never
 * trimmed. Times are epoch seconds; a custom range is entered in the
 * browser's local time.
 */

import {phpTrim, queryFromFilters} from './lists.js';
import {formatLocal, nowSeconds, timeZoneLabel, zonedEpoch, zonedParts} from './time.js';

/** The time presets of the filter bar: from = now − the preset, no end. */
export const TIME_PRESETS = Object.freeze([
    Object.freeze({key: '1h', label: '1 h', words: 'the last hour', seconds: 3600}),
    Object.freeze({key: '24h', label: '24 h', words: 'the last 24 h', seconds: 86400}),
    Object.freeze({key: '7d', label: '7 d', words: 'the last 7 days', seconds: 604800}),
]);

/** The filters of the bar, besides the tab, the order and the search text. */
const FILTER_KEYS = ['connection', 'queue', 'class', 'tag', 'batch', 'from', 'to'];

/** Every key of the URL query that the list filters own. */
const QUERY_KEYS = ['status', ...FILTER_KEYS, 'q', 'order'];

function preset(key) {
    return TIME_PRESETS.find(entry => entry.key === key) || null;
}

function set(value) {
    return value !== null && value !== undefined && value !== '';
}

/** The range of a preset at `now`; 'all' clears both ends. */
export function presetRange(key, now) {
    if (key === 'all') return {from: null, to: null};

    const entry = preset(key);

    if (!entry) throw new Error(`Unknown time preset: ${key}`);

    return {from: now - entry.seconds, to: null};
}

/**
 * The time button that is active: 'all' without a range, the preset of
 * `choice` ({key, from}, what the bar set) while the filters still hold its
 * range, else 'custom'. The URL keeps only the epochs, so after a reload a
 * preset's range shows as custom.
 */
export function activePreset(filters, choice) {
    const from = filters ? filters.from : null;
    const to = filters ? filters.to : null;

    if (!set(from) && !set(to)) return 'all';

    if (choice && preset(choice.key) && !set(to) && choice.from === from) return choice.key;

    return 'custom';
}

/**
 * The number on the "Filters N" button: the queue (with its connection),
 * the class, the tag, the batch and the time range count once each. The
 * search text, the tab and the order are outside the button.
 */
export function activeFilterCount(filters) {
    if (!filters) return 0;

    let count = 0;

    if (set(filters.connection)) count++;
    if (set(filters.class)) count++;
    if (set(filters.tag)) count++;
    if (set(filters.batch)) count++;
    if (set(filters.from) || set(filters.to)) count++;

    return count;
}

/** The active filters in words, in the order of the bar. */
export function filterSummary(filters, choice) {
    if (!filters) return [];

    const parts = [];

    if (set(filters.connection)) {
        parts.push(set(filters.queue) ? `${filters.connection}:${filters.queue}` : `${filters.connection}, every queue`);
    }

    if (set(filters.class)) parts.push(`class ${filters.class}`);
    if (set(filters.tag)) parts.push(`tag ${filters.tag}`);
    if (set(filters.batch)) parts.push(`batch ${filters.batch}`);

    const active = activePreset(filters, choice);

    if (active !== 'all' && active !== 'custom') {
        parts.push(preset(active).words);
    } else if (set(filters.from) && set(filters.to)) {
        parts.push(`from ${formatLocal(filters.from)} to ${formatLocal(filters.to)}`);
    } else if (set(filters.from)) {
        parts.push(`since ${formatLocal(filters.from)}`);
    } else if (set(filters.to)) {
        parts.push(`until ${formatLocal(filters.to)}`);
    }

    return parts;
}

/**
 * The queue choices: every workload queue ({connection, name} rows of
 * /api/workload), and the filtered queue when the workload does not list it.
 * A key joins the two names; it is never split again (a queue name may hold
 * ":").
 */
export function queueOptions(workload, filters) {
    const options = [];
    const seen = new Set();
    const add = (connection, queue) => {
        const key = `${connection}:${queue}`;

        if (seen.has(key)) return;

        seen.add(key);
        options.push({key, connection, queue, label: key});
    };

    for (const row of Array.isArray(workload) ? workload : []) {
        if (row && typeof row.connection === 'string' && typeof row.name === 'string') add(row.connection, row.name);
    }

    if (filters && set(filters.connection) && set(filters.queue)) add(filters.connection, filters.queue);

    return options;
}

/**
 * The filters after `changes`. An empty value is null; a queue without its
 * connection is dropped; the tab and the order do not change here.
 */
export function withFilters(filters, changes) {
    const next = {...filters};

    for (const key of [...FILTER_KEYS, 'q']) {
        if (changes && Object.prototype.hasOwnProperty.call(changes, key)) {
            next[key] = set(changes[key]) ? changes[key] : null;
        }
    }

    if (!set(next.connection)) next.queue = null;

    return next;
}

/** The filters after "Clear filters": the tab and the order stay. */
export function clearedFilters(filters) {
    const next = {...filters};

    for (const key of [...FILTER_KEYS, 'q']) next[key] = null;

    return next;
}

function pad(value) {
    return String(value).padStart(2, '0');
}

/**
 * The epoch of a `datetime-local` value in the display time zone
 * (support/time.js displayTimeZone(): orrery.display_timezone, else the
 * browser's zone), or null for an empty or invalid value.
 */
export function epochFromInput(value) {
    if (typeof value !== 'string') return null;

    const match = /^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})(?::(\d{2}))?$/.exec(value.trim());

    if (!match) return null;

    const [, year, month, day, hour, minute, second] = match.map(Number);
    if (month < 1 || month > 12 || day < 1 || day > 31 || hour > 23 || minute > 59 || (Number.isFinite(second) && second > 59)) return null;

    const epoch = zonedEpoch(year, month, day, hour, minute, Number.isFinite(second) ? second : 0);

    return Number.isFinite(epoch) ? Math.floor(epoch) : null;
}

/** The `datetime-local` value of an epoch in the display time zone, to the minute, or ''. */
export function inputFromEpoch(epoch) {
    if (typeof epoch !== 'number' || !Number.isFinite(epoch)) return '';

    const parts = zonedParts(epoch);

    return `${parts.year}-${pad(parts.month)}-${pad(parts.day)}T${pad(parts.hour)}:${pad(parts.minute)}`;
}

/** The note beside the custom-range inputs: the zone they are read in ("Times in GMT+9"). */
export function rangeZoneNote(now = nowSeconds()) {
    return `Times in ${timeZoneLabel(now)}`;
}

/** Why a custom range is refused, or null: the start must be before the end. */
export function rangeProblem(from, to) {
    if (!set(from) || !set(to)) return null;

    return from < to ? null : 'The start must be before the end.';
}

/**
 * The route query after a filter change: the list keys are replaced by the
 * filters' query, every other key of the screen stays. Without a status in
 * the filters, the query gets none.
 */
export function mergeQuery(query, filters) {
    const next = {};

    for (const [key, value] of Object.entries(query && typeof query === 'object' ? query : {})) {
        if (!QUERY_KEYS.includes(key)) next[key] = value;
    }

    const own = queryFromFilters(filters);

    if (!set(filters.status)) delete own.status;

    return {...next, ...own};
}

/**
 * The value that a typed filter sends, or null when it is empty: the class
 * is byte-exact and sent as typed; the tag and the search text are trimmed
 * as PHP trims them (phpTrim()).
 */
export function committedText(key, value) {
    if (typeof value !== 'string') return null;

    const sent = key === 'class' ? value : phpTrim(value);

    return sent === '' ? null : sent;
}
