/**
 * The data of the sidebar: pure functions from the API responses to what
 * the sidebar shows, so the shell's poll stays thin.
 *
 * Numbers can arrive as strings (the older endpoints return decimal columns
 * as strings on MySQL and Postgres), so every numeric read goes through
 * toSeconds(): a finite number or a numeric string, else null.
 */

import {pauseState} from '../support/pause.js';
import {formatClockZoned, formatCompactCount, formatCount, formatDuration, formatHourMinuteZoned, relativeTime, toSeconds} from '../support/time.js';

/** A failure group counts for attention when its last failure is this recent. */
export const ATTENTION_FAILURE_SECONDS = 900;

/** A tick older than this is stale: the sidebar footer warns. */
export const STALE_TICK_SECONDS = 180;

/**
 * The footer reports a degradation window that started or ended this
 * recently, or an open window whose last error is this recent. An open
 * window of a process that died never ends ("end unknown"), so an old open
 * window without recent errors does not keep the footer red.
 */
export const RECENT_DEGRADATION_SECONDS = 900;

/**
 * A retention state older than this is not current: the footer shows
 * retention as unknown. Three tick intervals (a review
 * found 300 s too short).
 */
export const RETENTION_STALE_SECONDS = 180;

/** The footer warns "Retention is behind" when the lag of a due row is at least this. */
export const RETENTION_BEHIND_SECONDS = 600;

/** The footer warns "Counts are behind" when the per-minute counts lag more than this. */
export const SAMPLES_BEHIND_SECONDS = 600;

/**
 * The workload rows as queue entries of the sidebar.
 *
 * A row lists every cause that pauses the queue in `pauses`
 * (support/pause.js pauseState()). The state is decided in this order:
 *
 * 1. 'paused': a definite cause holds, even when another part is unknown;
 * 2. 'unknown': the backlog could not be read (`backlog_unknown`);
 * 3. 'over': the queue is over its limit. It is a definite attention item
 *    only when its pause state is known off (attentionCount()); a possible
 *    pause (an Orrery queue pause whose Laravel flag is unknown) never
 *    hides it;
 * 4. 'unknown': the pause state is not known off;
 * 5. 'ready'.
 *
 * `pause` is the pauseState() of the row. `label` is the queue name (the
 * sidebar's section heading names the connections); `distinctLabel` adds
 * the connection for a name on several connections, for a list without
 * sections (the Overview cards). `connections` is the row's `connections`,
 * else `[connection]`.
 */
export function sidebarQueues(workload) {
    const rows = Array.isArray(workload) ? workload : [];
    const names = rows.map(row => row && row.name);

    return rows
        .filter(row => row && typeof row.name === 'string' && typeof row.connection === 'string')
        .map(row => {
            const duplicate = names.filter(name => name === row.name).length > 1;
            const pause = pauseState(row);
            const backlogUnknown = row.backlog_unknown === true;
            const overLimit = row.over_limit === true;

            let state = 'ready';

            if (pause.paused) {
                state = 'paused';
            } else if (backlogUnknown) {
                state = 'unknown';
            } else if (overLimit) {
                state = 'over';
            } else if (!pause.knownOff) {
                state = 'unknown';
            }

            const connections = Array.isArray(row.connections) && row.connections.length
                ? row.connections.filter(name => typeof name === 'string')
                : [row.connection];

            return {
                key: `${row.connection}:${row.name}`,
                connection: row.connection,
                connections: connections.length ? connections : [row.connection],
                queue: row.name,
                label: row.name,
                distinctLabel: duplicate ? `${row.name} (${row.connection})` : row.name,
                ready: toSeconds(row.length),
                oldestAge: toSeconds(row.oldest_age),
                overLimit,
                pause,
                backlogUnknown,
                state,
            };
        });
}

/**
 * The sidebar's queue sections: one per set of `connections` (the
 * connections that share one jobs table), in the order of first appearance
 * in the workload answer. Each is `{key, connections, heading, queues}`,
 * with the heading "Queues · database, database-long" and the
 * sidebarQueues() entries of the section.
 */
export function sidebarQueueGroups(workload) {
    const groups = new Map();

    for (const queue of sidebarQueues(workload)) {
        const key = queue.connections.join(',');

        if (!groups.has(key)) {
            groups.set(key, {key, connections: queue.connections, heading: `Queues · ${queue.connections.join(', ')}`, queues: []});
        }

        groups.get(key).queues.push(queue);
    }

    return [...groups.values()];
}

/**
 * The failure groups of a `GET /api/failures/groups` response, or null
 * when the response has no group list. The list key is `groups`.
 */
export function failureGroups(data) {
    return data && Array.isArray(data.groups) ? data.groups : null;
}

/** The page size of the failure-group list: a full page may have a next page. */
export const GROUP_PAGE_SIZE = 50;

/**
 * Whether the failure groups of the response may be incomplete: the scan was
 * truncated, or the first page is full, so a next page may exist.
 */
export function groupsIncomplete(data) {
    const groups = failureGroups(data);

    return !!groups && (data.truncated === true || groups.length >= GROUP_PAGE_SIZE);
}

/** Whether a failure group's last failure is recent enough to need attention. */
function recentGroup(group, now) {
    const last = group ? toSeconds(group.last_failed_at) : null;

    return last !== null && last >= now - ATTENTION_FAILURE_SECONDS;
}

/**
 * Whether the group page may hide a group with a recent failure. The two
 * reasons a page is incomplete differ:
 * - a full page is newest first, so it hides a recent group only when its
 *   last group is recent;
 * - a truncated scan (the scan merges a capped keyed scan with an unkeyed
 *   fallback, so the page order proves nothing) hides one unless it read
 *   back past the attention window: `scanned_from` older than now − 900.
 */
function groupsMayHideRecent(data, groups, now) {
    if (groups.length >= GROUP_PAGE_SIZE && recentGroup(groups[groups.length - 1], now)) return true;

    if (data.truncated === true) {
        const from = toSeconds(data.scanned_from);

        return from === null || from >= now - ATTENTION_FAILURE_SECONDS;
    }

    return false;
}

/**
 * The attention count of the shell and the Overview:
 * { count, atLeast, possible, unknown }.
 *
 * - `count` counts the definite items: the queues over their limit whose
 *   pause state is known off, and the failure groups with a failure in the
 *   last 15 minutes;
 * - `possible` counts the queues over their limit whose pause state is
 *   unknown or only possible;
 * - `unknown` lists the parts that are not known: 'failure groups' (no group
 *   list), 'pause state' (possible > 0) and 'queue state' (an unknown backlog);
 * - `atLeast`: the definite count is a lower bound, because a part is
 *   unknown, a queue may need attention, or the group page may hide a recent
 *   group (groupsMayHideRecent()).
 */
export function attentionCount(queues, groupsData, now) {
    const list = Array.isArray(queues) ? queues : [];
    const over = list.filter(queue => queue && queue.state === 'over');
    const definite = over.filter(queue => queue.pause && queue.pause.knownOff).length;
    const possible = over.length - definite;
    const groups = failureGroups(groupsData);
    const recent = groups ? groups.filter(group => recentGroup(group, now)).length : 0;
    const pageMayHideRecent = !!groups && groupsMayHideRecent(groupsData, groups, now);
    const unknown = [
        groups ? null : 'failure groups',
        possible > 0 ? 'pause state' : null,
        list.some(queue => queue && queue.backlogUnknown) ? 'queue state' : null,
    ].filter(Boolean);

    return {
        count: definite + recent,
        atLeast: pageMayHideRecent || possible > 0 || unknown.length > 0,
        possible,
        unknown,
    };
}

/**
 * The attention count of the shell from its live data ({ workload,
 * workloadError, groupsHour, groupsHourError }, shell/store.js `live`), or
 * null before the first workload read. A failed groups read gives no group
 * list. While the workload read fails, the queue state is unknown: the
 * count is "at least", and the stale over-limit queues are only possible.
 * A workload that never loaded counts no queue.
 */
export function shellAttention(state, now) {
    if (!state || (state.workload === null && !state.workloadError)) return null;

    const queues = sidebarQueues(state.workload);
    const groups = state.groupsHourError ? null : state.groupsHour;
    const attention = attentionCount(queues, groups, now);

    if (!state.workloadError) return attention;

    const definiteQueues = queues.filter(queue => queue.state === 'over' && queue.pause.knownOff).length;
    const unknown = attention.unknown.includes('queue state') ? attention.unknown : [...attention.unknown, 'queue state'];

    return {
        count: attention.count - definiteQueues,
        atLeast: true,
        possible: attention.possible + definiteQueues,
        unknown,
    };
}

/**
 * The sidebar badge of an attentionCount() result: the count, with "+" when
 * it is a lower bound, and "?" when it is 0 and a part is unknown. Null when
 * nothing needs attention (or the count is not loaded).
 */
export function attentionBadge(attention) {
    if (!attention || typeof attention.count !== 'number') return null;

    if (attention.count === 0) return attention.atLeast ? '?' : null;

    return `${formatCount(attention.count)}${attention.atLeast ? '+' : ''}`;
}

/**
 * The title of the attention badge. It never says a definite "0 need
 * attention" while a part is unknown: for example "At least 2 need
 * attention · 1 more may need attention (pause state unknown)", or
 * "Attention unknown: failure groups did not load".
 */
export function attentionTitle(attention) {
    if (!attention || typeof attention.count !== 'number') return null;

    const count = attention.count;
    const possible = attention.possible > 0 ? attention.possible : 0;
    const unknown = Array.isArray(attention.unknown) ? attention.unknown : [];
    // The possible items are over-limit queues whose pause state, or (while the workload read fails) whose whole state, is unknown.
    const reason = unknown.includes('pause state') ? 'pause state unknown' : 'queue state unknown';
    const notes = [
        unknown.includes('failure groups') ? 'failure groups did not load' : null,
        unknown.includes('queue state') && !(possible > 0 && reason === 'queue state unknown') ? 'queue state unknown' : null,
    ].filter(Boolean);
    const parts = [];

    if (count > 0) {
        parts.push(attention.atLeast
            ? `At least ${formatCount(count)} need attention`
            : `${formatCount(count)} ${count === 1 ? 'needs' : 'need'} attention`);
    }

    if (possible > 0) {
        parts.push(`${formatCount(possible)}${count > 0 ? ' more' : ''} may need attention (${reason})`);
    }

    if (!parts.length) {
        if (!attention.atLeast) return 'Nothing needs attention';

        return notes.length ? `Attention unknown: ${notes.join(' · ')}` : 'Attention unknown';
    }

    return [...parts, ...notes].join(' · ');
}

/**
 * The failures of the past hour from the failure groups: { count, atLeast },
 * or null without groups. The sum covers the returned page only, so it is
 * "at least" when the groups may be incomplete (groupsIncomplete()). A group
 * whose count is unknown adds 1 (a listed group holds at least one failure),
 * and a capped or inexact group count (`count_capped`, `exact: false`) is a
 * lower bound too: both make the sum "at least", never a definite total.
 */
export function failuresPastHour(data) {
    const groups = failureGroups(data);

    if (!groups) return null;

    let atLeast = groupsIncomplete(data);
    let count = 0;

    for (const group of groups) {
        const value = toSeconds(group && group.count);

        if (value === null) {
            count += 1;
            atLeast = true;

            continue;
        }

        count += value;

        if (group.count_capped === true || group.exact === false) atLeast = true;
    }

    return {count, atLeast};
}

/**
 * The retention state of `GET /api/health`:
 * { state: 'ok' | 'behind' | 'unknown', lagSeconds, at }.
 *
 * - 'behind': `behind` is true and the lag is at least RETENTION_BEHIND_SECONDS;
 * - 'unknown': also `behind: true` with a shorter lag while partitions
 *   stayed unchecked (unknown is never healthy);
 * - 'unknown': no state ('none'), an unreadable state, a missing field, a
 *   state older than RETENTION_STALE_SECONDS (a run that throws writes no
 *   state, so an old state is not current), or `behind: null` (the run's
 *   share ran out before it checked every partition, for example
 *   `deleted: 0` with `partitions_unchecked > 0`);
 * - else 'ok'.
 */
export function retentionState(retention, now) {
    const at = retention && retention.state === 'ok' ? toSeconds(retention.at) : null;

    if (at === null || now - at > RETENTION_STALE_SECONDS) {
        return {state: 'unknown', lagSeconds: null, at};
    }

    const lagSeconds = toSeconds(retention.lag_seconds);

    if (retention.behind === true) {
        if (lagSeconds !== null && lagSeconds >= RETENTION_BEHIND_SECONDS) return {state: 'behind', lagSeconds, at};

        // A short lag proves "ok" only when every unfinished partition was checked.
        const unchecked = toSeconds(retention.partitions_unchecked);

        return {state: lagSeconds !== null && unchecked === 0 ? 'ok' : 'unknown', lagSeconds, at};
    }

    if (retention.behind === false) {
        return {state: 'ok', lagSeconds, at};
    }

    return {state: 'unknown', lagSeconds, at};
}

/**
 * The per-minute counts state of `GET /api/health` `samples`:
 * { state: 'ok' | 'behind' | 'none' | 'unknown', lagSeconds }. The lag
 * is now minus the counted-through minute M.
 *
 * - 'behind': the lag passes SAMPLES_BEHIND_SECONDS;
 * - 'none': nothing was counted yet (a first install, before the first tick);
 * - 'unknown': the server could not read M (`unreadable`), or the value is
 *   not a number; also without the field (an older server), which the footer
 *   does not warn about;
 * - else 'ok'.
 */
export function samplesState(samples, now) {
    if (!samples || typeof samples !== 'object') return {state: 'unknown', lagSeconds: null, reported: false};

    if (samples.state === 'none') return {state: 'none', lagSeconds: null, reported: true};

    const through = samples.state === 'ok' ? toSeconds(samples.counted_through) : null;

    if (through === null) return {state: 'unknown', lagSeconds: null, reported: true};

    const lagSeconds = Math.max(0, now - through);

    return {state: lagSeconds > SAMPLES_BEHIND_SECONDS ? 'behind' : 'ok', lagSeconds, reported: true};
}

/** The reasons of `uninstrumented_connections` that say the queue object is not Orrery's. */
const NOT_ORRERYS = ['swap_disabled', 'foreign_queue_class'];

/** The valid `{connection, reason}` entries of `uninstrumented_connections`. */
function uninstrumentedEntries(health) {
    return (health && Array.isArray(health.uninstrumented_connections) ? health.uninstrumented_connections : [])
        .filter(entry => entry && typeof entry.connection === 'string' && entry.connection !== '');
}

/**
 * A pending or running count of `GET /api/counts` ({ count, at_least }) as
 * the sidebar shows it (Pending and Running are two items,
 * each linking to its own tab). The count is of mirror rows, and a
 * connection in `uninstrumented_connections` writes no pending or running
 * row, so while one is listed (any reason, `unknown`
 * included) a positive count is "at least" and 0 is unknown (null). Without
 * a health response the count stays as it is. Null when the count is
 * unknown.
 */
export function mirroredCount(value, health) {
    const count = value ? toSeconds(value.count) : null;

    if (count === null) return null;

    const atLeast = value.at_least === true;

    if (uninstrumentedEntries(health).length === 0) return {count, at_least: atLeast};

    return count > 0 ? {count, at_least: true} : null;
}

/** The Jobs items of the sidebar, each a tab of the Jobs list. */
const JOB_ITEMS = [
    {key: 'pending', label: 'Pending', icon: 'pending', what: 'pending jobs, delayed included'},
    {key: 'running', label: 'Running', icon: 'running', what: 'running jobs'},
    {key: 'completed', label: 'Completed', icon: 'completed', what: 'completed jobs kept'},
    {key: 'failed', label: 'Failed', icon: 'failed-job', what: 'failed jobs kept'},
    {key: 'stuck', label: 'Stuck', icon: 'stuck', what: null},
];

/**
 * The Jobs items of the sidebar from the shell's counts ({ pending, running,
 * completed, failed }, each { count, at_least } or null): { key, label,
 * icon, status, to, count, title }. Each item's count is the count of the
 * tab it links to, so the label, the count and the link always agree.
 * Stuck has no count; an unknown count is
 * "—", a capped one ends in "+".
 */
export function jobNavItems(counts) {
    const values = counts && typeof counts === 'object' ? counts : {};

    return JOB_ITEMS.map(item => {
        const value = values[item.key] || null;
        const count = value ? toSeconds(value.count) : null;
        let title = null;

        if (item.what !== null) {
            title = count === null
                ? `${item.what.charAt(0).toUpperCase()}${item.what.slice(1)}: unknown`
                : `${value.at_least === true ? 'At least ' : ''}${formatCount(count)} ${item.what}`;
        }

        return {
            key: item.key,
            label: item.label,
            icon: item.icon,
            status: item.key,
            to: {path: '/jobs', query: {status: item.key}},
            count: item.what === null ? null : countLabel(value),
            title,
        };
    });
}

/** Below this width the sidebar sits behind a toggle. */
export const SIDEBAR_BREAKPOINT = 1024;

/** Whether the sidebar is a drawer behind the toolbar's toggle at this viewport width. */
export function sidebarIsDrawer(width) {
    return typeof width === 'number' && Number.isFinite(width) && width < SIDEBAR_BREAKPOINT;
}

/**
 * Whether a screen's inspector is shown ({ narrow, inspectorOpen,
 * overlayOpen }, shell/store.js `shell`): in the wide layout the viewer's
 * kept choice, in the narrow layout (below 1024 px) only while the overlay
 * is open, so a kept "open" never covers a phone's screen by itself.
 */
export function inspectorShown(state) {
    if (!state) return false;

    return state.narrow === true ? state.overlayOpen === true : state.inspectorOpen === true;
}

/** "a", "[0] and [2]" style list of override entries. */
function overrideEntries(indexes) {
    const entries = indexes.map((index, position) => (position === 0 ? `orrery.trim_overrides[${index}]` : `[${index}]`));

    return listText(entries);
}

/**
 * The configuration problems of `GET /api/health`, in the
 * order the footer names them; each { key, text }. Every field is null when
 * the server could not read it (see configurationUnknown()), and an absent
 * field (an older server) is no problem:
 * - `schema`: the installed schema is older than the code (or missing): its hint, alone when no version is
 *   installed (the hint then names the problem, for example a refused sibling file);
 * - `redaction.valid` false: an invalid redaction pattern hides job values;
 * - `guard_invalid` true: `orrery.guard` names no usable guard;
 * - `app_key_missing` true: bulk forget is refused;
 * - `bulk_config.valid` false: `orrery.bulk.forget_max` refuses every bulk forget;
 * - `retention_config.valid` false: the retention phase skips;
 * - `missing_indexes`: the stuck lists and the stuck incidents need the
 *   deferred indexes, with the server's hint.
 */
export function configurationWarnings(health) {
    if (!health || typeof health !== 'object') return [];

    const warnings = [];
    const schema = health.schema;

    if (schema && typeof schema === 'object') {
        const installed = toSeconds(schema.installed);
        const expected = toSeconds(schema.expected);
        const outdated = installed === null || (expected !== null && installed < expected);
        const hint = typeof schema.hint === 'string' && schema.hint !== '' ? schema.hint : null;

        if (outdated || hint !== null) {
            // Without an installed version the server's hint says what is wrong (a refused sibling file, tables
            // before the marker, no tables), so it stands alone.
            if (installed === null) {
                warnings.push({key: 'schema', text: hint ?? 'The Orrery schema is not installed.'});
            } else {
                const version = `The Orrery schema is version ${installed}${expected !== null ? `, this release needs ${expected}` : ''}`;

                warnings.push({key: 'schema', text: hint !== null ? `${version}. ${hint}` : `${version}.`});
            }
        }
    }

    const redaction = health.redaction;

    if (redaction && typeof redaction === 'object' && redaction.valid === false) {
        const fields = (Array.isArray(redaction.errors) ? redaction.errors : [])
            .map(error => (error && typeof error.field === 'string' ? error.field : null))
            .filter(Boolean);

        warnings.push({
            key: 'redaction',
            text: `The redaction configuration is invalid${fields.length ? ` (${fields.join(', ')})` : ''}: job values are not shown until it is fixed.`,
        });
    }

    if (health.guard_invalid === true) {
        warnings.push({key: 'guard', text: 'orrery.guard names no usable guard: a signed-out request gets 403 instead of 401.'});
    }

    if (health.app_key_missing === true) {
        warnings.push({key: 'app_key', text: 'APP_KEY is empty: bulk forget is refused, and failure-group keys are not secret.'});
    }

    const bulk = health.bulk_config;

    if (bulk && typeof bulk === 'object' && bulk.valid === false) {
        warnings.push({key: 'bulk_config', text: 'orrery.bulk.forget_max is not a whole number from 1 to 100,000: every bulk forget is refused.'});
    }

    const retention = health.retention_config;

    if (retention && typeof retention === 'object' && retention.valid === false) {
        const errors = (Array.isArray(retention.errors) ? retention.errors : []).filter(error => typeof error === 'string' && error !== '');

        warnings.push({
            key: 'retention_config',
            text: `The retention configuration is invalid, so retention does not run${errors.length ? `: ${errors.join('; ')}` : '.'}`,
        });
    }

    const indexes = (Array.isArray(health.missing_indexes) ? health.missing_indexes : [])
        .filter(entry => entry && typeof entry.index === 'string' && entry.index !== '');

    if (indexes.length) {
        const hint = indexes.map(entry => entry.hint).find(value => typeof value === 'string' && value !== '') || null;

        warnings.push({
            key: 'missing_indexes',
            text: `The stuck lists and stuck alerts need the ${indexes.length === 1 ? 'index' : 'indexes'} ${listText(indexes.map(entry => entry.index))}${hint ? `. ${hint}` : '.'}`,
        });
    }

    return warnings;
}

/**
 * The neutral configuration notes of `GET /api/health`: the retention
 * overrides that match no known queue (`retention_config.unmatched`).
 * They may be meant for a queue that has not run yet, so they
 * are a note, not a warning.
 */
export function configurationNotes(health) {
    const retention = health && health.retention_config;
    const unmatched = retention && Array.isArray(retention.unmatched)
        ? retention.unmatched.filter(index => Number.isInteger(index) && index >= 0)
        : [];

    return unmatched.length ? [`${overrideEntries(unmatched)} ${unmatched.length === 1 ? 'matches' : 'match'} no known queue.`] : [];
}

/** The health fields whose null means "could not be read". */
const CONFIGURATION_FIELDS = [
    ['schema', 'schema version'],
    ['redaction', 'redaction'],
    ['guard_invalid', 'guard'],
    ['retention_config', 'retention configuration'],
    ['missing_indexes', 'indexes'],
];

/**
 * The configuration checks that the server could not read: a field that
 * is present and null (unknown, never "fine"). An absent field (an older
 * server) is not unknown. While the mirror is refused
 * (`sqlite_shared_mirror`) the footer reports the refusal instead.
 */
export function configurationUnknown(health) {
    if (!health || typeof health !== 'object' || health.sqlite_shared_mirror) return [];

    return CONFIGURATION_FIELDS
        .filter(([key]) => Object.prototype.hasOwnProperty.call(health, key) && health[key] === null)
        .map(([, name]) => name);
}

/** The other counters of a health window; each must be present and 0 for a rollback-only window. */
const DEGRADING_COUNTERS = ['dropped', 'unmatched_observations', 'unverified'];

/**
 * A health window whose only non-zero counter is rollback_unverified:
 * deferred queue operations that were not mirrored because their
 * transactions rolled back, usually a business rollback after a push. It
 * does not degrade the watcher. A window with another counter above 0, an
 * absent or unreadable counter (a renamed API field must not read as 0), or
 * an error still does.
 */
export function isRollbackOnlyWindow(window) {
    if (!window || !((toSeconds(window.rollback_unverified) ?? 0) > 0)) return false;

    return DEGRADING_COUNTERS.every(key => toSeconds(window[key]) === 0)
        && toSeconds(window.last_error_at) === null;
}

/**
 * The neutral footer line of the recent rollback drops, or null. The counter
 * counts deferred items (a bulk is one item), and a rollback is the usual
 * cause, though a lost COMMIT acknowledgement cannot be ruled out. With
 * `atLeast` (a truncated window list) the count is a lower bound.
 */
export function rollbackNote(count, atLeast = false) {
    if (!(count > 0)) return null;

    const amount = atLeast ? `At least ${count}` : `${count}`;
    const subject = count === 1 && !atLeast
        ? 'queue operation was not mirrored: its transaction rolled back'
        : 'queue operations were not mirrored: their transactions rolled back';

    return `${amount} ${subject} (a lost COMMIT cannot be ruled out).`;
}

/**
 * The count of the operations that wait for an operator (`awaiting_resolution`
 * of `GET /api/health`): { count, atLeast }, or null when the
 * server could not read it (unknown, never 0). awaitingUnknown() tells an
 * unreadable count (the key is present and null) from an older response
 * without the key.
 */
export function awaitingOperations(health) {
    const awaiting = health && health.awaiting_resolution;
    const count = awaiting ? toSeconds(awaiting.count) : null;

    return count === null ? null : {count, atLeast: awaiting.at_least === true};
}

/**
 * Whether the server reported the waiting operations as unknown: the key
 * `awaiting_resolution` is present and null, and the mirror is not refused
 * (`sqlite_shared_mirror`: the footer reports the refusal itself). An absent
 * key (an older server) is not unknown.
 */
export function awaitingUnknown(health) {
    return Boolean(health)
        && Object.prototype.hasOwnProperty.call(health, 'awaiting_resolution')
        && health.awaiting_resolution === null
        && !health.sqlite_shared_mirror;
}

/**
 * The newest failed rows that the server reads for the count
 * (HealthController::UNPROVABLE_WINDOW).
 */
const UNPROVABLE_WINDOW = 10000;

/**
 * The neutral footer line of the failed jobs whose record Orrery cannot
 * prove (`failed_records.unprovable_failures`): Orrery refuses
 * to retry or forget them, Laravel's own commands still can. The server
 * reads only the newest failed rows, so a censored zero (`at_least`) says
 * that the rest is unknown. Null when there are none or the count is
 * unknown.
 */
export function unprovableNote(health) {
    const unprovable = health && health.failed_records ? health.failed_records.unprovable_failures : null;
    const count = unprovable ? toSeconds(unprovable.count) : null;

    if (count === 0 && unprovable.at_least === true) {
        return `No failed job among the newest ${UNPROVABLE_WINDOW.toLocaleString('en-US')} lacks a proven failed record; the older failed jobs were not checked, so it is unknown beyond them.`;
    }

    if (!(count > 0)) return null;

    const amount = unprovable.at_least === true ? `At least ${count}` : `${count}`;

    return `${amount} failed ${count === 1 && unprovable.at_least !== true ? 'job has' : 'jobs have'} no proven failed record: retry and forget them with Laravel's queue:retry or queue:forget.`;
}

/**
 * The database queue connections whose fence state is unknown
 * (`fence_unknown_connections` of `GET /api/health`, D18): an
 * application SQLite file that allows no automatic reads, so the package
 * does not check its fence table. Unknown, never missing.
 */
export function fenceUnknownConnections(health) {
    const names = health && Array.isArray(health.fence_unknown_connections) ? health.fence_unknown_connections : [];

    return names.filter(name => typeof name === 'string' && name !== '');
}

/**
 * The distinct messages of `sqlite_warnings` of `GET /api/health` (D18),
 * in order: the "enable WAL" hint of an application SQLite file
 * without automatic reads, and the hints of a file that answered another
 * journal mode. One line per message, not one per file.
 */
export function sqliteWarningMessages(health) {
    const warnings = health && Array.isArray(health.sqlite_warnings) ? health.sqlite_warnings : [];
    const messages = [];

    for (const warning of warnings) {
        const message = warning && typeof warning.message === 'string' ? warning.message : '';

        if (message !== '' && !messages.includes(message)) messages.push(message);
    }

    return messages;
}

/** "a", "a and b", "a, b and c". */
function listText(items) {
    return items.length < 2 ? items.join('') : `${items.slice(0, -1).join(', ')} and ${items[items.length - 1]}`;
}

/**
 * One sentence per entry of `shared_jobs_table_retry_after` of `GET
 * /api/health` (gate fix 4): queue connections that share one jobs table
 * with different retry_after values. A job that a worker of a longer one
 * holds becomes ready for a worker of a shorter one, which can run it a
 * second time when workers of both serve the same queue. An entry without two named connections and a number for each
 * is skipped.
 */
export function sharedRetryAfterNotes(health) {
    const entries = health && Array.isArray(health.shared_jobs_table_retry_after) ? health.shared_jobs_table_retry_after : [];
    const notes = [];

    for (const entry of entries) {
        const connections = entry && Array.isArray(entry.connections) ? entry.connections : [];
        const values = entry && Array.isArray(entry.values) ? entry.values.map(value => toSeconds(value)) : [];

        if (connections.length < 2 || values.length !== connections.length
            || connections.some(name => typeof name !== 'string' || name === '') || values.some(value => value === null)
            || typeof entry.table !== 'string' || entry.table === '') continue;

        const shortest = Math.min(...values);
        const shorter = connections.filter((_, index) => values[index] === shortest);
        const parts = connections.map((name, index) => index === 0 ? `${name} (retry_after ${values[index]} s)` : `${name} (${values[index]} s)`);

        // Only a queue that workers of both connections serve is at risk.
        notes.push(`${listText(parts)} share the ${entry.table} table: a worker of ${listText(shorter)} can run a job again when workers of ${connections.length === 2 ? 'both' : 'all of them'} serve the same queue.`);
    }

    return notes;
}

/**
 * The watcher-health footer from `GET /api/health` and the live workers:
 * { tone: 'healthy' | 'warning' | 'unknown', title, tickAge, heartbeatAge,
 * recentWindows, missingFenceTables, fenceUnknown, awaiting, uninstrumented, instrumentationUnknown,
 * retention, samples, rollbackNote, unprovableNote, configNotes }. The checks, in order: no health, a
 * refused mirror (`sqlite_shared_mirror`, its reason as the title), a schema that is not installed (the
 * server's hint, also a refused sibling file), a tick (`tick.state` `unreadable`) or windows (null) that the
 * server could not read, no tick, a late tick, a
 * recent degradation, missing fence tables (retries on those connections
 * are refused until `php artisan orrery:install --fence` and `migrate`),
 * operations that wait for an operator, a waiting count that the server could not read
 * (awaitingUnknown()), uninstrumented connections (the reasons `swap_disabled` and
 * `foreign_queue_class`: their queue object is not Orrery's, so only
 * outcomes are mirrored), connections whose instrumentation is unknown (the
 * reason `unknown`, or a reason that the SPA does not know), connections
 * whose fence state is unknown (an application SQLite file without
 * automatic reads), the SQLite
 * warnings (sqliteWarningMessages()), jobs tables shared with different
 * retry_after values (sharedRetryAfterNotes()), retention
 * behind, an unknown retention state, per-minute counts behind by more than
 * SAMPLES_BEHIND_SECONDS, a count state that the server could not read,
 * and the configuration checks that the server could not read
 * (configurationUnknown()). Each of them gives the tone 'warning'. The
 * configuration problems (configurationWarnings(): an older schema right
 * after a degradation, the others after the waiting operations) are
 * warnings too.
 *
 * `configNotes` lists the configuration problems, the SQLite warnings, the
 * shared retry_after sentences and the unmatched retention overrides that
 * are not the title: they describe the configuration, so the footer keeps
 * them visible below any title.
 */
export function watcherHealth(health, workers, now) {
    if (!health || !health.tick) {
        return {tone: 'unknown', title: 'Watcher health unknown', tickAge: null, heartbeatAge: null};
    }

    const tickAt = toSeconds(health.tick.observer_tick_at);
    const tickAge = tickAt !== null ? Math.max(0, now - tickAt) : null;
    const heartbeats = (Array.isArray(workers) ? workers : [])
        .map(worker => heartbeatAge(worker, now))
        .filter(value => value !== null);
    const freshestHeartbeat = heartbeats.length ? Math.min(...heartbeats) : null;
    const since = now - RECENT_DEGRADATION_SECONDS;
    const recent = (Array.isArray(health.windows) ? health.windows : [])
        .filter(window => window && (
            toSeconds(window.started_at) >= since
            || (toSeconds(window.ended_at) !== null && toSeconds(window.ended_at) >= since)
            // A long degradation that is still open and still records errors.
            || (window.ended_at === null && toSeconds(window.last_error_at) !== null && toSeconds(window.last_error_at) >= since)
        ));
    // A business rollback after a push is not a degradation.
    const recentWindows = recent.filter(window => !isRollbackOnlyWindow(window)).length;
    const rolledBack = recent.reduce((sum, window) => sum + (toSeconds(window.rollback_unverified) ?? 0), 0);
    const missingFenceTables = (Array.isArray(health.missing_fence_tables) ? health.missing_fence_tables : [])
        .filter(name => typeof name === 'string' && name !== '');
    const entries = uninstrumentedEntries(health);
    const uninstrumented = entries.filter(entry => NOT_ORRERYS.includes(entry.reason)).map(entry => entry.connection);
    const instrumentationUnknown = entries.filter(entry => !NOT_ORRERYS.includes(entry.reason)).map(entry => entry.connection);
    const retention = retentionState(health.retention, now);
    const samples = samplesState(health.samples, now);
    const awaiting = awaitingOperations(health);
    const fenceUnknown = fenceUnknownConnections(health);
    const sqliteWarnings = sqliteWarningMessages(health);
    const sharedRetryAfter = sharedRetryAfterNotes(health);
    const configuration = configurationWarnings(health);
    const schema = configuration.find(warning => warning.key === 'schema') || null;
    const problems = configuration.filter(warning => warning.key !== 'schema').map(warning => warning.text);
    const unknownChecks = configurationUnknown(health);
    const result = (tone, title) => ({tone, title, tickAge, heartbeatAge: freshestHeartbeat, recentWindows, windowsTruncated: health.truncated === true, missingFenceTables, fenceUnknown, awaiting, uninstrumented, instrumentationUnknown, retention, samples,
        rollbackNote: rollbackNote(rolledBack, health.truncated === true), unprovableNote: unprovableNote(health),
        configNotes: [...configuration.map(warning => warning.text), ...sqliteWarnings, ...sharedRetryAfter, ...configurationNotes(health)].filter(note => note !== title)});

    // A refused mirror (D17) or a schema that is not installed answers with no tick: the reason is the title,
    // never "No tick yet".
    if (typeof health.sqlite_shared_mirror === 'string' && health.sqlite_shared_mirror !== '') {
        return result('warning', health.sqlite_shared_mirror);
    }

    if (schema && health.schema && toSeconds(health.schema.installed) === null) return result('warning', schema.text);

    // Unknown is never "No tick yet" or healthy: the tick (state `unreadable`) or the windows (null) could not be read.
    if (health.tick.state === 'unreadable' || health.windows === null) {
        return result('warning', "Watcher health unknown: Orrery's storage could not be read");
    }

    if (tickAge === null) return result('warning', 'No tick yet');

    if (tickAge > STALE_TICK_SECONDS) return result('warning', 'Tick is late');

    if (recentWindows > 0) return result('warning', 'Watcher degraded');

    // An older schema fails the bulk runs and the newer reads: before every other configuration line.
    if (schema) return result('warning', schema.text);

    if (missingFenceTables.length) {
        return result('warning', `Retries refused on ${missingFenceTables.join(', ')}: fence table missing; run php artisan orrery:install --fence`);
    }


    if (awaiting && awaiting.count > 0) {
        const amount = awaiting.atLeast ? `At least ${awaiting.count}` : `${awaiting.count}`;
        const subject = awaiting.count === 1 && !awaiting.atLeast ? 'operation waits' : 'operations wait';

        return result('warning', `${amount} ${subject} for an operator: Resolve on the job page, or php artisan orrery:resolve --all`);
    }

    // Unknown is never 0: a count that the server could not read is not "healthy".
    if (awaitingUnknown(health)) return result('warning', 'Waiting operations unknown');

    if (problems.length) return result('warning', problems[0]);

    if (uninstrumented.length) {
        return result('warning', `Pending and running jobs are not mirrored for ${uninstrumented.join(', ')}: the queue is not Orrery's.`);
    }

    if (instrumentationUnknown.length) {
        return result('warning', `Instrumentation is unknown for ${instrumentationUnknown.join(', ')}.`);
    }

    // Unknown is not missing: the package does not read the fence table of such a file (D18). It comes
    // after the waiting operations and the instrumentation, which it must never hide.
    if (fenceUnknown.length) return result('warning', `Fence state unknown for ${fenceUnknown.join(', ')}`);

    if (sqliteWarnings.length) return result('warning', sqliteWarnings[0]);

    if (sharedRetryAfter.length) return result('warning', sharedRetryAfter[0]);

    if (retention.state === 'behind') return result('warning', 'Retention is behind');

    // A warning, not only unknown: retention that cannot be shown to run may let the mirror grow.
    if (retention.state === 'unknown') return result('warning', 'Retention state unknown');

    // The throughput and queue counts lag behind.
    if (samples.state === 'behind') return result('warning', 'Counts are behind');

    if (samples.state === 'unknown' && samples.reported) return result('warning', 'Count state unknown');

    // Unknown is never healthy: a configuration check that the server could not read.
    if (unknownChecks.length) return result('warning', `Configuration checks unknown: ${listText(unknownChecks)}`);

    return result('healthy', 'Watcher healthy');
}

/**
 * The footer's title of the recent degradation windows (watcherHealth()):
 * "3 degradation windows in the last 15 minutes", with "At least" when the
 * server kept only the newest windows (`truncated`), or null for none.
 */
export function degradationTitle(result) {
    const count = result ? result.recentWindows : null;

    if (typeof count !== 'number' || count <= 0) return null;

    return `${result.windowsTruncated ? 'At least ' : ''}${formatCount(count)} degradation ${count === 1 ? 'window' : 'windows'} in the last 15 minutes`;
}

/**
 * How long ago a worker's heartbeat was, in seconds, at `now` (the server
 * clock, time.js nowSeconds()): from its `last_seen_at`, so the age goes on
 * growing between reads; else the server's `seconds_ago` of the read. Never
 * below 0: a worker host whose clock runs ahead reads "just now", not "-2 s
 * ago". Null when neither is known.
 */
export function heartbeatAge(worker, now) {
    const seen = toSeconds(worker && worker.last_seen_at);
    const age = seen !== null && Number.isFinite(now) ? now - seen : toSeconds(worker && worker.seconds_ago);

    return age === null ? null : Math.max(0, age);
}

/**
 * The dot of a listed worker from the server's `state` (`GET /api/workers`):
 * `{key, label}`. `quiet` (amber) is a worker whose last heartbeat is older
 * than two heartbeat intervals and that holds no live lease; the label
 * names the heartbeat's age at `now`, so it goes on growing between reads.
 * `live` keeps the green dot. A missing or other state is `unknown`, never
 * guessed as live.
 */
export function workerState(worker, now) {
    const state = worker && worker.state;

    if (state === 'quiet') {
        const age = heartbeatAge(worker, now);

        return {key: 'quiet', label: age === null ? 'No recent heartbeat' : `No heartbeat for ${formatDuration(age)}`};
    }

    if (state === 'live') {
        return {key: 'live', label: worker.heartbeat_fresh === false ? 'No fresh heartbeat: live through an active lease' : 'Fresh heartbeat'};
    }

    return {key: 'unknown', label: 'Worker state unknown'};
}

/**
 * The footer of the shell from its live data ({ health, workers,
 * healthError }, shell/store.js `live`): null before anything loaded. While
 * the health read fails, the footer says "Watcher health unknown": the last
 * data stays, but it is neither healthy nor a tick that only ages into
 * "Tick is late". `stopped` is true when the page left its polls (errors.js
 * terminalState(): an expired session or lost access, also when a screen's
 * own read met it first): the data then never updates again, so the footer
 * is unknown and shows no tick or heartbeat age that only grows.
 */
export function shellHealth(state, now, stopped = false) {
    if (!state || (state.health === null && state.workers === null)) return null;

    const result = watcherHealth(state.health, state.workers, now);

    if (stopped) {
        return {...result, tone: 'unknown', title: 'Watcher health unknown', tickAge: null, heartbeatAge: null};
    }

    if (state.healthError) {
        return {...result, tone: 'unknown', title: 'Watcher health unknown'};
    }

    return result;
}

/**
 * A count of `GET /api/counts` ({ count, at_least }) as the sidebar shows
 * it: compact, with "+" when it is capped, and "—" when it is unknown.
 */
export function countLabel(value) {
    const count = value ? toSeconds(value.count) : null;

    if (count === null) return '—';

    return `${formatCompactCount(count, {down: value.at_least === true})}${value.at_least === true ? '+' : ''}`;
}

/**
 * The running batches of the first `GET /api/batches` page
 * (snake_case): { count, atLeast }. The server decides whether each batch
 * runs (`running`, BatchesController::withRunning(): it reads the mirror
 * when the counts cannot tell); a batch whose `running` is not a boolean
 * (null: the server could not tell) makes the count "at least". The page
 * holds the 50 newest batches, so a full page makes the count "at least"
 * too. Unknown batches (`batches: null`, D18 or an unsupported repository)
 * give null; a missing batch table (`available: false`, an empty list)
 * gives 0.
 */
export function runningBatches(data) {
    const batches = data && Array.isArray(data.batches) ? data.batches : null;

    if (!batches) return null;

    let running = 0;
    let unknown = false;

    for (const batch of batches) {
        if (!batch) continue;

        if (batch.running === true) {
            running++;
        } else if (batch.running !== false) {
            unknown = true;
        }
    }

    return {count: running, atLeast: unknown || batches.length >= 50};
}

/**
 * The sidebar count of the running batches (runningBatches()): `{text,
 * title}`. An "at least" count is "50+ running", with the words in the
 * title. A known zero shows nothing; no known running batch while some
 * could not be read is unknown ("—"), never a silent zero. `failed` (the
 * last read failed) and a read whose count is unknown (`count` null: the
 * list could not be read, runningBatches() null) are unknown too; only
 * before the first read (`batches` null) does it show nothing.
 */
export function batchesCount(batches, failed = false) {
    if (failed) return {text: '—', title: 'Running batches unknown'};
    if (!batches) return {text: null, title: null};
    if (typeof batches.count !== 'number') return {text: '—', title: 'Running batches unknown'};

    if (batches.count === 0) return batches.atLeast ? {text: '—', title: 'Running batches unknown'} : {text: null, title: null};

    // "50+" keeps the aside short, so the label "Batches" never truncates; the title says "at least".
    return batches.atLeast
        ? {text: `${formatCount(batches.count)}+ running`, title: `At least ${formatCount(batches.count)} running batches`}
        : {text: `${formatCount(batches.count)} running`, title: null};
}

/** A list body of a live read; a body of another shape throws, so the part is a failed read, not an empty list. */
export function listBody(data) {
    if (!Array.isArray(data)) throw new TypeError('The answer is not a list.');

    return data;
}

/** An object body of a live read; a body of another shape throws, so the part is a failed read. */
export function objectBody(data) {
    if (!data || typeof data !== 'object' || Array.isArray(data)) throw new TypeError('The answer is not an object.');

    return data;
}

/** The sentence of an answer of the wrong shape (a login page answered with status 200, a proxy's page). */
export const UNREADABLE_ANSWER = "The server's answer could not be read. Reload the page; you may need to sign in again.";

/**
 * A screen's own read body: an object whose `key` is a list (or null when
 * `nullable`, an unknown list). A body of another shape throws a TypeError,
 * so the screen shows a failed read (UNREADABLE_ANSWER), never "none".
 */
export function bodyWith(data, key, {nullable = false} = {}) {
    const body = objectBody(data);
    const value = body[key];

    if (!Array.isArray(value) && !(nullable && value === null)) throw new TypeError(`The answer has no ${key} list.`);

    return body;
}

/** Whether a failed read is an answer of the wrong shape (bodyWith(), objectBody(), listBody()). */
export function isShapeError(error) {
    return error instanceof TypeError && !(error && error.response);
}

/** The shell's live data is stale after this long without a successful poll. */
export const LIVE_STALE_SECONDS = 30;

/**
 * The toolbar's live indicator: { tone, text (the wide "Live · 09:32:07
 * GMT+3"), short (the narrow "Live · 09:32 GMT+3"), title }. `at` is the
 * last successful live read (null: none yet), `failed` whether the last read
 * of a live part failed (it keeps its last data, so it is stale). Both forms
 * show the zone.
 */
export function liveIndicator(at, now, failed) {
    if (at === null) {
        return failed
            ? {tone: 'stale', text: 'Stale · no data yet', short: 'Stale', title: 'The live data did not load'}
            : {tone: 'unknown', text: 'Connecting…', short: 'Connecting…', title: null};
    }

    const stale = failed || (now !== null && now - at > LIVE_STALE_SECONDS);
    const word = stale ? 'Stale' : 'Live';

    return {
        tone: stale ? 'stale' : 'live',
        text: `${word} · ${formatClockZoned(at)}`,
        short: `${word} · ${formatHourMinuteZoned(at)}`,
        title: stale ? `Last update ${relativeTime(at, now ?? undefined)}` : 'Updated every few seconds',
    };
}
