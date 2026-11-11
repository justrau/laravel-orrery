/**
 * The rules of the Job classes view.
 * Pure helpers, so Node can
 * test them; screens/classes/index.vue stays thin.
 *
 * - The table reads `GET /api/classes?minutes=15|60`; the inspector reads
 *   `GET /api/classes/series?name=<class>&minutes=60|1440`.
 * - The class name is byte-exact: it is never trimmed or case-folded, in the
 *   URL, in a lookup or in the link to the Jobs list.
 * - Unknown is never zero: a null count is "—", an incomplete sum is a lower
 *   bound ("≥ N", "at least N"), and an absent bucket is a gap in a chart.
 * - `pushed` is null when any minute's arrivals are unknown, so a number is
 *   an exact sum and never gets "≥".
 * - Every time is epoch seconds through toSeconds(), shown in local time
 *   with its zone label.
 */

import {codeText} from '../../support/wording.js';
import {formatCount, formatDuration, formatHourMinute, timeZoneLabel, toSeconds} from '../../support/time.js';

/** The windows of the table, in minutes (the API takes 5..60). */
export const WINDOWS = Object.freeze([15, 60]);

export const DEFAULT_WINDOW = 60;

/** The spans of the inspector's charts: key → minutes (the API takes 5..1440). */
export const SPANS = Object.freeze({'1h': 60, '24h': 1440});

/** The poll interval of the table and of the inspector. */
export const POLL_SECONDS = 15;

const MISSING = '—';

function count(value) {
    const number = toSeconds(value);

    return number === null ? null : number;
}

/** The window of a route query: 15 or 60 minutes, else the default. */
export function windowFromQuery(query) {
    const value = query && typeof query === 'object' ? query.minutes : null;

    return typeof value === 'string' && WINDOWS.map(String).includes(value) ? Number(value) : DEFAULT_WINDOW;
}

/** The selected class of a route query, byte for byte, or null. */
export function selectedFromQuery(query) {
    const value = query && typeof query === 'object' ? query.class : null;

    return typeof value === 'string' && value !== '' ? value : null;
}

/** The route query of a window and a selection: the default window is left out; the keys in one order. */
export function queryFor(window, name) {
    const query = {};

    if (window !== DEFAULT_WINDOW && WINDOWS.includes(window)) query.minutes = String(window);
    if (typeof name === 'string' && name !== '') query.class = name;

    return query;
}

/** The minutes of a chart span key; an unknown key is 1 h. */
export function spanMinutes(span) {
    return Object.prototype.hasOwnProperty.call(SPANS, span) ? SPANS[span] : SPANS['1h'];
}

/** The query parameters of the series read of a class. */
export function seriesParams(name, span) {
    return {name, minutes: spanMinutes(span)};
}

function retention(value) {
    const source = value && typeof value === 'object' ? value : {};

    return {
        completed: toSeconds(source.completed),
        failed: toSeconds(source.failed),
        pushed: toSeconds(source.pushed),
    };
}

/**
 * The `/api/classes` response, normalized: numbers or null, never a value
 * made up for an unknown one. Null for a response of another shape.
 */
export function normalizeClasses(data) {
    if (!data || typeof data !== 'object' || !Array.isArray(data.classes)) return null;

    const unknown = data.unknown_minutes;
    const unknownCount = unknown && typeof unknown === 'object' ? count(unknown.count) : null;

    return {
        asOf: toSeconds(data.as_of),
        from: toSeconds(data.from),
        to: toSeconds(data.to),
        retentionFrom: retention(data.retention_from),
        classes: data.classes
            .filter(row => row && typeof row === 'object' && typeof row.name === 'string')
            .map(row => ({
                name: row.name,
                completed: count(row.completed),
                failed: count(row.failed),
                pushed: count(row.pushed),
                runtimeAvgMs: count(row.runtime_avg_ms),
                complete: row.complete === true,
            })),
        truncated: data.truncated === true,
        capped: data.capped === true,
        unknownMinutes: unknownCount !== null && unknownCount > 0
            ? {count: unknownCount, first: toSeconds(unknown.first), last: toSeconds(unknown.last)}
            : null,
    };
}

/** A sum of the table: "18,204", "≥ 18,204" for a lower bound, "—" when unknown. */
export function countText(value, complete) {
    if (value === null || value === undefined) return MISSING;

    return (complete ? '' : '≥ ') + formatCount(value);
}

/** The full-text title of a sum: "at least 27 failed", "27 failed", "failed unknown". */
export function countTitle(value, complete, noun) {
    if (value === null || value === undefined) return `${noun} unknown`;

    return `${complete ? '' : 'at least '}${formatCount(value)} ${noun}`;
}

/** The arrivals ("In"): an exact sum, or "—" when a minute's arrivals are unknown. */
export function inText(pushed) {
    return pushed === null || pushed === undefined ? MISSING : formatCount(pushed);
}

/** An average runtime in milliseconds: "340 ms", "1.2 s", "4 min 12 s", or "—" without a run. */
export function runtimeText(ms) {
    const value = toSeconds(ms);

    if (value === null || value < 0) return MISSING;
    if (Math.round(value) < 1000) return `${Math.round(value)} ms`;

    // Rounded to a tenth: 999.6 ms is "1.0 s", and 59,999 ms is "1 min" as 60,000 ms is, never "1000 ms" or "60.0 s".
    const seconds = (value / 1000).toFixed(1);

    if (value < 60000) return seconds === '60.0' ? formatDuration(60) : `${seconds} s`;

    return formatDuration(value / 1000);
}

/**
 * The notice of the uncounted minutes of the window (P-5), or null when
 * every minute was counted. A capped read says why: the row bound of the
 * read left those minutes out.
 */
export function unknownNotice(unknown, capped = false) {
    if (!unknown || !(unknown.count > 0)) return null;

    const zone = timeZoneLabel(unknown.first);
    const range = unknown.count === 1 || unknown.first === unknown.last
        ? `${formatHourMinute(unknown.first)} ${zone}`
        : `${formatHourMinute(unknown.first)} to ${formatHourMinute(unknown.last)} ${zone}`;
    const minutes = unknown.count === 1 ? '1 minute' : `${formatCount(unknown.count)} minutes`;

    if (capped) {
        return `${minutes} in this window could not be counted (${range}): the window has more job class rows than the read takes (dashboard.class_rows), for example job names that change per job. Sums that include them are lower bounds and read “at least”, and the list shows the busiest classes of the counted minutes.`;
    }

    return `${minutes} in this window could not be counted (${range}). Sums that include them are lower bounds and read “at least”.`;
}

/**
 * The live announcement of the uncounted minutes: it names the count only,
 * so a screen reader hears it once per change of the count, not on every
 * poll that moves the window.
 */
export function unknownAnnouncement(unknown) {
    if (!unknown || !(unknown.count > 0)) return '';

    return `${unknown.count === 1 ? '1 minute' : `${formatCount(unknown.count)} minutes`} in this window could not be counted.`;
}

/** The note of a selected class that the table does not list. */
export function notListedText(data, window) {
    if (data && data.truncated) {
        return `This class is not among the ${formatCount(data.classes.length)} busiest classes of the last ${window} minutes.`;
    }

    return `This class has no counted minute in the last ${window} minutes.`;
}

/** The table's title: "6 classes", "1 class", or the cut list. */
export function classesTitle(total, truncated) {
    if (truncated) return `Showing the ${formatCount(total)} busiest classes`;

    return total === 1 ? '1 class' : `${formatCount(total)} classes`;
}

/** "12:34–13:33 UTC · sorted by completed + failed", or null before the first read. */
export function windowLine(data) {
    if (!data || data.from === null || data.to === null) return null;

    return `${formatHourMinute(data.from)}–${formatHourMinute(data.to)} ${timeZoneLabel(data.to)} · sorted by completed + failed`;
}

/** The empty state: nothing ran, or nothing was counted while some minutes are unknown. */
export function emptyText(data, window) {
    if (data && data.unknownMinutes) {
        return `No job class was counted in the last ${window} minutes, and some minutes could not be counted.`;
    }

    return `No job class ran in the last ${window} minutes.`;
}

/** The row of a class, by its byte-exact name, or null. */
export function findClass(classes, name) {
    if (!Array.isArray(classes)) return null;

    return classes.find(row => row && row.name === name) || null;
}

/**
 * The Jobs list on the class and a window: the Completed
 * tab, the class byte for byte, and the half-open window [from, to) widened
 * to whole seconds.
 */
export function jobsLink(name, window) {
    const query = {status: 'completed', class: name};
    const from = window ? toSeconds(window.from) : null;
    const to = window ? toSeconds(window.to) : null;

    if (from !== null) query.from = String(Math.floor(from));
    if (to !== null) query.to = String(Math.ceil(to));

    return {path: '/jobs', query};
}

const BUCKET_KEYS = ['completed', 'failed', 'pushed', 'runtime_avg_ms'];

/** The `/api/classes/series` response, normalized; null for another shape. */
export function normalizeSeries(data) {
    if (!data || typeof data !== 'object' || !Array.isArray(data.buckets)) return null;

    const bucketSeconds = toSeconds(data.bucket_seconds);

    return {
        name: typeof data.name === 'string' ? data.name : null,
        asOf: toSeconds(data.as_of),
        from: toSeconds(data.from),
        to: toSeconds(data.to),
        bucketSeconds: bucketSeconds !== null && bucketSeconds > 0 ? bucketSeconds : 60,
        retentionFrom: retention(data.retention_from),
        buckets: data.buckets
            .filter(bucket => bucket && typeof bucket === 'object' && toSeconds(bucket.at) !== null)
            .map(bucket => {
                const entry = {at: toSeconds(bucket.at)};

                BUCKET_KEYS.forEach(key => {
                    entry[key] = count(bucket[key]);
                });

                entry.partial = bucket.partial === true;

                return entry;
            }),
    };
}

/** The BarChart series of the inspector: completed bars and the failed band, each with its own retention horizon. */
export function chartSeries(retentionFrom) {
    const horizon = retentionFrom || {};

    return {
        series: [{key: 'completed', label: 'completed', color: 'var(--color-accent)', retentionFrom: horizon.completed ?? null}],
        band: {key: 'failed', label: 'failed', color: 'var(--color-danger)', retentionFrom: horizon.failed ?? null},
    };
}

/**
 * "peak 412": the busiest whole minute by completed + failed, or null. When
 * a whole minute between the series' start and the current minute is absent
 * or has an unknown count, the true peak may be there: "peak ≥ 412".
 */
export function peakText(series) {
    const minutes = fullMinutes(series);

    if (minutes === null) return null;

    const size = series.bucketSeconds;
    const byStart = new Map(minutes.buckets.map(bucket => [bucket.at, bucket]));
    let peak = null;
    let unknown = false;

    for (let at = Math.floor(minutes.from / size) * size; at < minutes.to; at += size) {
        const bucket = byStart.get(at);

        if (!bucket || typeof bucket.completed !== 'number' || typeof bucket.failed !== 'number') {
            unknown = true;
            continue;
        }

        const total = bucket.completed + bucket.failed;

        if (peak === null || total > peak) peak = total;
    }

    if (peak === null) return null;

    return `peak ${unknown ? '≥ ' : ''}${formatCount(peak)}`;
}

/**
 * The whole minutes of a series, for the sparklines: the window ends at the
 * start of the current minute, and the partial bucket is left out, so it is
 * never drawn as a complete minute. Null without a series.
 */
export function fullMinutes(series) {
    if (!series || series.asOf === null || series.from === null) return null;

    const size = series.bucketSeconds;
    const to = Math.floor(series.asOf / size) * size;

    return {
        from: series.from,
        to,
        buckets: series.buckets.filter(bucket => !bucket.partial && bucket.at < to),
    };
}

/** "last full minute 302": the value of the minute before the current one, "—" when unknown; null without a series. */
export function lastFullMinuteText(series, key, format) {
    if (!series || series.asOf === null) return null;

    const size = series.bucketSeconds;
    const last = Math.floor(series.asOf / size) * size - size;
    const bucket = series.buckets.find(entry => entry.at === last);
    const value = bucket ? bucket[key] : null;

    return `last full minute ${value === null ? MISSING : format(value)}`;
}

/** The second line of a row below 768 px: done, failed, in and the average, each with its own rule. */
export function compactParts(row) {
    return [
        {key: 'completed', text: `${countText(row.completed, row.complete)} done`, tone: null},
        {key: 'failed', text: `${countText(row.failed, row.complete)} failed`, tone: row.failed > 0 ? 'danger' : null},
        {key: 'pushed', text: `${inText(row.pushed)} in`, tone: null},
        {key: 'runtime', text: row.runtimeAvgMs === null ? `runtime ${MISSING}` : `${runtimeText(row.runtimeAvgMs)} avg`, tone: null},
    ];
}

const UNEXPECTED = 'orreryUnexpectedAnswer';

/** The error of a response of another shape than the screen reads. */
export function unexpectedAnswer() {
    const error = new Error('The answer has another shape.');

    error[UNEXPECTED] = true;

    return error;
}

/** The sentence of a failed read: the wording of its error code, else the status, else no answer. */
export function classFailure(error) {
    const response = error && error.response ? error.response : null;
    const body = response && response.data && typeof response.data === 'object' ? response.data : {};
    const code = typeof body.error === 'string' && body.error !== '' ? body.error : null;

    if (error && error[UNEXPECTED] === true) return 'The server sent an unexpected answer. Try again.';
    if (!response) return 'The server did not answer. Try again.';
    if (code !== null) return codeText(code);

    return `The server answered with an error (HTTP ${response.status}). Try again.`;
}

/** The span of the class charts when the screen opens. */
export const DEFAULT_SPAN = '1h';

/** The Job classes screen's first reads for a route query: the classes of the window, and the selected class's series. */
export function classesFirstRequests(query) {
    const requests = [{url: '/api/classes', params: {minutes: windowFromQuery(query)}}];
    const selected = selectedFromQuery(query);

    if (selected !== null) requests.push({url: '/api/classes/series', params: seriesParams(selected, DEFAULT_SPAN)});

    return requests;
}
