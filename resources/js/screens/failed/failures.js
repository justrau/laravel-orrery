/**
 * The data rules of the Failed jobs screen: pure functions
 * from the API responses to what the screen shows, so the component stays
 * thin and each rule is in one place.
 */

import {formatDateTimeZoned, formatDayMinuteZoned, formatHourMinuteZoned, isSameDisplayDay, nowSeconds, timesInNote, toSeconds} from '../../support/time.js';

/** The ranges of the screen, in seconds. */
export const RANGES = [
    {key: '1h', label: '1 h', seconds: 3600, words: 'past hour'},
    {key: '24h', label: '24 h', seconds: 86400, words: 'past 24 h'},
    {key: '7d', label: '7 d', seconds: 604800, words: 'past 7 d'},
];

/**
 * The stored run of every failure-group host (the Failure groups screen, the
 * Overview notices, the Queues failures; BulkRun host rule 2): one key, so
 * a run started on any of them is found again on the Failure groups screen,
 * the only one that shows the recovery after a reload.
 */
export const GROUPS_BULK_STORAGE_KEY = 'orreryBulkRun:groups';

/** The most group keys of one bulk run with a groups source. */
export const MAX_GROUP_KEYS = 200;

/** A marker counts for a group when it lies at most this long before the group's first failure. */
export const MARKER_LEAD_SECONDS = 120;

/** The range with the given key, else the 1 h range. */
export function rangeOf(key) {
    return RANGES.find(range => range.key === key) || RANGES[0];
}

/** The groups of one page of the Failure groups list (the server's page size). */
export const GROUP_PAGE = 50;

const GROUP_KEY = /^[0-9a-f]{64}$/;

/**
 * The view of the Failure groups screen from its route query: `{range,
 * queue, offset, group}` (the view is in the URL, so a reload or a
 * shared link shows the same view). A value that is not valid is the
 * default: range `1h`, every queue (''), the first page (0), no group (null).
 * `offset` is a whole page (GROUP_PAGE), and `group` a group key.
 */
export function failedView(query) {
    const given = query && typeof query === 'object' ? query : {};
    const text = value => (typeof value === 'string' ? value : null);
    const range = RANGES.some(entry => entry.key === text(given.range)) ? given.range : '1h';
    const offset = /^\d{1,9}$/.test(text(given.offset) || '') ? Number(given.offset) : 0;
    const group = text(given.group);

    return {
        range,
        queue: text(given.queue) || '',
        offset: offset % GROUP_PAGE === 0 ? offset : 0,
        group: group !== null && GROUP_KEY.test(group) ? group : null,
    };
}

/** The route query of a view (failedView()): only the parts that differ from the default. */
export function failedViewQuery(view) {
    const query = {};

    if (view.range && view.range !== '1h') query.range = view.range;
    if (view.queue) query.queue = view.queue;
    if (view.offset > 0) query.offset = String(view.offset);
    if (view.group) query.group = view.group;

    return query;
}

/**
 * The live display window: `to` is now, `from` is now − range. The
 * screen computes it again for every poll.
 */
export function liveWindow(rangeSeconds, now) {
    const to = Math.floor(now);

    return {from: to - rangeSeconds, to};
}

/** The part after the last backslash: "App\Jobs\Sync" → "Sync". */
export function shortName(name) {
    if (typeof name !== 'string' || name === '') return '—';

    const parts = name.split('\\');

    return parts[parts.length - 1] || name;
}

/** "database:default" → "default". */
export function queueName(scoped) {
    if (typeof scoped !== 'string') return '—';

    const index = scoped.indexOf(':');

    return index === -1 ? scoped : scoped.slice(index + 1);
}

/** The first line of a text (split on CRLF, LF or CR, as the group key does). */
export function firstLine(text) {
    return typeof text === 'string' ? text.split(/\r\n|\n|\r/)[0] : null;
}

/**
 * The queue options of the filter from `GET /api/workload`: one option per
 * connection alias and queue ("connection:queue"). A row that merges aliases
 * gives each alias its own option, because a failed row keeps the alias
 * that ran it.
 */
export function queueOptions(workload) {
    const rows = Array.isArray(workload) ? workload : [];
    const options = [];
    const seen = new Set();

    rows.forEach(row => {
        if (!row || typeof row.name !== 'string') return;

        const aliases = Array.isArray(row.connections) && row.connections.length
            ? row.connections
            : (typeof row.connection === 'string' ? [row.connection] : []);

        aliases.forEach(alias => {
            const value = `${alias}:${row.name}`;

            if (seen.has(value)) return;

            seen.add(value);
            options.push({value, queue: row.name, connection: alias});
        });
    });

    const names = options.map(option => option.queue);

    return options.map(option => ({
        value: option.value,
        label: names.filter(name => name === option.queue).length > 1 ? `${option.queue} (${option.connection})` : option.queue,
    }));
}

/** "27", or "≥ 27" when the count is a lower bound. */
export function countText(count, atLeast) {
    if (typeof count !== 'number') return '—';

    return atLeast ? `≥ ${count.toLocaleString()}` : count.toLocaleString();
}

/**
 * The label of a group Retry or Forget button (a bulk run takes
 * every failed member of the groups, page after page, so there is no cap of
 * 200 any more): "Retry 37", "Retry ≥ 5,000" for a lower bound, and the
 * bare verb when the count is unknown or 0. The bulk sheet then shows the
 * frozen total.
 */
export function operationLabel(verb, count, atLeast) {
    if (typeof count !== 'number' || !Number.isFinite(count) || count <= 0) return verb;

    return atLeast ? `${verb} ≥ ${count.toLocaleString()}` : `${verb} ${count.toLocaleString()}`;
}

/**
 * The bulk-run source of failure groups (`{kind: 'groups'}`):
 * the queue scope ("connection:queue" or null), the window and the keys,
 * each key once, in order. Null without a key, with more than
 * MAX_GROUP_KEYS keys, or without a window: no run can start then.
 */
export function groupsSource(queue, window, keys, groups = null) {
    const list = [...new Set((Array.isArray(keys) ? keys : []).filter(key => typeof key === 'string' && key !== ''))];
    const from = window ? toSeconds(window.from) : null;
    const to = window ? toSeconds(window.to) : null;

    if (!list.length || list.length > MAX_GROUP_KEYS || from === null || to === null) return null;

    const source = {kind: 'groups', queue: typeof queue === 'string' && queue !== '' ? queue : null, from, to, keys: list};

    // The names of the groups, in key order, for the sheet only (support/bulk.js sourceSummary()); freezeBody() never sends them.
    if (Array.isArray(groups)) {
        source.names = list
            .map(key => groups.find(group => group && group.key === key) || null)
            .filter(Boolean)
            .map(group => ({exception_class: group.exception_class ?? null, message: group.message ?? null}));
    }

    return source;
}

/**
 * The source of "Start a new run…" after a reload (the recovered run's
 * `source` from `GET /api/bulk/{ref}`): an exact copy of a groups source,
 * the same queue, window and keys that the operator confirmed, never moved
 * to now and never the screen's selected group. Null for another kind or a
 * source without keys or window.
 */
export function restartSource(source) {
    if (!source || source.kind !== 'groups' || !Array.isArray(source.keys)) return null;

    const keys = source.keys.filter(key => typeof key === 'string' && key !== '');

    if (!keys.length || keys.length > MAX_GROUP_KEYS || typeof source.from !== 'number' || typeof source.to !== 'number') return null;

    return {kind: 'groups', queue: typeof source.queue === 'string' && source.queue !== '' ? source.queue : null, from: source.from, to: source.to, keys};
}

/**
 * The Jobs list of the failed jobs behind a failure group: the
 * Failed tab filtered by the group's job class and, when the screen has a
 * queue scope, that queue. The list cannot filter by the group key, so the
 * link names the class, not the group.
 */
export function failedJobsLink(group, queue = null) {
    const query = {status: 'failed'};

    if (group && typeof group.job === 'string' && group.job !== '') query.class = group.job;

    if (typeof queue === 'string' && queue.includes(':')) {
        const index = queue.indexOf(':');

        query.connection = queue.slice(0, index);
        query.queue = queue.slice(index + 1);
    }

    return {path: '/jobs', query};
}

/** The read of "Latest failed jobs": the newest rows of the Jobs list's Failed tab (D-13). */
export const LATEST_PATH = '/api/jobs';

/** The parameters of that read: the failed tab, newest failure first, `rows` of them. */
export function latestParams(rows) {
    return {status: 'failed', per_page: rows};
}

/** The count after a group's exception name: "×27", "× ≥5,000" for a lower bound, or '' when unknown. */
export function timesText(count, atLeast) {
    if (typeof count !== 'number' || !Number.isFinite(count)) return '';

    return atLeast ? `× ≥${count.toLocaleString()}` : `×${count.toLocaleString()}`;
}

/** "at least 41" or "41". */
export function atLeastText(count, atLeast) {
    if (typeof count !== 'number') return '—';

    return atLeast ? `at least ${count.toLocaleString()}` : count.toLocaleString();
}

/**
 * "312 still failed" from the failed count of `GET /api/counts` ({ count,
 * at_least }): "at least 1,000,000 still failed" at the count cap, and
 * "— still failed" when the count is unknown (null, or the read failed).
 */
export function stillFailedText(total) {
    const count = total && typeof total === 'object' ? toSeconds(total.count) : null;

    if (count === null) return '— still failed';

    return `${atLeastText(count, total.at_least === true)} still failed`;
}

/**
 * The toolbar subtitle: "41 in the past hour · 312 still failed · last 7
 * days". The past hour is the failed rows of the unfiltered 1 h scan (its
 * `scanned`, "at least" when truncated). "Still failed" is the failed count
 * of `GET /api/counts` (stillFailedText(); `undefined` leaves it out before
 * the counts loaded). The days follow the retention horizon of the
 * response. A part without data is left out.
 */
export function subtitleText(hour, failedTotal) {
    const parts = [];

    if (hour && typeof hour.scanned === 'number') {
        parts.push(`${atLeastText(hour.scanned, hour.truncated === true)} in the past hour`);
    }

    if (failedTotal !== undefined) {
        parts.push(stillFailedText(failedTotal));
    }

    const days = retentionDays(hour);

    if (days !== null) {
        parts.push(`last ${days} ${days === 1 ? 'day' : 'days'}`);
    }

    return parts.length ? parts.join(' · ') : null;
}

/** The retention of failed rows in whole days, from `as_of − retention_from`, or null. */
export function retentionDays(response) {
    if (!response || typeof response.as_of !== 'number' || typeof response.retention_from !== 'number') return null;

    const days = Math.round((response.as_of - response.retention_from) / 86400);

    return days >= 1 ? days : null;
}

/** "3 attempts each" or "2–3 attempts" from the detail's `attempts`, plus ", of the newest 5,000" for a capped group. */
export function attemptsText(attempts, each = 'each') {
    if (!attempts || typeof attempts.min !== 'number' || typeof attempts.max !== 'number') return '—';

    // A capped count reads the attempts of its newest rows only.
    const newest = typeof attempts.of_newest === 'number' ? `, of the newest ${attempts.of_newest.toLocaleString()}` : '';

    if (attempts.min === attempts.max) {
        return `${attempts.min} ${attempts.min === 1 ? 'attempt' : 'attempts'} ${each}${newest}`;
    }

    return `${attempts.min}–${attempts.max} attempts${newest}`;
}

/**
 * The chart buckets of the list: for each bucket, the selected group's count
 * (`selected`) and the other listed groups' sum (`other`), or `all` without a
 * selected group. A group lists every slot from the window and the retention
 * horizon on, except a capped group, which omits the slots before its oldest
 * counted row. A slot that no group has is absent here; a slot that some
 * listed group lacks is null in every sum that group belongs to: unknown,
 * never zero.
 *
 * A response without groups has no bucket list. Its slots are zero when the
 * scan was complete (no failures), from the first slot that ends after the
 * retention horizon.
 */
export function chartBuckets(response, selectedKey, window) {
    const groups = response && Array.isArray(response.groups) ? response.groups : [];
    const size = response && response.bucket_seconds > 0 ? response.bucket_seconds : 60;

    if (!groups.length) {
        if (!response || response.truncated === true || !window) return [];

        const buckets = [];
        const retention = typeof response.retention_from === 'number' ? response.retention_from : null;

        for (let at = Math.floor(window.from / size) * size; at < window.to; at += size) {
            if (retention !== null && at + size <= retention) continue;

            buckets.push({at, selected: 0, other: 0, all: 0, partial: at + size > window.to});
        }

        return buckets;
    }

    const byStart = new Map();
    const hasSelected = !!selectedKey && groups.some(group => group.key === selectedKey);
    const isSelected = group => hasSelected && group.key === selectedKey;
    const selectedCount = groups.filter(isSelected).length;
    const otherCount = groups.length - selectedCount;

    groups.forEach(group => {
        const seen = new Set();

        (Array.isArray(group.buckets) ? group.buckets : []).forEach(bucket => {
            if (!bucket || !Number.isFinite(bucket.at) || seen.has(bucket.at)) return;

            seen.add(bucket.at);

            const entry = byStart.get(bucket.at) || {at: bucket.at, selected: 0, other: 0, all: 0, partial: false, selectedGroups: 0, otherGroups: 0};
            const count = typeof bucket.count === 'number' ? bucket.count : 0;

            entry.all += count;

            if (isSelected(group)) {
                entry.selected += count;
                entry.selectedGroups += 1;
            } else {
                entry.other += count;
                entry.otherGroups += 1;
            }

            entry.partial = entry.partial || bucket.partial === true;
            byStart.set(bucket.at, entry);
        });
    });

    // A slot that a listed group lacks (a capped group before its oldest counted row) is unknown for every sum
    // that group belongs to: a gap, never the other groups' count alone.
    return [...byStart.values()].sort((a, b) => a.at - b.at).map(({selectedGroups, otherGroups, ...entry}) => {
        const selectedKnown = selectedGroups === selectedCount;
        const otherKnown = otherGroups === otherCount;

        return {
            ...entry,
            selected: selectedKnown ? entry.selected : null,
            other: otherKnown ? entry.other : null,
            all: selectedKnown && otherKnown ? entry.all : null,
        };
    });
}

/**
 * The peak of a bucket list by `key`: { at, value }, or null when there is
 * no failure. A partial bucket counts: its failures happened, and the
 * current minute of a burst is often the largest.
 */
export function peakOf(buckets, key) {
    let peak = null;

    (Array.isArray(buckets) ? buckets : []).forEach(bucket => {
        const value = bucket ? bucket[key] : null;

        if (typeof value === 'number' && value > 0 && (peak === null || value > peak.value)) {
            peak = {at: bucket.at, value};
        }
    });

    return peak;
}

/** "/min" or "/h" for the bucket size. */
export function perUnit(bucketSeconds) {
    return bucketSeconds === 3600 ? '/h' : (bucketSeconds === 60 ? '/min' : ` per ${bucketSeconds} s`);
}

/** The subtitle of the latest failed jobs: its rows show a bare clock, so it names their zone. */
export function latestSubtitle(now) {
    return `newest first by failure time · all queues · ${timesInNote(now)}`;
}

/**
 * "peak 1,234/min at 09:14 GMT+3" ("peak at least …" when the count is a
 * lower bound; "at 2026-09-26 14:00 GMT+3" for a peak before `now`'s day),
 * or null without a peak.
 */
export function peakText(peak, bucketSeconds, atLeast, now = nowSeconds()) {
    if (!peak) return null;

    return `peak ${atLeast ? 'at least ' : ''}${peak.value.toLocaleString()}${perUnit(bucketSeconds)} at ${formatDayMinuteZoned(peak.at, now)}`;
}

/**
 * A first or last failure time: "09:14 GMT+3" on `now`'s day in the display
 * zone, else "2026-09-26 09:14 GMT+3" (also 23 h ago when that was
 * yesterday), so an older time never reads as today's. "—" when unknown.
 */
export function seenTimeText(epoch, now) {
    const at = toSeconds(epoch);

    if (at === null) return '—';

    return isSameDisplayDay(at, now) ? formatHourMinuteZoned(at) : formatDateTimeZoned(at);
}

/**
 * The marker that lies at most MARKER_LEAD_SECONDS before the first failure
 * (the nearest one), or null. `rangeFrom` is the start of the read range:
 * the first failure is only the first in that range, so a marker counts
 * only when the range shows the whole lead before it without a failure;
 * else the group may have failed before the range, and the marker did not
 * start it.
 */
export function markerBefore(markers, firstFailedAt, rangeFrom = null) {
    if (typeof firstFailedAt !== 'number') return null;
    if (typeof rangeFrom === 'number' && firstFailedAt - rangeFrom < MARKER_LEAD_SECONDS) return null;

    const candidates = (Array.isArray(markers) ? markers : [])
        .filter(marker => marker && typeof marker.at === 'number'
            && marker.at <= firstFailedAt && firstFailedAt - marker.at <= MARKER_LEAD_SECONDS)
        .sort((a, b) => b.at - a.at);

    return candidates[0] || null;
}

/** The name of a marker: "Restart requested", or "Deploy" plus its label. */
export function markerName(marker) {
    if (!marker) return '';

    return marker.kind === 'deploy'
        ? ['Deploy', marker.label].filter(value => typeof value === 'string' && value !== '').join(' ')
        : 'Restart requested';
}

/**
 * Whether a group's count is a lower bound: the keyed
 * count passed the cap (`count_capped`), or the group holds rows of a
 * truncated fallback scan (`exact: false`).
 */
export function groupCountAtLeast(group) {
    return !!group && (group.count_capped === true || group.exact === false);
}

/**
 * Whether a group's start may be earlier than its `first_failed_at`: only a
 * group with rows of a truncated fallback scan. A capped keyed count still
 * has an exact start (a MIN over the key), so it has no qualifier.
 */
export function groupStartUncertain(group) {
    return !!group && group.exact === false && group.count_capped !== true;
}

/**
 * "first in this range at 09:14 GMT+3" (`range` names the read range, for
 * example "in the last hour"), with "or earlier" when the start may be
 * earlier (groupStartUncertain()). `first_failed_at` is the first failure
 * inside the range, never the group's start: it may have failed before.
 */
export function groupSinceText(group, range = 'in this range') {
    const first = group ? toSeconds(group.first_failed_at) : null;

    if (first === null) return `first ${range} at an unknown time`;

    return `first ${range} at ${formatHourMinuteZoned(first)}${groupStartUncertain(group) ? ' or earlier' : ''}`;
}

/**
 * The queues of a group as text: "emails, default", or "emails and more"
 * when the scanned members do not cover the whole group (`queues_complete:
 * false`). `name` maps each "connection:queue" entry (the full entry by
 * default). Repeated names are listed once.
 */
export function groupQueuesText(group, name = entry => entry) {
    const queues = group && Array.isArray(group.queues) ? group.queues.filter(entry => typeof entry === 'string') : [];
    const names = [...new Set(queues.map(name))];
    const more = !!group && group.queues_complete === false;

    if (!names.length) return more ? 'unknown queues' : '—';

    return `${names.join(', ')}${more ? ' and more' : ''}`;
}

/**
 * The inspector text of a detail without a group: the group is certainly
 * gone from the range only when the read was complete; else it was not found
 * among the failures that were read.
 */
export function groupGoneTitle(detail) {
    return detail && detail.complete === true
        ? 'No failed jobs of this group in this range'
        : 'Not found among the failures read';
}

/**
 * Whether a row of the Failed tab (`GET /api/jobs?status=failed`) seems to
 * belong to the group: the same exception class, job name and first line
 * of the message. Both texts come through the same projection,
 * so they compare as the group key's inputs do; the list does not
 * send the key itself. Two messages that redact to the same text compare
 * equal, so this marks a likely member, never a proven one. A row or a
 * group without an exception class matches nothing.
 */
export function rowInGroup(row, group) {
    if (!row || !group || typeof group.exception_class !== 'string' || group.exception_class === '') return false;

    return row.exception_class === group.exception_class
        && row.name === group.job
        && firstLine(row.exception_message || '') === firstLine(group.message || '');
}

/**
 * The text of a group's `latest_trace`: the trace itself, a sentence when
 * the server left it out because it is larger than it shows
 * ({omitted: "too_large", bytes}), else null (no trace, or an unknown
 * shape).
 */
export function traceText(trace) {
    if (typeof trace === 'string') return trace;

    if (trace && typeof trace === 'object' && trace.omitted === 'too_large') {
        const bytes = Number.isFinite(trace.bytes) ? ` (${trace.bytes.toLocaleString()} bytes)` : '';

        return `The stack trace is too large to show${bytes}.`;
    }

    return null;
}

/** The part of a trace that traceLocation() searches: the first frame is near the start. */
export const TRACE_SEARCH_CHARS = 4096;

/**
 * "SocketStream.php:154": the file and line of the first frame of a trace, or
 * null. It searches only the first TRACE_SEARCH_CHARS characters with a
 * bounded file name, so a long run without separators costs linear time
 * (an unbounded `+` was quadratic: 1.7 s for 54 KB).
 */
export function traceLocation(trace) {
    if (typeof trace !== 'string') return null;

    const match = trace.slice(0, TRACE_SEARCH_CHARS).match(/([^\s/\\()]{1,200}\.php)(?::|\()(\d{1,9})/);

    return match ? `${match[1]}:${match[2]}` : null;
}

/** The rows of "Latest failed jobs". */
export const LATEST_ROWS = 5;

/** The params of a failure group read: the live window, the queue filter, and `extra` (an offset). */
export function groupWindowParams(window, queue, extra = {}) {
    const params = {from: window.from, to: window.to, ...extra};

    if (queue) params.queue = queue;

    return params;
}

/** The read of one group's detail in a window: `GET /api/failures/groups/{key}`. */
export function groupDetailRead(key, window, queue) {
    return {url: `/api/failures/groups/${key}`, params: groupWindowParams(window, queue), timeKeys: ['from', 'to']};
}

/**
 * The reads of one refresh of the Failure groups screen for a view
 * ({range, queue, offset, group}) at `now`: `{window, groups, latest,
 * markers, detail}`. `groups` is null for the live past hour of all queues
 * (the screen takes it from the shell), `detail` null without a group. The
 * screen's refresh() and the route's first load (failedFirstRequests())
 * both build their reads here, so a prefetch is always the screen's read.
 */
export function failedReads(view, now) {
    const window = liveWindow(rangeOf(view.range).seconds, now);
    const live = view.range === '1h' && !view.queue && !view.offset;

    return {
        window,
        groups: live ? null : {url: '/api/failures/groups', params: groupWindowParams(window, view.queue, view.offset ? {offset: view.offset} : {}), timeKeys: ['from', 'to']},
        latest: {url: LATEST_PATH, params: latestParams(LATEST_ROWS)},
        markers: {url: '/api/markers', params: {since: window.from - MARKER_LEAD_SECONDS}, timeKeys: ['since']},
        detail: view.group ? groupDetailRead(view.group, window, view.queue) : null,
    };
}

/**
 * The Failure groups screen's first reads for a route query (failedReads()
 * of the URL's view): the group list (not for the live past hour of all
 * queues), the latest failed jobs, the markers and the selected group.
 */
export function failedFirstRequests(query, now) {
    const reads = failedReads(failedView(query), now);

    return [reads.groups, reads.latest, reads.markers, reads.detail].filter(Boolean);
}
