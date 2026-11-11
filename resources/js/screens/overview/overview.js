/**
 * The data rules of the Overview:
 * pure functions from the API responses to what the screen shows, so the
 * component stays thin and each rule is in one place.
 */

import {ATTENTION_FAILURE_SECONDS, attentionCount, failureGroups, shellAttention} from '../../shell/data.js';
import {broaderCauses, causesText, continueWarning, isRecordFailed, pauseCommands, pauseLabel, pauseNotes, pauseState, recordFailedNote, recordFailedWarning} from '../../support/pause.js';
import {queuePath} from '../../support/redirects.js';
import {readStored, removeStored, writeStored} from '../../support/storage.js';
import {slotStarts, slotValue} from '../../support/series.js';
import {formatCount, formatDayMinuteZoned, formatDuration, formatHourMinuteZoned, formatLimit, formatTimeRangeZoned, nowSeconds, relativeTime, toSeconds} from '../../support/time.js';
import {
    groupCountAtLeast,
    groupQueuesText,
    groupSinceText,
    markerBefore,
    operationLabel,
    queueName,
    shortName,
    stillFailedText,
} from '../failed/failures.js';

/** The failure-group rows of the Overview table. */
export const GROUP_ROWS = 4;

/**
 * The most failure-group notices the Overview shows. A burst of distinct
 * messages (each with an id in it) can make dozens of groups; the rest are
 * summed in one line that links to Failed jobs.
 */
export const MAX_GROUP_NOTICES = 5;

/** The live window of the Overview's failure groups: the past hour. */
export const GROUP_RANGE_SECONDS = 3600;

/** A number from an API field (numbers can be strings on MySQL and Postgres), or null. */
export function num(value) {
    return toSeconds(value);
}

/** The aliases of a workload row: `connections`, else its `connection`. */
export function aliasesOf(row) {
    if (row && Array.isArray(row.connections) && row.connections.length) {
        return row.connections.filter(alias => typeof alias === 'string' && alias !== '');
    }

    return row && typeof row.connection === 'string' ? [row.connection] : [];
}

/**
 * The state of a workload row: { pause, backlogUnknown, overLimit, pill: {
 * key, label }, hint }.
 *
 * `pause` is the pauseState() of the row's causes
 * (support/pause.js). The pill follows the state order of the sidebar:
 *
 * 1. Paused, when a definite cause holds: "Paused", "Paused (connection)",
 *    "Paused (all queues)", "Paused (queue:pause)", "Paused (queue:pause
 *    --all)", or several causes in one label;
 * 2. Backlog unknown (`backlog_unknown`: the backlog read failed);
 * 3. Over limit (a possible pause never hides it);
 * 4. "Possibly paused" or "Pause state unknown" (the pause is not known off);
 * 5. Workers unknown (no worker count), Workers quiet (every listed
 *    worker is quiet: `quiet_processes` equals `processes`), Running (a
 *    live worker), or No workers.
 *
 * `hint` names what Orrery's actions cannot lift (Laravel's global pause:
 * "Resume with php artisan queue:continue --all") and the notes of an
 * unknown part, else null. Unknown is never "not paused", "Running" or "No
 * workers"; a missing row is "Unknown".
 */
export function queueState(row) {
    const pause = pauseState(row);
    const backlogUnknown = !!(row && row.backlog_unknown === true);
    const overLimit = !!(row && row.over_limit === true);
    const workers = num(row && row.processes);
    const quiet = num(row && row.quiet_processes);
    const hints = [
        ...pauseCommands(pause).map(command => `Resume with ${command}`),
        ...pauseNotes(pause).filter(note => note !== 'pause state unknown' && note !== 'possibly paused (queue:pause)'),
    ];
    const hint = row && hints.length ? hints.join(' · ') : null;

    let pill;

    if (!row) {
        pill = {key: 'unknown', label: 'Unknown'};
    } else if (pause.paused) {
        pill = {key: 'paused', label: pauseLabel(pause)};
    } else if (backlogUnknown) {
        pill = {key: 'unknown', label: 'Backlog unknown'};
    } else if (overLimit) {
        pill = {key: 'over', label: 'Over limit'};
    } else if (!pause.knownOff) {
        pill = {key: 'unknown', label: pause.possible.length ? pauseLabel(pause) : 'Pause state unknown'};
    } else if (workers === null) {
        pill = {key: 'unknown', label: 'Workers unknown'};
    } else if (workers > 0 && quiet === workers) {
        pill = {key: 'quiet', label: 'Workers quiet'};
    } else if (workers > 0) {
        pill = {key: 'running', label: 'Running'};
    } else {
        pill = {key: 'idle', label: 'No workers'};
    }

    return {pause, backlogUnknown, overLimit, pill, hint};
}

/**
 * "at least since 09:17 GMT+3" or "since 09:17 GMT+3" (with the date for a
 * start before `now`'s day), or null without a start.
 */
export function overLimitSinceText(row, now = nowSeconds()) {
    const since = num(row && row.over_limit_since);

    if (since === null) return null;

    return `${row.over_limit_since_censored === true ? 'at least since' : 'since'} ${formatDayMinuteZoned(since, now)}`;
}

/**
 * The card footer: "Over limit since 09:17 GMT+3" (amber), "Over limit" without a
 * start, "Within limit", "No limit" for a threshold of 0, or "Limit state
 * unknown" when the backlog or the limit state is unknown (`over_limit:
 * null`, `backlog_unknown`), never "Within limit".
 */
export function limitFooter(row, now = nowSeconds()) {
    if (row && row.over_limit === true) {
        const since = overLimitSinceText(row, now);

        return {tone: 'over', text: since ? `Over limit ${since}` : 'Over limit'};
    }

    const threshold = num(row && row.threshold);

    if (threshold !== null && threshold === 0) return {tone: 'plain', text: 'No limit'};

    if (!row || row.backlog_unknown === true || row.over_limit !== false || threshold === null) {
        return {tone: 'plain', text: 'Limit state unknown'};
    }

    return {tone: 'plain', text: 'Within limit'};
}

/** A count for a card or a notice: "1,204", or "—" when unknown. */
export function countOf(value) {
    const number = num(value);

    return number === null ? '—' : formatCount(number);
}

/**
 * A group's count: "27", or "at least 27" when the count is a lower bound
 * (groupCountAtLeast(): a capped count, or rows of a truncated fallback scan).
 */
export function groupCountText(group) {
    const count = num(group && group.count);

    if (count === null) return '—';

    return groupCountAtLeast(group) ? `at least ${formatCount(count)}` : formatCount(count);
}

/** Whether a failure group had a failure in the last 15 minutes. */
export function groupNeedsAttention(group, now) {
    const last = num(group && group.last_failed_at);

    return last !== null && last >= now - ATTENTION_FAILURE_SECONDS;
}

/** The queue names of a group: "emails", "emails, default", or "emails and more" when the list is incomplete. */
export function groupQueues(group) {
    return groupQueuesText(group, queueName);
}

/** A failure group whose last failure is this recent is active (tier 2); older ones within the window fade (tier 4). */
export const ACTIVE_FAILURE_SECONDS = 300;

/** The attention items shown in full before the rest stack behind a button. */
export const ATTENTION_SHOWN = 3;

const EXPANDED_KEY = 'orreryAttentionExpanded';

/**
 * The attention items (the same items as the sidebar's attentionCount();
 * only the order is this function's), most important first:
 *
 * 1. stalled queues: over the limit, not paused by a definite cause, and no
 *    worker can drain them (`processes` 0, or every worker quiet:
 *    `quiet_processes` equals `processes`); largest oldest wait first;
 * 2. active failure groups: a last failure in the last
 *    ACTIVE_FAILURE_SECONDS; largest count first (an at-least count by its
 *    lower bound, an unknown count last), then newest last failure;
 * 3. backed-up queues with a live worker, or with an unknown worker count
 *    (never stalled by a guess; the detail says "workers unknown"); the
 *    largest oldest wait ÷ threshold first, an unknown ratio last;
 * 4. fading failure groups: a last failure 5 to 15 minutes ago, newest
 *    first.
 *
 * A queue is over its limit when `over_limit` is true; an unknown or only
 * possible pause is not a proof of a pause, so it stays, with "possibly
 * paused" or "pause state unknown". Ties break by the item key, so the
 * order does not change between polls.
 */
export function attentionItems(workload, groupsData, markers, now) {
    const rows = Array.isArray(workload) ? workload : [];
    const names = rows.map(row => row && row.name);
    const queues = rows
        .filter(row => row && typeof row.name === 'string' && typeof row.connection === 'string'
            && row.over_limit === true && !pauseState(row).paused)
        .map(row => {
            const label = names.filter(name => name === row.name).length > 1 ? `${row.name} (${row.connection})` : row.name;
            const since = overLimitSinceText(row, now);
            const workers = num(row.processes);
            const quiet = num(row.quiet_processes);
            const stalled = workers !== null && (workers === 0 || quiet === workers);
            const age = num(row.oldest_age);
            const threshold = num(row.threshold);
            const why = !stalled ? '' : (workers === 0 ? ' has no worker and' : ' has only quiet workers and');
            const detail = [
                `${countOf(row.length)} ready on ${countOf(row.processes)} ${workers === 1 ? 'worker' : 'workers'}`,
                since ? `over the limit ${since}` : null,
                ...attentionPauseNotes(pauseState(row)),
                workers === null ? 'workers unknown' : null,
            ].filter(Boolean).join(' · ');

            return {
                type: 'queue',
                key: `queue:${row.connection}:${row.name}`,
                tier: stalled ? 1 : 3,
                rank: stalled ? age : (age !== null && threshold !== null && threshold > 0 ? age / threshold : null),
                row,
                label,
                title: `${label}${why} has waited ${formatDuration(age)}, over its ${formatLimit(threshold)} limit`,
                detail,
                to: queuePath(row.connection, row.name),
            };
        });

    const groups = (failureGroups(groupsData) || [])
        .filter(group => group && typeof group.key === 'string' && groupNeedsAttention(group, now))
        .map(group => {
            const marker = markerBefore(markers, num(group.first_failed_at), num(groupsData && groupsData.scope && groupsData.scope.from));
            const last = num(group.last_failed_at);
            const active = last >= now - ACTIVE_FAILURE_SECONDS;

            return {
                type: 'group',
                key: `group:${group.key}`,
                tier: active ? 2 : 4,
                rank: active ? num(group.count) : last,
                last,
                group,
                title: `${groupCountText(group)} failed with ${shortName(group.exception_class)}, ${groupSinceText(group, 'in the last hour')}`,
                detail: [
                    typeof group.message === 'string' && group.message !== '' ? group.message : null,
                    shortName(group.job),
                    groupQueues(group),
                    `last ${relativeTime(last, now)}`,
                ].filter(Boolean).join(' · '),
                marker,
                retryLabel: `${operationLabel('Retry', num(group.count), groupCountAtLeast(group))}…`,
            };
        });

    return [...queues, ...groups].sort(compareAttention);
}

/** The order of attentionItems(): the tier, the rank (larger first, unknown last), the last failure, the key. */
function compareAttention(a, b) {
    if (a.tier !== b.tier) return a.tier - b.tier;
    if (a.rank !== b.rank) {
        if (a.rank === null) return 1;
        if (b.rank === null) return -1;

        return b.rank - a.rank;
    }
    if (a.type === 'group' && a.last !== b.last) return (b.last ?? 0) - (a.last ?? 0);

    return a.key < b.key ? -1 : (a.key > b.key ? 1 : 0);
}

/**
 * The attention items shown in full (`visible`) and the ones stacked behind
 * the "Show more" button (`hidden`): the first ATTENTION_SHOWN items, and
 * the rest. One more item than that shows in full: a button never hides
 * exactly one item.
 */
export function attentionSplit(items, shown = ATTENTION_SHOWN) {
    const list = Array.isArray(items) ? items : [];

    return list.length <= shown + 1 ? {visible: list, hidden: []} : {visible: list.slice(0, shown), hidden: list.slice(shown)};
}

/** The stacked items' button: "Show 13 more · 9 queues over limit, 4 failure groups". */
export function attentionSummary(hidden) {
    const queues = hidden.filter(item => item.type === 'queue').length;
    const groups = hidden.length - queues;
    const parts = [
        queues ? `${formatCount(queues)} ${queues === 1 ? 'queue' : 'queues'} over limit` : null,
        groups ? `${formatCount(groups)} failure ${groups === 1 ? 'group' : 'groups'}` : null,
    ].filter(Boolean);

    return `Show ${formatCount(hidden.length)} more · ${parts.join(', ')}`;
}

/** Whether this browser tab expanded the attention list (sessionStorage through support/storage.js; false when it cannot be read). */
export function readAttentionExpanded(storage) {
    return readStored(EXPANDED_KEY, storage) === '1';
}

/** Stores the expanded state of the attention list; false when the storage cannot be written. */
export function writeAttentionExpanded(expanded, storage) {
    return expanded ? writeStored(EXPANDED_KEY, '1', storage) : removeStored(EXPANDED_KEY, storage);
}

/** The pause notes of an attention item: "possibly paused", or the unknown parts ("pause state unknown"). */
function attentionPauseNotes(pause) {
    if (pause.knownOff) return [];

    const notes = [pause.possible.length ? 'possibly paused' : null, ...pauseNotes(pause)].filter(Boolean);

    return notes.length ? notes : ['pause state unknown'];
}

/** "Tick is late" → "tick is late", for a part after the first. */
function lowerFirst(text) {
    return text.charAt(0).toLowerCase() + text.slice(1);
}

function upperFirst(text) {
    return text.charAt(0).toUpperCase() + text.slice(1);
}

/**
 * The toolbar subtitle from the attention count of
 * attentionCount() ({ count, atLeast, possible, unknown }) and the shell's
 * watcher health (store.js liveWatcherHealth(): { tone, title }, or null
 * before it loaded, which is unknown):
 *
 * - "2 need attention · the rest is healthy", or "Everything is healthy",
 *   only while the watcher is healthy, no part is unknown and the count is
 *   not a lower bound (`atLeast`); a lower bound of 0 is "Attention
 *   unknown: more may need attention";
 * - the possible items and the unknown parts: "1 may need attention · pause
 *   state unknown", "Failure groups unknown";
 * - the watcher title when it is not healthy: "Tick is late" with no item,
 *   "2 need attention · tick is late" with items. It never says "healthy"
 *   then;
 * - never "at least 0": a count of 0 is not shown.
 */
export function attentionSubtitle(attention, health) {
    if (!attention) return null;

    const count = typeof attention.count === 'number' ? attention.count : 0;
    const possible = attention.possible > 0 ? attention.possible : 0;
    const unknown = (Array.isArray(attention.unknown) ? attention.unknown : [])
        .filter(part => part !== 'pause state' || possible > 0);
    const watcher = health && typeof health.title === 'string' ? health : {tone: 'unknown', title: 'Watcher health unknown'};
    const parts = [];

    if (count > 0) {
        parts.push(`${attention.atLeast ? 'at least ' : ''}${formatCount(count)} ${count === 1 ? 'needs' : 'need'} attention`);
    }

    if (possible > 0) {
        parts.push(`${formatCount(possible)}${count > 0 ? ' more' : ''} may need attention`);
    }

    if (unknown.length) {
        const list = unknown.length > 1 ? `${unknown.slice(0, -1).join(', ')} and ${unknown[unknown.length - 1]}` : unknown[0];

        parts.push(`${list} unknown`);
    }

    if (watcher.tone !== 'healthy') {
        parts.push(parts.length ? lowerFirst(watcher.title) : watcher.title);
    } else if (!parts.length) {
        // A lower bound of 0 (a full group page may hide a recent group) is never "healthy".
        return attention.atLeast ? 'Attention unknown: more may need attention' : 'Everything is healthy';
    } else if (possible === 0 && !unknown.length && !attention.atLeast) {
        parts.push('the rest is healthy');
    }

    return upperFirst(parts.join(' · '));
}

/**
 * The attention count of the Overview: the shell's (shellAttention(), so the
 * subtitle and the sidebar agree), from the shell's `live` data. While the
 * workload has not loaded (and did not fail), it counts the failure groups
 * alone, with the queue state unknown; null before anything loaded.
 */
export function overviewAttention(state, now) {
    const shell = shellAttention(state, now);

    if (shell) return shell;

    if (!state || (state.groupsHour === null && !state.groupsHourError)) return null;

    const count = attentionCount([], state.groupsHourError ? null : state.groupsHour, now);

    return {...count, atLeast: true, unknown: [...count.unknown, 'queue state']};
}

/** "Oldest wait against each queue’s limit · 14 workers on 3 hosts". */
export function queuesSubtitle(workers) {
    const base = 'Oldest wait against each queue’s limit';

    if (!Array.isArray(workers)) return base;

    const hosts = new Set(workers.filter(worker => worker && typeof worker.host === 'string').map(worker => worker.host)).size;

    return `${base} · ${formatCount(workers.length)} ${workers.length === 1 ? 'worker' : 'workers'}`
        + ` on ${formatCount(hosts)} ${hosts === 1 ? 'host' : 'hosts'}`;
}

/** The throughput response's retention horizon of a series, or null. */
export function retentionOf(throughput, series) {
    const horizons = throughput && throughput.retention_from;

    return horizons && typeof horizons === 'object' ? num(horizons[series]) : null;
}

/**
 * The buckets of a throughput response with numeric values: each value is a
 * number or null (unknown), and `at` a number.
 */
export function throughputBuckets(throughput) {
    const buckets = throughput && Array.isArray(throughput.buckets) ? throughput.buckets : [];

    return buckets
        .map(bucket => ({
            at: num(bucket && bucket.at),
            completed: num(bucket && bucket.completed),
            failed: num(bucket && bucket.failed),
            partial: !!(bucket && bucket.partial === true),
        }))
        .filter(bucket => bucket.at !== null);
}

/**
 * The completed jobs of the window: { count, atLeast }, or null without a
 * response. A slot that is unknown (no bucket, before the retention horizon,
 * a null value) is not counted as zero: the total is then "at least".
 */
export function completedInWindow(throughput) {
    if (!throughput) return null;

    const from = num(throughput.from);
    const to = num(throughput.to);
    const size = num(throughput.bucket_seconds) || 60;

    if (from === null || to === null) return null;

    const byStart = new Map(throughputBuckets(throughput).map(bucket => [bucket.at, bucket]));
    const retention = retentionOf(throughput, 'completed');
    let count = 0;
    let atLeast = false;

    slotStarts(from, to, size).forEach(at => {
        const value = slotValue(byStart.get(at), 'completed', at, retention, size);

        if (value === null) {
            atLeast = true;
        } else {
            count += value;
        }
    });

    return {count, atLeast};
}

/** The minutes that the rate of throughputRate() averages. */
export const RATE_MINUTES = 5;

/**
 * The rate of the last five whole minutes: the jobs per
 * minute, completed and failed, of the five 60-second buckets before the
 * bucket that contains `as_of`. Each of the five must be present, complete
 * (`partial: false`) and known in both series; else the rate is unknown
 * (null). An older bucket never takes the place of a missing one.
 */
export function throughputRate(throughput) {
    const asOf = num(throughput && throughput.as_of);
    const size = num(throughput && throughput.bucket_seconds) || 60;

    if (asOf === null) return null;

    const current = Math.floor(asOf / size) * size;
    const byStart = new Map(throughputBuckets(throughput).map(bucket => [bucket.at, bucket]));
    let sum = 0;

    for (let index = RATE_MINUTES; index >= 1; index--) {
        const bucket = byStart.get(current - index * size);

        if (!bucket || bucket.partial || bucket.completed === null || bucket.failed === null) return null;

        sum += bucket.completed + bucket.failed;
    }

    return sum / RATE_MINUTES;
}

/** A rate per minute: "1,284", "12", "1.4"; "—" when unknown. */
export function rateText(rate) {
    if (typeof rate !== 'number' || !Number.isFinite(rate)) return '—';

    return formatCount(rate >= 10 ? Math.round(rate) : Math.round(rate * 10) / 10);
}

/**
 * The throughput subtitle: "1,284/min · last 5 min · 74,902 completed in the
 * past hour". The rate comes from the throughput response
 * (throughputRate()), never from the metric snapshots.
 */
export function throughputSubtitle(throughput) {
    const completed = completedInWindow(throughput);
    const parts = [`${rateText(throughputRate(throughput))}/min · last 5 min`];

    // "at least 0" says nothing (a fresh install, or every minute unknown): the count is unknown.
    if (completed && completed.atLeast && completed.count === 0) {
        parts.push('completed in the past hour unknown');
    } else if (completed) {
        parts.push(`${completed.atLeast ? 'at least ' : ''}${formatCount(completed.count)} completed in the past hour`);
    }

    return parts.join(' · ');
}

/** The median of a list of numbers. */
function median(values) {
    const sorted = [...values].sort((a, b) => a - b);

    return sorted.length ? sorted[Math.floor(sorted.length / 2)] : null;
}

/** "~600", "~1,300": two significant digits. */
export function approximately(value) {
    if (!(value > 0)) return '~0';

    const magnitude = Math.pow(10, Math.max(0, Math.floor(Math.log10(value)) - 1));

    return `~${formatCount(Math.round(value / magnitude) * magnitude)}`;
}

/**
 * The chart annotations of the completed series,
 * computed from complete buckets only (a partial bucket is still
 * filling, and an unknown bucket is not a zero):
 *
 * - the peak: the largest bar, shown when it exceeds 1.5 × the median;
 * - the dip: the longest run of consecutive bars below 0.5 × the median,
 *   labelled with its lowest value to two significant digits;
 * - the failure burst: a run of at least 3 consecutive minutes with 2 or more
 *   failures each; the one with the most failures, with its sum and minutes.
 */
export function throughputAnnotations(buckets, bucketSeconds = 60) {
    const complete = buckets.filter(bucket => !bucket.partial).sort((a, b) => a.at - b.at);
    const values = complete.filter(bucket => bucket.completed !== null).map(bucket => bucket.completed);
    const middle = median(values);
    const annotations = [];

    if (values.length >= 3 && middle !== null) {
        const peak = complete.reduce((best, bucket) => (bucket.completed !== null && (!best || bucket.completed > best.completed) ? bucket : best), null);

        if (peak && peak.completed > 0 && peak.completed > 1.5 * middle) {
            annotations.push({kind: 'peak', at: peak.at, text: `Peak ${formatCount(peak.completed)}/min`, target: 'main'});
        }

        const dip = lowRun(complete, 'completed', value => value < 0.5 * middle, bucketSeconds, 1);

        if (dip && middle > 0) {
            annotations.push({kind: 'dip', at: dip.from, to: dip.to, text: `Dip to ${approximately(dip.min)}/min`, target: 'main'});
        }
    }

    const burst = lowRun(complete, 'failed', value => value >= 2, bucketSeconds, 3, true);

    if (burst) {
        annotations.push({
            kind: 'burst',
            at: burst.from,
            to: burst.to,
            text: `${formatCount(burst.sum)} failures ${formatTimeRangeZoned(burst.from, burst.to)}`,
            target: 'band',
        });
    }

    return annotations;
}

/**
 * The best run of consecutive buckets whose `key` value passes `test`: the
 * longest run, or with `bySum` the one with the largest sum. A missing or
 * unknown bucket ends a run. { from, to, min, sum, length } or null.
 */
function lowRun(buckets, key, test, bucketSeconds, minLength, bySum = false) {
    let best = null;
    let run = null;
    let previous = null;

    buckets.forEach(bucket => {
        const value = bucket[key];
        const passes = value !== null && test(value);
        const continues = run && previous && bucket.at - previous.at === bucketSeconds;

        if (passes && continues) {
            run.to = bucket.at;
            run.min = Math.min(run.min, value);
            run.sum += value;
            run.length++;
        } else if (passes) {
            run = {from: bucket.at, to: bucket.at, min: value, sum: value, length: 1};
        } else {
            run = null;
        }

        previous = bucket;

        if (run && run.length >= minLength
            && (!best || (bySum ? run.sum > best.sum : run.length > best.length))) {
            best = {...run};
        }
    });

    return best;
}

/**
 * The failure-groups subtitle: "41 in the past hour · 312 still failed ·
 * last 7 days". The past hour is the scan's failed rows ("at least" when
 * truncated), "still failed" the failed count of `GET /api/counts` ({ count,
 * at_least }; null when it is unknown, which shows "—"; undefined leaves it
 * out), and the days the retention of failed rows. A part without data is
 * left out.
 */
export function groupsSubtitle(groupsData, failedTotal) {
    const parts = [];

    if (groupsData && num(groupsData.scanned) !== null) {
        parts.push(`${groupsData.truncated === true ? 'at least ' : ''}${formatCount(num(groupsData.scanned))} in the past hour`);
    }

    if (failedTotal !== undefined) {
        parts.push(stillFailedText(failedTotal));
    }

    const asOf = num(groupsData && groupsData.as_of);
    const retention = num(groupsData && groupsData.retention_from);

    if (asOf !== null && retention !== null) {
        const days = Math.round((asOf - retention) / 86400);

        if (days >= 1) parts.push(`last ${days} ${days === 1 ? 'day' : 'days'}`);
    }

    return parts.join(' · ');
}

/**
 * The frozen selection of a queue control action, taken from the workload
 * row when the user clicks: one signal per alias (aliases are the exotic
 * limit, and a pause or continue from the UI covers each of them).
 * `cacheNote` is the `queue_pause_cache_local` hint of /api/health (a queue
 * pause is Laravel's own, so it reaches only the workers that share the
 * cache store), frozen with the selection, or null.
 */
export function queueControlSelection(action, row, cacheNote = null) {
    return {
        type: 'control',
        action,
        scope: 'queue',
        queue: row.name,
        connections: aliasesOf(row),
        // Frozen with the row: its causes and unknown parts (pauseState()).
        pause: pauseState(row),
        cacheNote: typeof cacheNote === 'string' && cacheNote !== '' ? cacheNote : null,
    };
}

/** The frozen selection of a global control action. */
export function globalControlSelection(action) {
    return {type: 'control', action, scope: 'global', queue: null, connections: []};
}

/** The request bodies of a control selection: one per alias, or one global. */
export function controlRequests(selection) {
    if (selection.scope === 'global') {
        return [{action: selection.action, scope: 'global'}];
    }

    return selection.connections.map(connection => ({
        action: selection.action,
        scope: 'queue',
        connection,
        queue: selection.queue,
    }));
}

/**
 * The notice after a control write, from the answers of its signals: each
 * outcome is { body, data } or { body, failed: true, text, status, data }
 * (text "HTTP 500", "no response"; data the error body). A failed signal
 * names its connection. A 503 `record_failed` is no failed signal: its
 * change happened, so it gives a warning (support/pause.js
 * recordFailedWarning(), as on the Queues screen). A continue is a success
 * only when every answer says that nothing else pauses its target
 * (continueWarning()); a pause or terminate whose signals all answered is
 * sent.
 */
export function controlOutcome(selection, outcomes) {
    const target = selection.scope === 'global'
        ? (selection.action === 'terminate' ? 'all workers' : 'all queues')
        : selection.label;
    const verb = {pause: 'Pause', continue: 'Continue', terminate: 'Terminate'}[selection.action];
    const total = outcomes.length;
    const failures = outcomes.filter(outcome => outcome.failed && !isRecordFailed(outcome));
    const named = body => (body.scope === 'queue' ? `${body.connection}:${body.queue}` : target);

    if (failures.length) {
        const note = recordFailedNote(outcomes, named);

        return {
            tone: 'danger',
            title: failures.length === total
                ? `${verb} ${target} failed.`
                : `${verb} ${target}: ${failures.length} of ${total} signals failed.`,
            detail: failures.map(failure => `${failure.body.connection ? `${failure.body.connection}: ` : ''}${failure.text}`)
                .concat(note === null ? [] : [note]).join(' · '),
        };
    }

    const recorded = recordFailedWarning(`${verb} ${target}`, outcomes, named);

    if (recorded) return recorded;

    if (selection.action === 'continue') {
        const warning = continueWarning(`${verb} ${target}`, outcomes, named);

        if (warning) return warning;
    }

    return {
        tone: 'success',
        title: selection.action === 'terminate'
            ? 'Terminate sent: every worker stops after its current job.'
            : `${verb} sent for ${target}.`,
        detail: selection.connections.length > 1 ? `One signal on each of ${selection.connections.join(', ')}.` : null,
    };
}

/** The words of a control action: the sheet title, the message and the button. */
export function controlSheet(selection, label) {
    const terminate = 'Stops every worker after its current job. Your process manager starts them again.';

    if (selection.scope === 'global') {
        if (selection.action === 'terminate') {
            return {title: 'Terminate all workers…', message: terminate, confirmLabel: 'Terminate all workers', tone: 'danger'};
        }

        // A pause or continue changes exactly its scope: the
        // pauses of connections and single queues stay.
        if (selection.action === 'continue') {
            return {
                title: 'Continue all queues…',
                message: 'Removes the pause of all queues. Pauses of connections and of single queues stay, and so does Laravel\'s queue:pause --all.',
                confirmLabel: 'Continue all queues',
                tone: 'primary',
            };
        }

        return {
            title: 'Pause all queues…',
            message: 'Pauses every queue. Pauses of connections and of single queues stay, so Continue all later leaves them paused.',
            confirmLabel: 'Pause all queues',
            tone: 'primary',
        };
    }

    const on = selection.connections.length > 1 ? ` on ${selection.connections.join(', ')}` : '';

    if (selection.action === 'continue') {
        // A queue Continue lifts the queue's own pause (also one from queue:pause), never a
        // broader one: the sheet names what stays, and lists the commands (nativeContinueCommands()).
        const pause = selection.pause || null;
        const broader = broaderCauses(pause);
        let native = null;

        if (broader.length) {
            native = `Lifts the queue pause of ${label}${on}. It stays paused by ${causesText(broader)}.`;
        } else if (!pause || pause.pausedUnknown || pause.queueCauseUnknown || pause.nativeUnknown) {
            native = `Lifts the pause of ${label}${on}. Whether another pause also holds ${label} is unknown, so workers may still not take its jobs.`;
        }

        return {
            title: `Continue ${label}…`,
            message: native || `Workers take jobs from ${label}${on} again.`,
            confirmLabel: `Continue ${label}`,
            tone: 'primary',
        };
    }

    return {
        title: `Pause ${label}…`,
        message: `Workers finish their current job and take no new jobs from ${label}${on} until you continue.`
            + (selection.cacheNote ? ` ${selection.cacheNote}` : ''),
        confirmLabel: `Pause ${label}`,
        tone: 'primary',
    };
}

/**
 * The commands that lift a pause that Orrery's Continue cannot lift, for a
 * frozen control selection: Laravel's global pause needs "php artisan
 * queue:continue --all". [] when there is none.
 */
export function nativeContinueCommands(selection) {
    if (!selection || selection.action !== 'continue') return [];

    return pauseCommands(selection.pause || null);
}

/**
 * The error line of a failed GET /api/control: the Overview's Pause all and
 * Continue all follow the last signal list read (`control`), or none.
 */
export function controlErrorText(control) {
    return control
        ? 'The pause state could not be refreshed. Pause all and Continue all follow the last state read.'
        : 'The pause state could not be loaded. Pause all and Continue all may not match it.';
}

/** Whether a control signal list (GET /api/control) has a global pause. */
export function hasGlobalPause(signals) {
    return Array.isArray(signals) && signals.some(signal => signal && signal.signal === 'pause' && signal.scope === 'global');
}
