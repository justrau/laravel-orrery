/**
 * The data rules of the Queues screen: pure functions
 * from the API responses to what the screen shows, so the components stay
 * thin and each rule is in one place.
 *
 * Unknown is never zero: a null or non-numeric value stays null, and the
 * screen shows "—". Numbers can arrive as strings from the older endpoints
 * (decimal columns on MySQL and Postgres), so every number goes through
 * num(), which accepts a finite number or a numeric string.
 */

import {formatCount, formatDayMinuteZoned, formatDuration, formatHourMinuteZoned, formatLimit, nowSeconds, relativeTime, toSeconds} from '../../support/time.js';
import {codeText} from '../../support/wording.js';
import {heartbeatAge} from '../../shell/data.js';
import {groupCountAtLeast, operationLabel, timesText} from '../failed/failures.js';
import {
    broaderCauses,
    causesText,
    continueWarning,
    isRecordFailed,
    issuedCause,
    pauseCommands,
    pauseLabel,
    pauseNotes,
    pauseState,
    recordFailedNote,
    recordFailedWarning,
} from '../../support/pause.js';

/** The table and the failures poll every this many seconds. */
export const POLL_SECONDS = 10;

/** At most this many history requests are in flight at once. */
export const HISTORY_CONCURRENCY = 4;

/** The backlog cards shown (and read) at first; "Show more" adds this many. */
export const CARD_PAGE = 24;

/** A change of the queue set loads the new cards after this delay (ms), so a row that drops for one poll costs nothing. */
export const HISTORY_DEBOUNCE_MS = 1500;

/**
 * Run the task functions with at most `limit` in flight, and resolve with
 * their settled results in order (as Promise.allSettled). A task that
 * `stop()` rejects before it starts is skipped as rejected.
 */
export function runLimited(tasks, limit, stop = () => false) {
    const results = new Array(tasks.length);
    let next = 0;

    const worker = () => {
        if (next >= tasks.length) return Promise.resolve();

        const index = next++;

        if (stop()) {
            results[index] = {status: 'rejected', reason: new Error('stopped')};

            return worker();
        }

        return Promise.resolve()
            .then(tasks[index])
            .then(value => { results[index] = {status: 'fulfilled', value}; }, reason => { results[index] = {status: 'rejected', reason}; })
            .then(worker);
    };

    return Promise.all(Array.from({length: Math.min(limit, tasks.length)}, worker)).then(() => results);
}

/** The failures window: the live past hour. */
export const FAILURE_WINDOW_SECONDS = 3600;

/** A failure group gets the attention dot when its last failure is this recent. */
export const RECENT_FAILURE_SECONDS = 900;

/** The page size of the failure groups: a full page may have a next page. */
export const GROUP_PAGE_SIZE = 50;

/** The failure rows shown on this screen; the rest are in Failed jobs. */
export const FAILURE_ROWS = 10;

/** The fixed text of every terminate (the shared rules). */
export const TERMINATE_TEXT = 'Stops every worker after its current job. Your process manager starts them again.';

/** A finite number from a number or a numeric string, else null. */
export function num(value) {
    return toSeconds(value);
}

/**
 * One workload row with its numbers coerced (num()) and its unknowns kept:
 * - `pause` is the pause state of the row (support/pause.js pauseState():
 *   every cause in `pauses`, and whether it is known off);
 * - `backlogUnknown` means that the backlog read failed: `ready`,
 *   `oldestAge` and the limit state are unknown, not empty.
 */
export function normalizeRow(row) {
    if (!row || typeof row.name !== 'string' || typeof row.connection !== 'string') return null;

    const connections = Array.isArray(row.connections) && row.connections.length
        ? row.connections.filter(alias => typeof alias === 'string')
        : [row.connection];
    const threshold = num(row.threshold);

    return {
        key: `${row.connection}:${row.name}`,
        name: row.name,
        connection: row.connection,
        connections,
        ready: num(row.length),
        workers: num(row.processes),
        quietWorkers: num(row.quiet_processes),
        unknownStateWorkers: num(row.unknown_state_processes),
        oldestAge: num(row.oldest_age),
        threshold: threshold === null ? 0 : threshold,
        overLimit: row.over_limit === true,
        overLimitSince: row.over_limit === true ? num(row.over_limit_since) : null,
        overLimitCensored: row.over_limit === true && row.over_limit_since_censored === true,
        running: num(row.running),
        inPerMin: num(row.in_per_min),
        outPerMin: num(row.out_per_min),
        pause: pauseState(row),
        backlogUnknown: row.backlog_unknown === true,
    };
}

/** The normalized rows of a `GET /api/workload` response (invalid rows dropped). */
export function normalizeWorkload(data) {
    return (Array.isArray(data) ? data : []).map(normalizeRow).filter(Boolean);
}

/**
 * The row that a route names: the queue on its primary connection, or on
 * one of its aliases (a link may name any alias). Null when there is none.
 */
export function findRow(rows, connection, queue) {
    if (!connection || !queue) return null;

    return rows.find(row => row.name === queue && row.connection === connection)
        || rows.find(row => row.name === queue && row.connections.includes(connection))
        || null;
}

/** The rows grouped by their primary connection, in the order of the rows. */
export function connectionSections(rows) {
    const sections = [];

    rows.forEach(row => {
        let section = sections.find(entry => entry.connection === row.connection);

        if (!section) {
            section = {connection: row.connection, aliases: [], rows: []};
            sections.push(section);
        }

        section.rows.push(row);
        row.connections.forEach(alias => {
            if (!section.aliases.includes(alias)) section.aliases.push(alias);
        });
    });

    return sections;
}

/**
 * The toolbar subtitle: "4 on database · 10,178 ready · 1 over its limit",
 * or "4 queues on 2 connections · …" with several connections. The ready
 * sum is left out when a row's ready count is unknown, and the queues whose
 * backlog is unknown are counted apart ("1 backlog unknown"). Such a queue
 * may be over its limit too, so then the over-limit count is a lower bound:
 * "at least 1 over its limit", and left out when it is 0 (never a definite
 * "0 over its limit").
 */
export function subtitleText(rows) {
    if (!rows.length) return null;

    const sections = connectionSections(rows);
    const parts = [sections.length === 1
        ? `${rows.length} on ${sections[0].connection}`
        : `${rows.length} queues on ${sections.length} connections`];

    if (rows.every(row => row.ready !== null)) {
        parts.push(`${rows.reduce((sum, row) => sum + row.ready, 0).toLocaleString()} ready`);
    }

    const over = rows.filter(row => row.overLimit).length;
    const unknown = rows.filter(row => row.backlogUnknown).length;

    if (!unknown) {
        parts.push(`${over.toLocaleString()} over its limit`);
    } else if (over > 0) {
        parts.push(`at least ${over.toLocaleString()} over its limit`);
    }

    if (unknown) parts.push(`${unknown.toLocaleString()} backlog unknown`);

    return parts.join(' · ');
}

/**
 * The state of a queue: { kind, label, detail }, in the state order of the
 * sidebar (by design). The pause comes from the row's causes (core
 * support/pause.js):
 *
 * 1. paused, when a definite cause holds: "Paused", "Paused (connection)",
 *    "Paused (all queues)", "Paused (queue:pause)", "Paused (queue:pause
 *    --all)" or several causes in one label; the detail is "by <issued_by>
 *    · <relative>" of Orrery's pause, and the notes (a masked own cause,
 *    an unread Laravel state);
 * 2. unknown: "Backlog unknown" (the backlog read failed), or "Possibly
 *    paused" with "backlog unknown" in the detail while a possible cause
 *    holds; the detail adds the pause notes (the unread Laravel state);
 * 3. over: "Over limit", "since HH:MM <zone>" or "at least since HH:MM <zone>" (censored),
 *    and "possibly paused" or "pause state unknown" when the pause is not
 *    known off: a possible pause never hides an over-limit queue;
 * 4. unknown: "Possibly paused" or "Pause state unknown" (never "Running");
 * 5. unknown: "Workers unknown" without a worker count; idle: "No workers";
 *    running: "Running".
 *
 * A missing row is "Unknown". pauseHint() gives what Orrery's actions
 * cannot lift and what is not known.
 */
export function queueState(row, now) {
    if (!row) return {kind: 'unknown', label: 'Unknown', detail: null};

    const pause = row.pause || pauseState(null);

    if (pause.paused) {
        const cause = issuedCause(pause);
        const actor = cause && !cause.possible ? cause : null;
        const ago = actor && actor.issuedAt !== null ? relativeTime(actor.issuedAt, now) : null;
        const detail = [actor && actor.issuedBy ? `by ${actor.issuedBy}` : null, ago, ...pauseNotes(pause)].filter(Boolean).join(' · ') || null;

        return {kind: 'paused', label: pauseLabel(pause), detail};
    }

    if (row.backlogUnknown) {
        // A possible pause is never hidden behind the unknown backlog (rollback-mode SQLite: neither is read).
        const notes = pause.knownOff ? [] : pauseNotes(pause);

        if (pause.possible.length) {
            const others = notes.filter(note => note !== 'pause state unknown');

            return {kind: 'unknown', label: pauseLabel(pause), detail: ['backlog unknown', ...others].join(' · ')};
        }

        return {kind: 'unknown', label: 'Backlog unknown', detail: notes.join(' · ') || null};
    }

    const notes = pause.knownOff ? [] : [pause.possible.length ? 'possibly paused' : null, ...pauseNotes(pause)].filter(Boolean);

    if (row.overLimit) {
        const detail = [overDetail(row, now), ...notes].filter(Boolean).join(' · ');

        return {kind: 'over', label: 'Over limit', detail: detail || null};
    }

    if (!pause.knownOff) {
        const others = pauseNotes(pause).filter(note => note !== 'pause state unknown');

        return {kind: 'unknown', label: pause.possible.length ? pauseLabel(pause) : 'Pause state unknown', detail: others.join(' · ') || null};
    }

    if (row.workers === null) {
        return {kind: 'unknown', label: 'Workers unknown', detail: null};
    }

    if (row.workers === 0) {
        return {kind: 'idle', label: 'No workers', detail: null};
    }

    if (row.quietWorkers === row.workers) {
        return {kind: 'quiet', label: 'Workers quiet', detail: 'no heartbeat from its workers lately'};
    }

    // On an uninstrumented connection a busy worker and a dead one look the same.
    if (row.unknownStateWorkers > 0 && (row.quietWorkers ?? 0) + row.unknownStateWorkers === row.workers) {
        return {kind: 'unknown', label: 'Worker state unknown', detail: 'no recent heartbeat, and the running jobs are not mirrored'};
    }

    return {kind: 'running', label: 'Running', detail: null};
}

/** Whether a definite cause pauses the queue. */
export function isPaused(row) {
    return !!(row && row.pause && row.pause.paused);
}

/**
 * The hint under the inspector's actions: "Resume with php artisan
 * queue:continue --all" for Laravel's global pause (Orrery's actions cannot
 * lift it), the notes of a masked own cause or an unread Laravel state, and
 * $cacheNote, the `queue_pause_cache_local` text of /api/health (the server
 * words the `file` and `array` cases); null without
 * one.
 */
export function pauseHint(row, cacheNote = null) {
    if (!row) return null;

    const pause = row.pause || pauseState(null);
    const parts = [
        ...pauseCommands(pause).map(command => `Resume with ${command}`),
        ...pauseNotes(pause).filter(note => note !== 'pause state unknown' && note !== 'possibly paused (queue:pause)'),
        typeof cacheNote === 'string' && cacheNote !== '' ? cacheNote : null,
    ].filter(Boolean);

    return parts.length ? parts.join(' · ') : null;
}

/** Where the backlog chart starts its paused tint: Orrery's pause time, else the window start; null when not paused. */
export function pausedFrom(row, window) {
    if (!isPaused(row) || !window) return null;

    const cause = issuedCause(row.pause);

    return cause && !cause.possible && cause.issuedAt !== null ? cause.issuedAt : window.from;
}

/**
 * The title of the backlog chart's paused tint: "Paused from 09:14 GMT+3" when
 * Orrery issued the pause (its time is known; with its date when it is of an
 * earlier day than the window's end); else (Laravel's queue:pause,
 * a possible cause) the pause holds across the window and its start is
 * unknown. Null when the queue is not paused or there is no window.
 */
export function pausedTitle(row, window) {
    if (!isPaused(row) || !window) return null;

    const cause = issuedCause(row.pause);

    return cause && !cause.possible && cause.issuedAt !== null
        ? `Paused from ${formatDayMinuteZoned(cause.issuedAt, window.to)}`
        : 'Paused across this window; the start is unknown';
}

/** The retention keys of "Kept for", in order, with their labels. */
const KEPT_KEYS = [
    ['completed', 'Completed'],
    ['silenced', 'Silenced'],
    ['failed', 'Failed'],
    ['unknown', 'Unknown'],
    ['monitored', 'Monitored'],
];

/** Where an effective value comes from (`rule` of GET /api/settings/retention). */
const KEPT_SOURCES = {
    'connection+queue': 'set for this queue on this connection',
    queue: 'set for this queue',
    connection: 'set for this connection',
    global: 'the default',
};

/**
 * A retention in words: 0 is "until the next tick" (the next tick trims it),
 * whole days and hours in their unit, else minutes; "—" for an invalid or
 * unknown value (never 0).
 */
export function retentionText(minutes) {
    const value = toSeconds(minutes);

    if (value === null || value < 0) return '—';
    if (value === 0) return 'until the next tick';
    if (value % 1440 === 0) return `${value / 1440} d`;
    if (value % 60 === 0 && value >= 120) return `${value / 60} h`;

    return `${value} min`;
}

/**
 * "Kept for" of the selected queue from `GET /api/settings/retention`:
 * the effective entry of the row's queue on one of its
 * connections, as [{ key, label, text, source }]. Null when the answer has
 * no entry for the queue (unknown, never the defaults: the row may have
 * overrides).
 */
export function keptFor(settings, row) {
    const effective = settings && Array.isArray(settings.effective) ? settings.effective : null;

    if (!effective || !row) return null;

    const connections = Array.isArray(row.connections) && row.connections.length ? row.connections : [row.connection];
    const entry = effective.find(candidate => candidate && candidate.queue === row.name && connections.includes(candidate.connection));

    if (!entry || !entry.values || typeof entry.values !== 'object') return null;

    return KEPT_KEYS.map(([key, label]) => {
        const value = entry.values[key] || null;

        return {
            key,
            label,
            text: retentionText(value ? value.minutes : null),
            source: value && KEPT_SOURCES[value.rule] ? KEPT_SOURCES[value.rule] : 'an unknown rule',
        };
    });
}

/**
 * The links to a tab of the Jobs list filtered by the queue: one per
 * alias of the row, [{ key, label, to }]. `orrery_jobs.connection` keeps the
 * alias that pushed the job, and a workload row merges every alias of one
 * jobs table (`connections`), so one link with the primary alias would miss
 * the jobs of the others. The label names the alias only when there are
 * several. Empty without a row.
 */
export function queueJobsLinks(row, status) {
    if (!row) return [];

    const aliases = Array.isArray(row.connections) && row.connections.length ? row.connections : [row.connection];
    const word = status === 'failed' ? 'Failed jobs' : 'Pending jobs';

    return aliases.map(alias => ({
        key: alias,
        label: aliases.length > 1 ? `${word} on ${alias}` : word,
        to: {path: '/jobs', query: {status, connection: alias, queue: row.name}},
    }));
}

/**
 * Whether a connection section has an Orrery pause of the connection: then
 * "Continue all…" is offered. It sends a connection continue, which lifts
 * only that pause, so a pause of every queue (Orrery's or Laravel's) does
 * not offer it: the Overview continues a global pause.
 */
export function sectionPausedBroadly(section) {
    return section.rows.some(row => row.pause && row.pause.definite.some(cause => cause.source === 'orrery' && cause.scope === 'connection'));
}

function overDetail(row, now) {
    if (row.overLimitSince === null) return null;

    return `${row.overLimitCensored ? 'at least since' : 'since'} ${formatDayMinuteZoned(row.overLimitSince, now ?? undefined)}`;
}

/** "limit 60 s", or "No limit" when the limit is 0. */
export function limitText(threshold) {
    return threshold > 0 ? `limit ${formatLimit(threshold)}` : 'No limit';
}

/** The oldest wait in words ("2 min 14 s"), or "—" when nothing is ready or the age is unknown. */
export function waitText(row) {
    return row.oldestAge === null ? '—' : formatDuration(row.oldestAge);
}

/** A rate per minute: "615", "4.2", "0"; "—" when unknown. */
export function rateText(value) {
    if (value === null || value === undefined) return '—';

    const rounded = value >= 10 ? Math.round(value) : Math.round(value * 10) / 10;

    return rounded.toLocaleString();
}

/** "615 / 488" for the table: the observed in and out rates. */
export function inOutText(row) {
    return `${rateText(row.inPerMin)} / ${rateText(row.outPerMin)}`;
}

/**
 * The in and out bars of the inspector, on one scale (the larger rate):
 * { in, out, excess, diff } as fractions of the track. `excess` is the part
 * that in has over out (the dashed end of the out bar), and `diff` the
 * difference per minute when in > out, else null. Null rates give null bars.
 */
export function inOutBars(inPerMin, outPerMin) {
    const scale = Math.max(inPerMin || 0, outPerMin || 0);
    const fraction = value => (value === null ? null : (scale > 0 ? value / scale : 0));
    // A difference below the display precision (0.05/min) is no difference.
    const diff = inPerMin !== null && outPerMin !== null && inPerMin - outPerMin >= MIN_DIFF ? inPerMin - outPerMin : null;

    return {
        in: fraction(inPerMin),
        out: fraction(outPerMin),
        excess: diff !== null && scale > 0 ? diff / scale : 0,
        diff,
    };
}

/** The smallest in − out difference that the inspector reports. */
export const MIN_DIFF = 0.05;

/** "+127/min more in than out". */
export function diffText(diff) {
    return diff === null ? null : `+${rateText(diff)}/min more in than out`;
}

/**
 * The live workers that serve a row: a worker on one of the row's aliases
 * whose subscribed queues include the queue.
 */
export function workersFor(workers, row) {
    return (Array.isArray(workers) ? workers : [])
        .filter(worker => worker
            && row.connections.includes(worker.connection)
            && subscribedQueues(worker).includes(row.name))
        .sort((a, b) => String(a.name).localeCompare(String(b.name)));
}

/** The subscribed queues of a worker row (the list, else the comma-separated `queue`). */
export function subscribedQueues(worker) {
    if (Array.isArray(worker.subscribed_queues)) return worker.subscribed_queues;

    return typeof worker.queue === 'string' ? worker.queue.split(',').map(entry => entry.trim()).filter(Boolean) : [];
}

/** The live workers of a connection section: "14 workers on 3 hosts". */
export function sectionWorkersText(workers, aliases) {
    if (!Array.isArray(workers)) return 'workers unknown';

    const own = workers.filter(worker => worker && aliases.includes(worker.connection));
    const hosts = new Set(own.map(worker => worker.host)).size;

    return `${own.length} ${own.length === 1 ? 'worker' : 'workers'} on ${hosts} ${hosts === 1 ? 'host' : 'hosts'}`;
}

/**
 * The current job of a worker: { name, fullName, runtime } or null. Null is
 * unknown (the connector swap is off, or no live lease was observed), never
 * idle: the screen shows "—".
 */
export function currentJob(worker, now) {
    const job = worker && worker.current_job;

    if (!job || typeof job.name !== 'string') return null;

    const startedAt = num(job.started_at);

    return {
        name: shortName(job.name),
        fullName: job.name,
        runtime: startedAt === null ? '—' : runtimeText(Math.max(0, now - startedAt)),
    };
}

/** "1.2 s" under a minute, else "2 min 14 s". */
export function runtimeText(seconds) {
    if (seconds === null || seconds < 0) return '—';

    return seconds < 60 ? `${(Math.floor(seconds * 10) / 10).toFixed(1)} s` : formatDuration(seconds);
}

/** The part after the last backslash: "App\Jobs\Sync" → "Sync". */
export function shortName(name) {
    if (typeof name !== 'string' || name === '') return '—';

    const parts = name.split('\\');

    return parts[parts.length - 1] || name;
}

/**
 * The workers header: "4 serve emails · heartbeat 12 s ago" (the freshest
 * heartbeat at `now`, shell/data.js heartbeatAge()), or "No worker serves emails".
 */
export function workersHeader(workers, queue, now = nowSeconds()) {
    if (!workers.length) return `No worker serves ${queue}`;

    const ages = workers.map(worker => heartbeatAge(worker, now)).filter(value => value !== null);
    const heartbeat = ages.length ? ` · heartbeat ${Math.round(Math.min(...ages))} s ago` : '';

    return `${workers.length} serve ${queue}${heartbeat}`;
}

/**
 * The buckets of a history response with numbers coerced: { at, ready,
 * partial }. A null or non-numeric `ready` stays null (a gap), and a
 * bucket without a valid `at` is dropped (absent, so unknown).
 */
export function historyBuckets(data) {
    return (data && Array.isArray(data.buckets) ? data.buckets : [])
        .map(bucket => ({at: num(bucket && bucket.at), ready: num(bucket && bucket.ready), partial: !!(bucket && bucket.partial)}))
        .filter(bucket => bucket.at !== null);
}

/**
 * The state of a queue's backlog chart from its history read ({window,
 * buckets, error}): `loading` before the first answer, `failed` when no
 * read succeeded, `stale` when the last read failed and the chart shows an
 * older one, else `ok`.
 */
export function backlogStatus(history) {
    if (!history) return 'loading';
    if (!history.window) return history.error ? 'failed' : 'loading';

    return history.error ? 'stale' : 'ok';
}

/**
 * The history window of a response, { from, to, retentionFrom }, or null.
 */
export function historyWindow(data) {
    const from = num(data && data.from);
    const to = num(data && data.to);

    if (from === null || to === null || to <= from) return null;

    return {from, to, retentionFrom: num(data.retention_from)};
}

/** The markers of a `GET /api/markers` response with `at` coerced, oldest first. */
export function normalizeMarkers(data) {
    return (data && Array.isArray(data.markers) ? data.markers : [])
        .map(marker => ({kind: marker && marker.kind, at: num(marker && marker.at), label: marker && typeof marker.label === 'string' ? marker.label : null}))
        .filter(marker => marker.at !== null && (marker.kind === 'restart' || marker.kind === 'deploy'))
        .sort((a, b) => a.at - b.at);
}

/** The name of a marker: "Restart requested", or "Deploy" plus its label. */
export function markerName(marker) {
    if (!marker) return '';

    return marker.kind === 'deploy'
        ? ['Deploy', marker.label].filter(value => typeof value === 'string' && value !== '').join(' ')
        : 'Restart requested';
}

/** The markers inside the window [from, to). */
export function markersIn(markers, window) {
    if (!window) return [];

    return markers.filter(marker => marker.at >= window.from && marker.at < window.to);
}

/**
 * The growth since the latest marker in the window, computed from the
 * series: { marker, at, then, now, change }, or null when no marker lies in
 * the window, or the marker's bucket or every later bucket is unknown.
 * `then` is the ready backlog in the marker's bucket, `now` the latest known
 * bucket.
 */
export function growthSince(buckets, markers, window) {
    const inWindow = markersIn(markers, window);

    if (!inWindow.length) return null;

    const marker = inWindow[inWindow.length - 1];
    const at = Math.floor(marker.at / 60) * 60;
    const known = buckets.filter(bucket => bucket.ready !== null).sort((a, b) => a.at - b.at);
    const start = known.find(bucket => bucket.at === at);
    const latest = known[known.length - 1];

    if (!start || !latest || latest.at < at) return null;

    return {marker, at: marker.at, then: start.ready, now: latest.ready, change: latest.ready - start.ready};
}

/** "+7,587 since 09:14 GMT+3" or "−12 since 09:14 GMT+3". */
export function growthText(growth) {
    if (!growth) return null;

    const sign = growth.change >= 0 ? '+' : '−';

    return `${sign}${Math.abs(growth.change).toLocaleString()} since ${formatHourMinuteZoned(growth.at)}`;
}

/** The live failures window: `to` is now, `from` is now − 1 h. */
export function failureWindow(now) {
    const to = Math.floor(now);

    return {from: to - FAILURE_WINDOW_SECONDS, to};
}

/**
 * The failure-group scopes of a selection: one `connection:queue` per alias
 * of the selected row (a failed row keeps the alias that ran it), or one
 * scope without a queue (all queues) when no row is selected.
 */
export function failureScopes(row) {
    return row ? row.connections.map(alias => `${alias}:${row.name}`) : [null];
}

/**
 * The failure rows from the responses of failureScopes(): each group with
 * its `scopeQueue` (the queue scope that a retry of it must post) and
 * `atLeast` (its count is a lower bound: groupCountAtLeast()), newest last
 * failure first. { groups, incomplete, retentionFrom }: `incomplete` when a scan was
 * truncated or a page was full, so more groups may exist; `retentionFrom`
 * the latest retention horizon of the responses (older failures are gone).
 */
export function mergeFailures(responses) {
    const groups = [];
    let incomplete = false;
    let retentionFrom = null;

    responses.forEach(({scopeQueue, data}) => {
        const list = data && Array.isArray(data.groups) ? data.groups : [];
        const horizon = num(data && data.retention_from);

        if (horizon !== null && (retentionFrom === null || horizon > retentionFrom)) retentionFrom = horizon;

        incomplete = incomplete || !!(data && data.truncated === true) || list.length >= GROUP_PAGE_SIZE;

        list.forEach(group => {
            if (!group || typeof group.key !== 'string') return;

            groups.push({
                ...group,
                count: num(group.count),
                last_failed_at: num(group.last_failed_at),
                first_failed_at: num(group.first_failed_at),
                scopeQueue,
                atLeast: groupCountAtLeast(group),
                retentionFrom: num(data && data.retention_from),
                bucketSeconds: num(data && data.bucket_seconds) || 60,
                rowKey: `${scopeQueue || '*'}|${group.key}`,
            });
        });
    });

    groups.sort((a, b) => ((b.last_failed_at || 0) - (a.last_failed_at || 0)) || (a.rowKey < b.rowKey ? -1 : 1));

    return {groups, incomplete, retentionFrom};
}

/** "Retry 27…", or "Retry ≥ 1,027…" for a lower bound (operationLabel()): the bulk run freezes the whole group. */
export function retryLabel(group) {
    return `${operationLabel('Retry', group.count, group.atLeast)}…`;
}

/** "TransportException ×27", or "E × ≥5,000" for a lower-bound count (timesText()). */
export function groupTitle(group) {
    const count = timesText(group.count, group.atLeast);

    return `${shortName(group.exception_class)}${count ? ` ${count}` : ''}`;
}

/**
 * The line under the failure rows: "No other failures on emails in the past
 * hour" when every group is listed, "No failures on …" when there is none,
 * and null when more groups may exist.
 */
export function failuresFooter(groups, incomplete, label, window = null, retentionFrom = null) {
    if (incomplete || groups.length > FAILURE_ROWS) return null;

    const other = groups.length ? 'other ' : '';

    // Failures older than the retention horizon are gone: the answer covers only the kept part.
    if (window && retentionFrom !== null && retentionFrom > window.from) {
        return `No ${other}failures on ${label} since ${formatHourMinuteZoned(retentionFrom)} (older failures are not kept)`;
    }

    return `No ${other}failures on ${label} in the past hour`;
}

/**
 * The control requests of a confirmed action (the frozen selection): one
 * `POST /api/control` body per alias. A queue-scoped pause, continue or
 * terminate sends one signal per alias in `connections` (aliases), and
 * a connection-scoped one per alias of the section.
 */
export function controlBodies(selection) {
    return selection.connections.map(connection => (selection.scope === 'queue'
        ? {action: selection.action, scope: 'queue', connection, queue: selection.queue}
        : {action: selection.action, scope: 'connection', connection}));
}

/**
 * The notice after a control write, from the answers of its signals: each
 * outcome is { body, failed, status, data } (data the error body of a failed
 * one). A 503 `record_failed` is no failed signal: its change happened, so
 * it gives a warning (support/pause.js recordFailedWarning()). A continue is
 * a success only when every answer says that nothing else pauses its target
 * (continueWarning()).
 */
export function controlNotice(selection, outcomes) {
    const title = controlTitle(selection);
    const total = outcomes.length;
    const failed = outcomes.filter(outcome => outcome.failed && !isRecordFailed(outcome));
    const target = body => (body.scope === 'queue' ? `${body.connection}:${body.queue}` : body.connection);

    if (failed.length) {
        const note = recordFailedNote(outcomes, target);

        return {
            tone: failed.length === total ? 'danger' : 'warning',
            title: `${title}: ${failed.length} of ${total} ${total === 1 ? 'signal' : 'signals'} failed.`,
            detail: failed.map(outcome => `${outcome.body.connection}: ${outcome.status ? `HTTP ${outcome.status}` : 'no response'}`)
                .concat(note === null ? [] : [note]).join(' · '),
        };
    }

    const recorded = recordFailedWarning(title, outcomes, target);

    if (recorded) return recorded;

    if (selection.action === 'continue') {
        const warning = continueWarning(title, outcomes, target);

        if (warning) return warning;
    }

    const targets = outcomes.map(outcome => target(outcome.body)).join(', ');

    return {tone: 'success', title: `${title}: sent.`, detail: `${total} ${total === 1 ? 'signal' : 'signals'}: ${targets}.`};
}

/** The title of a control confirmation, naming its target. */
export function controlTitle(selection) {
    if (selection.scope === 'queue') {
        if (selection.action === 'terminate') return `Terminate ${selection.queue} workers`;

        return `${selection.action === 'pause' ? 'Pause' : 'Continue'} ${selection.queue}`;
    }

    if (selection.action === 'terminate') return `Terminate all workers on ${selection.label}`;
    if (selection.action === 'continue') return `Continue all queues on ${selection.label}`;

    return `Pause all queues on ${selection.label}`;
}

/**
 * The message of a control confirmation: a pause or continue
 * changes exactly its scope. A connection pause or continue leaves the
 * pauses of single queues as they are, and a queue continue leaves a
 * broader pause in force. A queue pause is Laravel's own (queue:pause), so
 * it needs a cache store that every worker shares (`cacheNote`, the health hint, frozen
 * with the selection).
 */
export function controlMessage(selection) {
    if (selection.action === 'terminate') {
        return selection.scope === 'queue'
            ? `${TERMINATE_TEXT} Workers that also serve other queues stop too.`
            : TERMINATE_TEXT;
    }

    if (selection.scope !== 'queue') {
        return selection.action === 'continue'
            ? `Removes the pause of ${selection.label}. Pauses of its single queues stay.`
            : `Pauses every queue on ${selection.label}. Pauses of its single queues stay, so a later Continue all leaves them paused.`;
    }

    const pause = selection.pause || null;

    if (selection.action === 'continue') {
        const broader = broaderCauses(pause);

        if (broader.length) {
            return `Lifts the queue pause of ${selection.queue}. It stays paused by ${causesText(broader)}.`;
        }

        if (!pause || pause.pausedUnknown || pause.queueCauseUnknown || pause.nativeUnknown) {
            return `Lifts the pause of ${selection.queue}. Whether another pause also holds ${selection.queue} is unknown, so workers may still not take its jobs.`;
        }

        return `Workers take jobs from ${selection.queue} again.`;
    }

    return `Workers stop taking jobs from ${selection.queue}. Jobs keep arriving and wait until you continue it.`
        + (typeof selection.cacheNote === 'string' && selection.cacheNote !== '' ? ` ${selection.cacheNote}` : '');
}

/** The confirm button label of a control action. */
export function controlConfirmLabel(selection) {
    return {pause: 'Pause', continue: 'Continue', terminate: 'Terminate'}[selection.action] || 'Confirm';
}

// ---------------------------------------------------------------------------
// Clear waiting jobs
// ---------------------------------------------------------------------------

/**
 * A request about one queue: the queue connection and the queue name travel
 * as values (a JSON body, or query parameters), never as path segments. A
 * browser resolves `.` and `..` segments of a path, also encoded ones, so a
 * name like `x/../default` in a path would reach another queue.
 * `{url, body}` for `waiting` and `clear` (POST), `{url, params}` for
 * `history` (GET).
 */
export function queueRequest(action, connection, queue, extra = {}) {
    const target = {connection: String(connection), queue: String(queue)};

    if (action === 'history') return {url: '/api/queues/history', params: {...target, ...extra}};

    return {url: `/api/queues/${action === 'clear' ? 'clear' : 'waiting'}`, body: target};
}

function wholeCount(value) {
    return Number.isInteger(value) && value >= 0 ? value : null;
}

/**
 * The count of the clear's sheet, from `POST …/waiting`: "N waiting
 * (including M delayed; K in batches, whose batches will not finish)". A
 * count that could not be read (null: a failed or refused read) is
 * "unknown", never 0; an unknown batch part says so.
 */
export function clearCountText(count) {
    const waiting = wholeCount(count && count.waiting);
    const delayed = wholeCount(count && count.delayed);

    if (waiting === null || delayed === null) return 'The number of waiting jobs is unknown.';

    const inBatches = wholeCount(count.in_batches);
    let batches;

    if (inBatches === null) {
        batches = 'how many are in batches is unknown';
    } else if (count.in_batches_at_least === true) {
        // Some waiting jobs have no row in Orrery yet: they may be in batches too.
        batches = inBatches === 0
            ? 'none known in batches; some jobs are not in Orrery yet'
            : `at least ${formatCount(inBatches)} in batches, whose batches will not finish`;
    } else {
        batches = inBatches === 0 ? 'none known in batches' : `${formatCount(inBatches)} in batches, whose batches will not finish`;
    }

    return `${formatCount(waiting)} waiting (including ${formatCount(delayed)} delayed; ${batches}).`;
}

/**
 * The sheet of "Clear waiting jobs…": the queue and its aliases, and the
 * count frozen when the sheet opens (null: unknown). The final numbers come
 * from the answer.
 */
export function clearSheet(row, count) {
    const aliases = Array.isArray(row.connections) && row.connections.length ? row.connections : [row.connection];

    return {
        title: `Clear waiting jobs of ${row.name}?`,
        message: `${clearCountText(count)} Every job of ${row.connection}:${row.name} that no worker has reserved is deleted, with its rows in Orrery`
            + (aliases.length > 1 ? ` (also those of ${aliases.filter(alias => alias !== row.connection).join(', ')}, which share its jobs table)` : '')
            + '. Reserved jobs keep running. This cannot be undone.',
        confirmLabel: 'Clear waiting jobs',
        tone: 'danger',
        selection: {kind: 'clear', connection: row.connection, queue: row.name, aliases: aliases.slice()},
    };
}

/** The answer of a clear (200), as a notice `{tone, title, detail}`. */
export function clearOutcome(selection, data) {
    const deleted = wholeCount(data && data.deleted);

    if (!data || deleted === null) return {tone: 'warning', title: `Clear of ${selection.queue}: outcome unknown`, detail: codeText('write_failed')};

    const notes = [];

    if (data.mirror_failed === true) notes.push("Some of Orrery's rows could not be deleted; reconciliation marks them unknown later.");
    if (wholeCount(data.undecodable) > 0) notes.push(`${formatCount(data.undecodable)} payload(s) could not be decoded, so their unresolved rows in Orrery stay.`);
    if (data.reason === 'time_budget') notes.push('It stopped at its time limit: waiting jobs remain. Clear again to go on.');
    if (data.reason === 'probe_failed') notes.push('Orrery could not check its unresolved rows, so it stopped before the next batch: those jobs are still queued. Clear again.');
    if (data.reason === 'write_failed') notes.push('A later batch could not be deleted, so it stopped: the jobs counted here are gone, the others are still queued. Clear again.');

    const title = `Cleared ${formatCount(deleted)} waiting ${deleted === 1 ? 'job' : 'jobs'} of ${selection.queue}`;
    const tone = data.complete === true && data.mirror_failed !== true ? 'success' : 'warning';

    return {tone, title, detail: notes.length ? notes.join(' ') : 'Reserved jobs were kept.'};
}

/**
 * A clear that did not answer 200: a refusal (409 `in_transaction`,
 * `not_database_queue`, 422 `invalid`, 404) changed nothing; a 403 is left to the global alert
 * (null); any other failure has an unknown outcome.
 */
export function clearFailure(selection, error) {
    const status = error && error.response ? error.response.status : null;
    const body = error && error.response && error.response.data && typeof error.response.data === 'object' ? error.response.data : {};

    if (status === 403) return null;
    if ((status === 404 || status === 409 || status === 422) && typeof body.error === 'string') return {tone: 'danger', title: `${selection.queue} was not cleared`, detail: codeText(body.error)};

    return {tone: 'warning', title: `Clear of ${selection.queue}: outcome unknown`, detail: codeText('write_failed')};
}
