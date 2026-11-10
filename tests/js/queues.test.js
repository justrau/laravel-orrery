// Tests of resources/js/screens/queues/queues.js. `pnpm test` runs in UTC.

import {test} from 'node:test';
import assert from 'node:assert/strict';

import {
    cardName,
    backlogStatus,
    clearCountText,
    clearFailure,
    clearOutcome,
    clearSheet,
    connectionSections,
    controlBodies,
    controlNotice,
    controlConfirmLabel,
    controlMessage,
    controlTitle,
    currentJob,
    diffText,
    FAILURE_ROWS,
    FAILURE_WINDOW_SECONDS,
    failureScopes,
    failuresFooter,
    failureWindow,
    findRow,
    GROUP_PAGE_SIZE,
    groupTitle,
    growthSince,
    growthText,
    historyBuckets,
    historyWindow,
    inOutBars,
    inOutText,
    queueRequest,
    limitText,
    markerName,
    markersIn,
    mergeFailures,
    MIN_DIFF,
    normalizeMarkers,
    normalizeRow,
    normalizeWorkload,
    num,
    isPaused,
    pausedFrom,
    pausedTitle,
    keptFor,
    retentionText,
    queueJobsLinks,
    pauseHint,
    queueState,
    rateText,
    retryLabel,
    runLimited,
    runtimeText,
    sectionPausedBroadly,
    sectionWorkersText,
    sharedScale,
    shortName,
    subscribedQueues,
    subtitleText,
    TERMINATE_TEXT,
    waitText,
    workersFor,
    workersHeader,
} from '../../resources/js/screens/queues/queues.js';

// 2026-09-28 09:00:00 UTC
const NOW = 1790586000;

function row(name, extra = {}) {
    return normalizeRow({name, connection: 'database', length: 10, processes: 1, oldest_age: 5, threshold: 60, pauses: [], paused_unknown: false, ...extra});
}

// Rejects: more tasks in flight than the limit, results out of order, and a rejection that stops the rest.
test('runLimited keeps the limit and settles in order', async () => {
    let inFlight = 0;
    let peak = 0;
    const task = (value, fail = false) => async () => {
        inFlight++;
        peak = Math.max(peak, inFlight);
        await new Promise(resolve => setTimeout(resolve, 5));
        inFlight--;

        if (fail) throw new Error(`fail ${value}`);

        return value;
    };

    const results = await runLimited([task(1), task(2, true), task(3), task(4), task(5)], 2);

    assert.equal(peak, 2);
    assert.deepEqual(results.map(result => result.status), ['fulfilled', 'rejected', 'fulfilled', 'fulfilled', 'fulfilled']);
    assert.deepEqual(results.filter(result => result.value).map(result => result.value), [1, 3, 4, 5]);
    assert.equal(results[1].reason.message, 'fail 2');
});

// Rejects: tasks that still start after stop() is true.
test('runLimited skips the tasks after a stop', async () => {
    let started = 0;
    let stopped = false;
    const task = () => async () => { started++; stopped = true; return started; };

    const results = await runLimited([task(), task(), task()], 1, () => stopped);

    assert.equal(started, 1);
    assert.deepEqual(results.map(result => result.status), ['fulfilled', 'rejected', 'rejected']);
    assert.deepEqual(await runLimited([], 4), []);
});

// Rejects: Number(), which turns '' into 0.
test('num reads numbers and numeric strings only', () => {
    assert.equal(num('7'), 7);
    assert.equal(num(''), null);
});

// Rejects: 0 for an unknown ready count or age, and a pause read that failed shown as no pause.
test('normalizeRow keeps unknowns as null', () => {
    const normalized = normalizeRow({
        name: 'emails', connection: 'database', connections: ['database', 'alt', 5],
        length: '12', processes: null, oldest_age: null, threshold: null,
        over_limit: false, over_limit_since: NOW, running: '2', in_per_min: null, out_per_min: '4.5',
        pauses: [], paused_unknown: true,
    });

    assert.equal(normalized.key, 'database:emails');
    assert.deepEqual(normalized.connections, ['database', 'alt']);
    assert.equal(normalized.ready, 12);
    assert.equal(normalized.workers, null);
    assert.equal(normalized.oldestAge, null);
    assert.equal(normalized.threshold, 0);
    assert.equal(normalized.overLimitSince, null, 'a start only while over the limit');
    assert.equal(normalized.running, 2);
    assert.equal(normalized.inPerMin, null);
    assert.equal(normalized.outPerMin, 4.5);
    assert.equal(normalized.pause.pausedUnknown, true);
    assert.equal(normalized.pause.knownOff, false);
    assert.equal(normalized.backlogUnknown, false);
});

// Rejects: an unknown backlog read as an empty queue, and a row without a pauses list read as running.
test('normalizeRow keeps the unknown backlog and an absent pause list', () => {
    const unknown = normalizeRow({name: 'q', connection: 'c', length: null, oldest_age: null, over_limit: null, backlog_unknown: true});

    assert.equal(unknown.backlogUnknown, true);
    assert.equal(unknown.ready, null);
    assert.equal(unknown.overLimit, false);
    assert.equal(unknown.pause.knownOff, false, 'no pauses list: unknown');
    assert.equal(normalizeRow({name: 'q', connection: 'c', pauses: []}).pause.knownOff, true);
});

// Rejects: a cause with a string time kept as a string, an empty actor kept, and an invalid cause kept.
test('normalizeRow reads the causes and drops an invalid row', () => {
    const paused = normalizeRow({name: 'q', connection: 'c', pauses: [{scope: 'global', source: 'orrery', issued_at: '100', issued_by: '', possible: false}, {scope: 'x', source: 'orrery'}], paused_unknown: true});

    assert.deepEqual(paused.pause.definite, [{scope: 'global', source: 'orrery', issuedAt: 100, issuedBy: null, possible: false}]);
    assert.equal(paused.pause.paused, true);
    assert.equal(isPaused(paused), true);
    assert.equal(normalizeRow({name: 'q'}), null);
    assert.equal(normalizeRow(null), null);

    const over = normalizeRow({name: 'q', connection: 'c', over_limit: true, over_limit_since: '50', over_limit_since_censored: true});
    assert.equal(over.overLimitSince, 50);
    assert.equal(over.overLimitCensored, true);
});

// Rejects: null rows in the result.
test('normalizeWorkload drops invalid rows', () => {
    assert.deepEqual(normalizeWorkload([{name: 'a', connection: 'c'}, {name: 'b'}, null]).map(entry => entry.name), ['a']);
    assert.deepEqual(normalizeWorkload(null), []);
});

// Rejects: an alias match that wins over the primary connection.
test('findRow finds the primary connection first, then an alias', () => {
    const rows = [
        normalizeRow({name: 'q', connection: 'a', connections: ['a', 'b']}),
        normalizeRow({name: 'q', connection: 'b'}),
    ];

    assert.equal(findRow(rows, 'b', 'q'), rows[1]);
    assert.equal(findRow(rows.slice(0, 1), 'b', 'q'), rows[0]);
    assert.equal(findRow(rows, 'c', 'q'), null);
    assert.equal(findRow(rows, null, 'q'), null);
});

// Rejects: one section per row, and aliases that are lost.
test('connectionSections groups rows by connection and collects aliases', () => {
    const sections = connectionSections([
        normalizeRow({name: 'a', connection: 'x', connections: ['x', 'y']}),
        normalizeRow({name: 'b', connection: 'z'}),
        normalizeRow({name: 'c', connection: 'x'}),
    ]);

    assert.deepEqual(sections.map(section => [section.connection, section.aliases, section.rows.map(entry => entry.name)]), [
        ['x', ['x', 'y'], ['a', 'c']],
        ['z', ['z'], ['b']],
    ]);
});

// Rejects: a ready sum that counts an unknown row as zero.
test('subtitleText leaves out the ready sum when a count is unknown', () => {
    assert.equal(subtitleText([row('a', {length: 10000}), row('b', {length: 178, over_limit: true})]), '2 on database · 10,178 ready · 1 over its limit');
    assert.equal(subtitleText([row('a'), row('b', {length: null, connection: 'other'})]), '2 queues on 2 connections · 0 over its limit');
    // An unknown backlog is not "within its limit": it is counted apart.
    assert.equal(
        subtitleText([row('a', {over_limit: true}), row('b', {length: null, over_limit: null, backlog_unknown: true})]),
        '2 on database · at least 1 over its limit · 1 backlog unknown',
    );
    assert.equal(subtitleText([]), null);
});

// Rejects: a definite "0 over its limit" (or "1 over its limit") while a backlog is unknown,
// and a subtitle that drops the over-limit count when every backlog is known.
test('subtitleText never states a definite over-limit count while a backlog is unknown', () => {
    const unknown = row('b', {length: null, over_limit: null, backlog_unknown: true});

    assert.equal(subtitleText([row('a'), unknown, row('c', {length: null, over_limit: null, backlog_unknown: true})]), '3 on database · 2 backlog unknown');
    assert.equal(subtitleText([row('a', {over_limit: true}), row('c', {over_limit: true}), unknown]), '3 on database · at least 2 over its limit · 1 backlog unknown');
    assert.equal(subtitleText([row('a', {length: 4}), row('c', {length: 5})]), '2 on database · 9 ready · 0 over its limit');
});

const cause = (scope, source = 'orrery', extra = {}) => ({scope, source, issued_at: null, issued_by: null, possible: false, ...extra});

// Rejects: a pause of a broader scope shown as a queue pause, and an actor without the time.
test('queueState of a paused queue names the scope and the actor', () => {
    assert.deepEqual(
        queueState(row('q', {pauses: [cause('queue', 'orrery', {issued_at: NOW - 120, issued_by: 'ops@example.com'})]}), NOW),
        {kind: 'paused', label: 'Paused', detail: 'by ops@example.com · 2 min ago'},
    );
    assert.deepEqual(
        queueState(row('q', {pauses: [cause('global', 'orrery', {issued_by: 'cli'})]}), NOW),
        {kind: 'paused', label: 'Paused (all queues)', detail: 'by cli'},
    );
    assert.deepEqual(
        queueState(row('q', {pauses: [cause('connection', 'orrery', {issued_at: NOW - 5})]}), NOW),
        {kind: 'paused', label: 'Paused (connection)', detail: '5 s ago'},
    );
});

// Rejects: a failed signal read shown as running, and an over-limit state that hides the unknown pause.
test('queueState of an unknown pause state is never running', () => {
    assert.deepEqual(queueState(row('q', {paused_unknown: true}), NOW), {kind: 'unknown', label: 'Pause state unknown', detail: null});
    // The state order of the sidebar: over the limit before an unknown pause, which the detail names.
    assert.deepEqual(
        queueState(row('q', {paused_unknown: true, over_limit: true, over_limit_since: NOW}), NOW),
        {kind: 'over', label: 'Over limit', detail: 'since 09:00 UTC · pause state unknown'},
    );
    assert.deepEqual(
        queueState(row('q', {paused_unknown: true, over_limit: true}), NOW),
        {kind: 'over', label: 'Over limit', detail: 'pause state unknown'},
    );
});

// Rejects: an unknown backlog shown as running or within the limit, and an unknown worker count shown as "No workers".
test('queueState of an unknown backlog or unknown workers is unknown', () => {
    assert.deepEqual(
        queueState(row('q', {length: null, oldest_age: null, over_limit: null, backlog_unknown: true}), NOW),
        {kind: 'unknown', label: 'Backlog unknown', detail: null},
    );
    assert.deepEqual(queueState(row('q', {processes: null}), NOW), {kind: 'unknown', label: 'Workers unknown', detail: null});
    assert.deepEqual(queueState(null, NOW), {kind: 'unknown', label: 'Unknown', detail: null});
});

// Rejects: Laravel's pause read as running, a label that hides one of several causes, and Laravel's global pause without its command.
test('every cause shows: Laravel\'s queue pause, its global pause, and Orrery\'s', () => {
    const native = row('emails', {pauses: [cause('queue', 'queue:pause')], over_limit: true});
    assert.deepEqual(queueState(native, NOW), {kind: 'paused', label: 'Paused (queue:pause)', detail: null});
    assert.equal(pauseHint(native), null, 'Orrery\'s Continue lifts a queue:pause');

    const both = row('emails', {pauses: [cause('global', 'orrery', {issued_at: NOW - 60, issued_by: 'cli'}), cause('queue', 'queue:pause')]});
    assert.deepEqual(queueState(both, NOW), {kind: 'paused', label: 'Paused (all queues, queue:pause)', detail: 'by cli · 1 min ago'});

    const all = row('emails', {pauses: [cause('global', 'queue:pause --all')], queue_cause_unknown: true});
    assert.equal(queueState(all, NOW).label, 'Paused (queue:pause --all)');
    assert.equal(pauseHint(all), "Resume with php artisan queue:continue --all · Own pause state unknown while Laravel's global pause is on");

    assert.equal(pauseHint(row('emails', {pauses: [cause('queue')]})), null);
    // The health hint is the server's text (it words the file and the array store).
    assert.equal(pauseHint(row('emails'), 'The cache store keeps its values in one process (array).'), 'The cache store keeps its values in one process (array).');
    assert.equal(pauseHint(row('emails'), ''), null);
    assert.equal(pauseHint(null), null);
    // A definite cause wins over an unknown part.
    assert.equal(queueState(row('q', {pauses: [cause('queue')], paused_unknown: true}), NOW).kind, 'paused');
});

// An Orrery queue pause whose Laravel flag is not read is only possible.
// Rejects: a possible pause shown as "Paused", and one that hides an over-limit queue.
test('a possible pause is never Paused and never hides an over-limit queue', () => {
    const possible = {pauses: [cause('queue', 'orrery', {possible: true, issued_by: 'cli'})], native_unknown: true};

    assert.deepEqual(queueState(row('q', possible), NOW), {
        kind: 'unknown', label: 'Possibly paused', detail: "Laravel's pause state unknown; a queue:continue run from the command line is not seen",
    });
    assert.deepEqual(queueState(row('q', {...possible, over_limit: true}), NOW), {
        kind: 'over', label: 'Over limit', detail: "possibly paused · Laravel's pause state unknown; a queue:continue run from the command line is not seen",
    });
    assert.equal(isPaused(row('q', possible)), false);
});

// Gate fix (rollback mode): an Orrery queue pause whose Laravel flag cannot be read, on a queue whose backlog cannot be
// read either. Rejects: "Backlog unknown" that hides the possible pause, a possible pause shown as "Paused", and a
// detail without the unknown backlog or the hint. It still passes a State cell that names no actor of the possible pause.
test('a possible pause over an unknown backlog shows as Possibly paused', () => {
    const possible = {pauses: [cause('queue', 'orrery', {possible: true, issued_by: 'cli'})], native_unknown: true};
    const unknownBacklog = {length: null, oldest_age: null, over_limit: null, backlog_unknown: true};

    assert.deepEqual(queueState(row('idle', {...possible, ...unknownBacklog}), NOW), {
        kind: 'unknown', label: 'Possibly paused', detail: "backlog unknown · Laravel's pause state unknown; a queue:continue run from the command line is not seen",
    });
    assert.deepEqual(queueState(row('idle', {native_unknown: true, ...unknownBacklog}), NOW), {
        kind: 'unknown', label: 'Backlog unknown', detail: "Laravel's pause state unknown; a queue:continue run from the command line is not seen",
    }, 'an unread flag without a possible pause stays "Backlog unknown", with the hint');
    assert.equal(isPaused(row('idle', {...possible, ...unknownBacklog})), false);
});

// Review nit. Rejects: an unread pause state hidden behind "Backlog unknown" without a word.
test('an unknown pause state over an unknown backlog names the pause state', () => {
    assert.deepEqual(queueState(row('q', {paused_unknown: true, length: null, oldest_age: null, over_limit: null, backlog_unknown: true}), NOW), {
        kind: 'unknown', label: 'Backlog unknown', detail: 'pause state unknown',
    });
});

// Rejects: an unknown native state (a masked or unread flag, or no pauses list) read as running.
test('an unknown native state is never Running', () => {
    assert.deepEqual(queueState(row('q', {native_unknown: true}), NOW), {kind: 'unknown', label: 'Pause state unknown', detail: "Laravel's pause state unknown; a queue:continue run from the command line is not seen"});
    assert.deepEqual(
        queueState(normalizeRow({name: 'q', connection: 'database', processes: 3, threshold: 60}), NOW),
        {kind: 'unknown', label: 'Pause state unknown', detail: null},
    );
    assert.equal(queueState(row('q', {native_unknown: true, processes: 0}), NOW).kind, 'unknown');
});

// Rejects: an idle queue with zero workers shown as running, and a censored start without "at least".
test('queueState of an unpaused queue: over, idle, running', () => {
    assert.deepEqual(queueState(row('q', {over_limit: true, over_limit_since: NOW, over_limit_since_censored: true}), NOW),
        {kind: 'over', label: 'Over limit', detail: 'at least since 09:00 UTC'});
    // A start of an earlier day carries its date.
    assert.equal(queueState(row('q', {over_limit: true, over_limit_since: NOW - 86400}), NOW).detail, 'since 2026-09-27 09:00 UTC');
    assert.deepEqual(queueState(row('q', {over_limit: true}), NOW), {kind: 'over', label: 'Over limit', detail: null});
    assert.deepEqual(queueState(row('q', {processes: 0}), NOW), {kind: 'idle', label: 'No workers', detail: null});
    assert.deepEqual(queueState(row('q', {processes: 3}), NOW), {kind: 'running', label: 'Running', detail: null});
});

// Rejects: a queue pause offered as a broad pause, Orrery's or Laravel's global pause offered as the connection's (a
// connection continue cannot lift it), and a tint without a pause.
test('sectionPausedBroadly and pausedFrom', () => {
    assert.equal(sectionPausedBroadly({rows: [row('a', {pauses: [cause('queue')]}), row('b')]}), false);
    assert.equal(sectionPausedBroadly({rows: [row('a', {pauses: [cause('connection')]})]}), true);
    assert.equal(sectionPausedBroadly({rows: [row('a', {pauses: [cause('global')]})]}), false);
    assert.equal(sectionPausedBroadly({rows: [row('a', {pauses: [cause('global'), cause('connection')]})]}), true);
    assert.equal(sectionPausedBroadly({rows: [row('a', {pauses: [cause('global', 'queue:pause --all')]})]}), false);

    const window = {from: NOW - 3600, to: NOW};
    assert.equal(pausedFrom(row('a', {pauses: [cause('queue', 'orrery', {issued_at: NOW - 60})]}), window), NOW - 60);
    assert.equal(pausedFrom(row('a', {pauses: [cause('queue', 'queue:pause')]}), window), NOW - 3600);
    assert.equal(pausedFrom(row('a'), window), null);
});

// Rejects: "limit 0 s", and "0 s" for an unknown wait.
test('limitText and waitText', () => {
    assert.equal(limitText(60), 'limit 60 s');
    assert.equal(limitText(0), 'No limit');
    assert.equal(waitText(row('q', {oldest_age: 134})), '2 min 14 s');
    assert.equal(waitText(row('q', {oldest_age: null})), '—');
});

// Rejects: 0 for an unknown rate, and decimals on a large rate.
test('rateText and inOutText', () => {
    assert.equal(rateText(615.4), '615');
    assert.equal(rateText(4.24), '4.2');
    assert.equal(rateText(0), '0');
    assert.equal(rateText(1234.6), '1,235');
    assert.equal(rateText(null), '—');
    assert.equal(rateText(undefined), '—');
    assert.equal(inOutText(row('q', {in_per_min: 615, out_per_min: null})), '615 / —');
});

// Rejects: a null rate drawn as an empty bar, and a difference below the display precision.
test('inOutBars draws both rates on one scale', () => {
    assert.deepEqual(inOutBars(200, 100), {in: 1, out: 0.5, excess: 0.5, diff: 100});
    assert.deepEqual(inOutBars(100, 200), {in: 0.5, out: 1, excess: 0, diff: null});
    assert.deepEqual(inOutBars(null, 50), {in: null, out: 1, excess: 0, diff: null});
    assert.deepEqual(inOutBars(0, 0), {in: 0, out: 0, excess: 0, diff: null});
    assert.equal(inOutBars(1 + MIN_DIFF / 2, 1).diff, null);
    assert.equal(diffText(127), '+127/min more in than out');
    assert.equal(diffText(null), null);
});

// Rejects: a worker on another connection, and a worker that does not subscribe to the queue.
test('subscribedQueues and workersFor', () => {
    assert.deepEqual(subscribedQueues({subscribed_queues: ['a', 'b']}), ['a', 'b']);
    assert.deepEqual(subscribedQueues({queue: 'high, default,'}), ['high', 'default']);
    assert.deepEqual(subscribedQueues({}), []);

    const target = row('default', {connections: ['database', 'alt']});
    const workers = [
        {name: 'w2', connection: 'alt', queue: 'high,default'},
        {name: 'w1', connection: 'database', subscribed_queues: ['default']},
        {name: 'w3', connection: 'other', queue: 'default'},
        {name: 'w4', connection: 'database', queue: 'high'},
        null,
    ];

    assert.deepEqual(workersFor(workers, target).map(worker => worker.name), ['w1', 'w2']);
    assert.deepEqual(workersFor(null, target), []);
});

// Rejects: workers of other connections, and "0 workers" for an unknown list.
test('sectionWorkersText counts the workers and hosts of the aliases', () => {
    const workers = [{connection: 'a', host: 'h1'}, {connection: 'b', host: 'h2'}, {connection: 'a', host: 'h1'}, {connection: 'c', host: 'h3'}];

    assert.equal(sectionWorkersText(workers, ['a', 'b']), '3 workers on 2 hosts');
    assert.equal(sectionWorkersText(workers, ['c']), '1 worker on 1 host');
    assert.equal(sectionWorkersText(null, ['a']), 'workers unknown');
});

// Rejects: an unknown current job shown as idle, and a negative runtime from clock skew.
test('currentJob is null when unknown', () => {
    assert.deepEqual(currentJob({current_job: {name: 'App\\Jobs\\Sync', started_at: String(NOW - 2.25)}}, NOW), {name: 'Sync', fullName: 'App\\Jobs\\Sync', runtime: '2.2 s'});
    assert.equal(currentJob({current_job: {name: 'J', started_at: NOW + 5}}, NOW).runtime, '0.0 s');
    assert.equal(currentJob({current_job: {name: 'J', started_at: null}}, NOW).runtime, '—');
    assert.equal(currentJob({current_job: null}, NOW), null);
    assert.equal(currentJob(null, NOW), null);
});

// Rejects: a rounded runtime (1.3 s for 1.29 s), and an empty name shown as empty.
test('runtimeText and shortName', () => {
    assert.equal(runtimeText(1.29), '1.2 s');
    assert.equal(runtimeText(134), '2 min 14 s');
    assert.equal(runtimeText(null), '—');
    assert.equal(runtimeText(-1), '—');
    assert.equal(shortName('App\\Jobs\\Sync'), 'Sync');
    assert.equal(shortName(undefined), '—');
});

// Rejects: the oldest heartbeat, and "heartbeat NaN s ago".
test('workersHeader names the freshest heartbeat', () => {
    assert.equal(workersHeader([{seconds_ago: 30}, {seconds_ago: '11.6'}], 'emails'), '2 serve emails · heartbeat 12 s ago');
    assert.equal(workersHeader([{seconds_ago: null}], 'emails'), '1 serve emails');
    assert.equal(workersHeader([], 'emails'), 'No worker serves emails');
    // The age grows from last_seen_at between reads, and a worker clock ahead of the server's is never negative.
    assert.equal(workersHeader([{seconds_ago: 2, last_seen_at: NOW - 2}], 'emails', NOW + 40), '1 serve emails · heartbeat 42 s ago');
    assert.equal(workersHeader([{seconds_ago: -2, last_seen_at: NOW + 2}], 'emails', NOW), '1 serve emails · heartbeat 0 s ago');
});

// Rejects: a null ready count turned into 0 (a gap is unknown, never zero).
test('historyBuckets keeps a null ready count as a gap', () => {
    assert.deepEqual(historyBuckets({buckets: [{at: 60, ready: null}, {at: 120, ready: 'x'}]}), [
        {at: 60, ready: null, partial: false},
        {at: 120, ready: null, partial: false},
    ]);
});

// Rejects: a string time or count that is lost, and a bucket without a start that is kept at 0.
test('historyBuckets coerces numbers and drops buckets without a start', () => {
    assert.deepEqual(historyBuckets({buckets: [
        {at: '60', ready: '12', partial: true},
        {at: null, ready: 5},
        {ready: 5},
        null,
        {at: 180, ready: 0},
    ]}), [
        {at: 60, ready: 12, partial: true},
        {at: 180, ready: 0, partial: false},
    ]);
});

// Rejects: a throw on a response without buckets.
test('historyBuckets of a response without buckets is empty', () => {
    assert.deepEqual(historyBuckets(null), []);
    assert.deepEqual(historyBuckets({buckets: 'x'}), []);
});

// Rejects: a window with to <= from, and 0 for a missing from.
test('historyWindow needs a valid window', () => {
    assert.deepEqual(historyWindow({from: '0', to: 3600, retention_from: null}), {from: 0, to: 3600, retentionFrom: null});
    assert.deepEqual(historyWindow({from: 0, to: 3600, retention_from: '10'}), {from: 0, to: 3600, retentionFrom: 10});
    assert.equal(historyWindow({from: 3600, to: 3600}), null);
    assert.equal(historyWindow({from: null, to: 3600}), null);
    assert.equal(historyWindow(null), null);
});

// Rejects: a scale of 0, and a null ready count read as a value.
test('sharedScale takes the largest known ready count, at least 1', () => {
    assert.equal(sharedScale({a: {buckets: [{ready: 5}, {ready: null}]}, b: {buckets: [{ready: 40}]}}), 40);
    assert.equal(sharedScale({a: {buckets: [{ready: 0}]}}), 1);
    assert.equal(sharedScale(null), 1);
});

// Rejects: newest first, and markers of an unknown kind or without a time.
test('normalizeMarkers keeps known kinds, oldest first', () => {
    assert.deepEqual(normalizeMarkers({markers: [
        {kind: 'deploy', at: '200', label: 'v2'},
        {kind: 'restart', at: 100, label: 7},
        {kind: 'other', at: 50},
        {kind: 'deploy', at: null},
    ]}), [
        {kind: 'restart', at: 100, label: null},
        {kind: 'deploy', at: 200, label: 'v2'},
    ]);
    assert.deepEqual(normalizeMarkers(null), []);
});

// Rejects: a closed window that keeps a marker at `to`.
test('markerName and markersIn', () => {
    assert.equal(markerName({kind: 'deploy', label: 'v2'}), 'Deploy v2');
    assert.equal(markerName({kind: 'restart'}), 'Restart requested');
    assert.equal(markerName(null), '');

    const markers = [{at: 0}, {at: 60}, {at: 120}];
    assert.deepEqual(markersIn(markers, {from: 0, to: 120}).map(marker => marker.at), [0, 60]);
    assert.deepEqual(markersIn(markers, null), []);
});

// Rejects: a growth from an unknown marker bucket, and a growth from the first marker instead of the latest.
test('growthSince measures from the latest marker in the window', () => {
    const buckets = [{at: 0, ready: 5}, {at: 60, ready: 10}, {at: 120, ready: null}, {at: 180, ready: 40}];
    const window = {from: 0, to: 240};

    const growth = growthSince(buckets, [{at: 10, kind: 'restart'}, {at: 75, kind: 'deploy'}], window);
    assert.deepEqual({then: growth.then, now: growth.now, change: growth.change, at: growth.at}, {then: 10, now: 40, change: 30, at: 75});

    assert.equal(growthSince(buckets, [{at: 130}], window), null, 'the marker bucket is unknown');
    assert.equal(growthSince(buckets, [{at: 300}], window), null, 'no marker in the window');
    assert.equal(growthSince([{at: 0, ready: 5}], [{at: 70}], window), null, 'no bucket at the marker');
});

// Rejects: a hyphen instead of a minus sign, and no sign for a growth.
test('growthText signs the change', () => {
    assert.equal(growthText({change: 7587, at: NOW + 14 * 60}), '+7,587 since 09:14 UTC');
    assert.equal(growthText({change: -12, at: NOW}), '−12 since 09:00 UTC');
    assert.equal(growthText({change: 0, at: NOW}), '+0 since 09:00 UTC');
    assert.equal(growthText(null), null);
});

// Rejects: a fractional `to`.
test('failureWindow is the past hour to the whole second', () => {
    assert.deepEqual(failureWindow(NOW + 0.7), {from: NOW - FAILURE_WINDOW_SECONDS, to: NOW});
});

// Rejects: only the primary connection, and an empty list without a row.
test('failureScopes gives one scope per alias, or all queues', () => {
    assert.deepEqual(failureScopes(row('emails', {connections: ['database', 'alt']})), ['database:emails', 'alt:emails']);
    assert.deepEqual(failureScopes(null), [null]);
});

// Rejects: a merged list that is complete although one scan was truncated or one page was full.
test('mergeFailures merges the scopes newest first and marks an incomplete list', () => {
    const merged = mergeFailures([
        {scopeQueue: 'database:emails', data: {retention_from: '100', bucket_seconds: 60, groups: [
            {key: 'k1', count: '3', last_failed_at: '500', first_failed_at: 400},
            {key: 'k2', count: 1, last_failed_at: 900},
            {count: 1},
        ]}},
        {scopeQueue: 'alt:emails', data: {truncated: true, retention_from: 200, groups: [{key: 'k1', count: 2, last_failed_at: 700}]}},
    ]);

    assert.deepEqual(merged.groups.map(group => group.rowKey), ['database:emails|k2', 'alt:emails|k1', 'database:emails|k1']);
    assert.equal(merged.groups[2].count, 3);
    assert.equal(merged.groups[2].first_failed_at, 400);
    assert.equal(merged.groups[1].atLeast, false, 'the scan stopped, but the group count is exact');
    assert.equal(merged.groups[1].bucketSeconds, 60);
    assert.equal(merged.incomplete, true);
    assert.equal(merged.retentionFrom, 200);

    const full = {scopeQueue: null, data: {groups: Array.from({length: GROUP_PAGE_SIZE}, (_, index) => ({key: `k${index}`}))}};
    assert.equal(mergeFailures([full]).incomplete, true);
    assert.equal(mergeFailures([{scopeQueue: null, data: {groups: []}}]).incomplete, false);
    assert.equal(mergeFailures([{scopeQueue: null, data: null}]).retentionFrom, null);
});

// Rejects: a capped count without the mark, the old "up to 200" cap of the explicit-list retry (a bulk run takes
// the whole group), "null" for an unknown count, and the run-together "×≥ 27".
test('retryLabel and groupTitle mark a lower-bound count', () => {
    assert.equal(retryLabel({count: 27, atLeast: false}), 'Retry 27…');
    assert.equal(retryLabel({count: 1027, atLeast: true}), 'Retry ≥ 1,027…');
    assert.equal(retryLabel({count: 1027, atLeast: false}), 'Retry 1,027…');
    assert.equal(retryLabel({count: null}), 'Retry…');
    assert.equal(groupTitle({exception_class: 'App\\TransportException', count: 27, atLeast: false}), 'TransportException ×27');
    assert.equal(groupTitle({exception_class: 'E', count: 27, atLeast: true}), 'E × ≥27');
    assert.equal(groupTitle({exception_class: 'E', count: null}), 'E');
});

// Rejects: the scan's `truncated` as the count qualifier, and a capped count without "at least".
test('mergeFailures marks the lower-bound counts per group', () => {
    const merged = mergeFailures([{scopeQueue: null, data: {truncated: true, groups: [
        {key: 'capped', count: 5000, count_capped: true, exact: false, last_failed_at: 3},
        {key: 'fallback', count: 12, count_capped: false, exact: false, last_failed_at: 2},
        {key: 'exact', count: 4, count_capped: false, exact: true, last_failed_at: 1},
    ]}}]);

    assert.deepEqual(merged.groups.map(group => [group.key, group.atLeast]), [['capped', true], ['fallback', true], ['exact', false]]);
    assert.equal(merged.incomplete, true);
});

// Rejects: "No other failures" when more groups may exist, and a past-hour claim beyond the retention horizon.
test('failuresFooter claims no other failures only for a complete list', () => {
    assert.equal(failuresFooter([], false, 'emails'), 'No failures on emails in the past hour');
    assert.equal(failuresFooter([{}], false, 'emails'), 'No other failures on emails in the past hour');
    assert.equal(failuresFooter([{}], true, 'emails'), null);
    assert.equal(failuresFooter(new Array(FAILURE_ROWS + 1).fill({}), false, 'emails'), null);
    assert.equal(
        failuresFooter([], false, 'emails', {from: NOW - 3600, to: NOW}, NOW - 600),
        'No failures on emails since 08:50 UTC (older failures are not kept)',
    );
    assert.equal(failuresFooter([], false, 'emails', {from: NOW - 3600, to: NOW}, NOW - 7200), 'No failures on emails in the past hour');
});

// Rejects: one body for a merged row, and a connection body with a queue.
// Rejects: a green "sent" for a continue whose answer names a cause that still pauses the target, a continue whose
// remaining causes are unknown shown as a success, the failure counts lost, and a pause read for still_paused_by.
test('controlNotice words the result from the answers', () => {
    const selection = {scope: 'connection', action: 'continue', label: 'database', connections: ['database']};
    const body = {action: 'continue', scope: 'connection', connection: 'database'};
    const answer = extra => ({body, data: {removed: 1, still_paused_by: [], still_paused_unknown: false, lift_unknown: false, ...extra}});

    assert.deepEqual(controlNotice(selection, [answer()]),
        {tone: 'success', title: 'Continue all queues on database: sent.', detail: '1 signal: database.'});

    assert.deepEqual(controlNotice(selection, [answer({removed: 0, still_paused_by: [
        {scope: 'global', value: '*', source: 'orrery'},
        {scope: 'global', value: '*', source: 'queue:pause --all'},
    ]})]), {
        tone: 'warning',
        title: 'Continue all queues on database: still paused.',
        detail: "database stays paused by the pause of all queues and Laravel's global pause (queue:continue --all).",
    });

    const queue = {scope: 'queue', action: 'continue', queue: 'emails', connections: ['database']};
    const queueBody = {action: 'continue', scope: 'queue', connection: 'database', queue: 'emails'};
    assert.deepEqual(controlNotice(queue, [{body: queueBody, data: {removed: 1, still_paused_by: [{scope: 'queue', value: 'database:emails', source: 'queue:pause'}]}}]).detail,
        "database:emails stays paused by Laravel's queue pause (queue:continue database:emails).");
    assert.deepEqual(controlNotice(queue, [{body: queueBody, data: {removed: 1, still_paused_by: [], still_paused_unknown: true}}]), {
        tone: 'warning',
        title: 'Continue emails: sent; the pause state is unknown.',
        detail: "Laravel's pause state of database:emails could not be read: it may still be paused.",
    });
    assert.equal(controlNotice(queue, [{body: queueBody, data: {}}]).tone, 'warning', 'an answer without the list is unknown, never a success');

    const failed = controlNotice(selection, [{body, failed: true, status: 500}]);
    assert.deepEqual(failed, {tone: 'danger', title: 'Continue all queues on database: 1 of 1 signal failed.', detail: 'database: HTTP 500'});

    const pause = {scope: 'connection', action: 'pause', label: 'database', connections: ['database']};
    assert.equal(controlNotice(pause, [{body: {action: 'pause', scope: 'connection', connection: 'database'}, data: {}}]).tone, 'success');
});

// Rejects: a 503 `record_failed` (Laravel's part happened; only Orrery's record failed) counted as a failed signal in
// the danger tone, and a continue whose other pauses could not be read called a success.
// Still passes: any title that keeps the word "done" and the warning tone.
test('controlNotice words a change whose Orrery record failed as a warning', () => {
    const queue = {scope: 'queue', action: 'continue', queue: 'emails', connections: ['database']};
    const body = {action: 'continue', scope: 'queue', connection: 'database', queue: 'emails'};
    const notice = controlNotice(queue, [{body, failed: true, status: 503, data: {error: 'record_failed', paused: false}}]);

    assert.equal(notice.tone, 'warning');
    assert.equal(notice.title, "Continue emails: done; Orrery's record of it was not saved.");
    assert.match(notice.detail, /^Laravel's queue pause of database:emails is lifted; whether another pause still holds it is unknown\. /);

    const pause = {...queue, action: 'pause'};
    assert.equal(controlNotice(pause, [{body: {...body, action: 'pause'}, failed: true, status: 503, data: {error: 'record_failed', paused: true}}]).tone, 'warning');
    assert.equal(controlNotice(pause, [{body: {...body, action: 'pause'}, failed: true, status: 503, data: {error: 'write_failed'}}]).tone, 'danger');
});

test('controlBodies sends one body per alias', () => {
    assert.deepEqual(controlBodies({scope: 'queue', action: 'pause', queue: 'emails', connections: ['a', 'b']}), [
        {action: 'pause', scope: 'queue', connection: 'a', queue: 'emails'},
        {action: 'pause', scope: 'queue', connection: 'b', queue: 'emails'},
    ]);
    assert.deepEqual(controlBodies({scope: 'connection', action: 'continue', connections: ['a']}), [
        {action: 'continue', scope: 'connection', connection: 'a'},
    ]);
});

// Rejects: a queue terminate that hides that shared workers stop too, and an empty label for an unknown action.
test('controlTitle, controlMessage and controlConfirmLabel name the target', () => {
    const queue = {scope: 'queue', queue: 'emails', connections: ['a']};
    const connection = {scope: 'connection', label: 'database', connections: ['a']};

    assert.equal(controlTitle({...queue, action: 'pause'}), 'Pause emails');
    assert.equal(controlTitle({...queue, action: 'continue'}), 'Continue emails');
    assert.equal(controlTitle({...queue, action: 'terminate'}), 'Terminate emails workers');
    assert.equal(controlTitle({...connection, action: 'pause'}), 'Pause all queues on database');
    assert.equal(controlTitle({...connection, action: 'continue'}), 'Continue all queues on database');
    assert.equal(controlTitle({...connection, action: 'terminate'}), 'Terminate all workers on database');

    assert.equal(controlMessage({...queue, action: 'terminate'}), `${TERMINATE_TEXT} Workers that also serve other queues stop too.`);
    assert.equal(controlMessage({...connection, action: 'terminate'}), TERMINATE_TEXT);
    const state = extra => normalizeRow({name: 'emails', connection: 'a', pauses: [], paused_unknown: false, ...extra}).pause;
    assert.equal(controlMessage({...queue, action: 'continue', pause: state({pauses: [cause('queue', 'queue:pause')]})}), 'Workers take jobs from emails again.');
    // A queue continue never lifts a broader pause: the sheet names what stays.
    assert.equal(
        controlMessage({...queue, action: 'continue', pause: state({pauses: [cause('connection'), cause('queue')]})}),
        'Lifts the queue pause of emails. It stays paused by the pause of the connection.',
    );
    assert.equal(
        controlMessage({...queue, action: 'continue', pause: state({pauses: [cause('global', 'queue:pause --all')], queue_cause_unknown: true})}),
        "Lifts the queue pause of emails. It stays paused by Laravel's global pause (queue:continue --all).",
    );
    // An unknown pause state: no promise that the queue resumes.
    assert.equal(
        controlMessage({...queue, action: 'continue', pause: state({native_unknown: true})}),
        'Lifts the pause of emails. Whether another pause also holds emails is unknown, so workers may still not take its jobs.',
    );
    assert.match(controlMessage({...queue, action: 'continue'}), /is unknown/, 'a selection without a pause state promises nothing');
    assert.equal(controlMessage({...queue, action: 'pause'}), 'Workers stop taking jobs from emails. Jobs keep arriving and wait until you continue it.');
    assert.equal(controlMessage({...queue, action: 'pause', cacheNote: 'Local store.'}), 'Workers stop taking jobs from emails. Jobs keep arriving and wait until you continue it. Local store.');
    assert.equal(controlMessage({...connection, action: 'continue'}), 'Removes the pause of database. Pauses of its single queues stay.');
    assert.match(controlMessage({...connection, action: 'pause'}), /^Pauses every queue on database\. Pauses of its single queues stay/);

    assert.equal(controlConfirmLabel({action: 'pause'}), 'Pause');
    assert.equal(controlConfirmLabel({action: 'terminate'}), 'Terminate');
    assert.equal(controlConfirmLabel({action: 'x'}), 'Confirm');
});

// A native queue:pause has no known start, but the sparkline titled it "Paused from <window
// start>". Rejects: a start time for a pause whose start is unknown, Orrery's own pause time dropped, and a
// title without a pause.
test('pausedTitle names a known start only', () => {
    const window = {from: NOW - 3600, to: NOW};

    assert.equal(pausedTitle(row('a', {pauses: [cause('queue', 'orrery', {issued_at: NOW - 60})]}), window), 'Paused from 08:59 UTC');
    assert.equal(pausedTitle(row('a', {pauses: [cause('queue', 'orrery', {issued_at: NOW - 2 * 86400})]}), window), 'Paused from 2026-09-26 09:00 UTC');
    assert.equal(pausedTitle(row('a', {pauses: [cause('queue', 'queue:pause')]}), window), 'Paused across this window; the start is unknown');
    assert.equal(pausedTitle(row('a', {pauses: [cause('global', 'queue:pause --all')]}), window), 'Paused across this window; the start is unknown');
    assert.equal(pausedTitle(row('a'), window), null);
    assert.equal(pausedTitle(row('a', {pauses: [cause('queue', 'queue:pause')]}), null), null);
});

const RETENTION = {
    global: {completed: 60, silenced: 60, failed: 10080, unknown: 10080, monitored: 10080},
    effective: [
        {connection: 'database', queue: 'a', values: {
            completed: {minutes: 43200, rule: 'connection+queue'},
            silenced: {minutes: 60, rule: 'global'},
            failed: {minutes: 0, rule: 'queue'},
            unknown: {minutes: 120, rule: 'connection'},
            monitored: {minutes: null, rule: 'global'},
        }},
    ],
    truncated: false,
};

// "Kept for". Rejects: the global values shown for a queue with overrides, a queue of
// another connection (or an alias) matched by its name alone, an unknown queue shown as the defaults, 0 shown as
// "0 min" (it means the next tick), an invalid value (null) shown as 0, and minutes left as minutes for whole days.
test('keptFor gives the effective retention of the selected queue', () => {
    const kept = keptFor(RETENTION, row('a'));

    assert.deepEqual(kept.map(entry => entry.key), ['completed', 'silenced', 'failed', 'unknown', 'monitored']);
    assert.deepEqual(kept.map(entry => entry.text), ['30 d', '60 min', 'until the next tick', '2 h', '—']);
    assert.deepEqual(kept.map(entry => entry.source), ['set for this queue on this connection', 'the default', 'set for this queue', 'set for this connection', 'the default']);
    assert.deepEqual(kept.map(entry => entry.label), ['Completed', 'Silenced', 'Failed', 'Unknown', 'Monitored']);

    assert.equal(keptFor(RETENTION, row('a', {connection: 'other'})), null, 'another connection is unknown');
    assert.notEqual(keptFor(RETENTION, row('a', {connection: 'primary', connections: ['primary', 'database']})), null, 'an alias of the row matches');
    assert.equal(keptFor(RETENTION, row('b')), null);
    assert.equal(keptFor(null, row('a')), null);
    assert.equal(retentionText(1440 * 7), '7 d');
    assert.equal(retentionText(90), '90 min');
    assert.equal(retentionText(180), '3 h');
    assert.equal(retentionText(-1), '—');
});

// Review fix 5. orrery_jobs.connection keeps the alias that pushed the job, and a row merges every alias of one
// jobs table. Rejects: one link with the primary alias only (the jobs of the other aliases are missing), and
// links named by alias when the row has one.
test('queueJobsLinks links every alias of the row', () => {
    assert.deepEqual(queueJobsLinks(row('a'), 'pending'), [{key: 'database', label: 'Pending jobs', to: {path: '/jobs', query: {status: 'pending', connection: 'database', queue: 'a'}}}]);
    assert.deepEqual(queueJobsLinks(row('a', {connections: ['database', 'database-secondary']}), 'failed').map(link => [link.label, link.to.query.connection]), [
        ['Failed jobs on database', 'database'],
        ['Failed jobs on database-secondary', 'database-secondary'],
    ]);
    assert.deepEqual(queueJobsLinks(null, 'failed'), []);
});

// Rejects: a failed refresh of the backlog read that keeps the last chart with no stale mark, a first failed
// read shown as loading, and a stale mark on a fresh chart.
test('backlogStatus marks a chart whose last read failed as stale', () => {
    const window = {from: 0, to: 3600, retentionFrom: null};

    assert.equal(backlogStatus(null), 'loading');
    assert.equal(backlogStatus({window: null, buckets: [], error: true}), 'failed');
    assert.equal(backlogStatus({window, buckets: [], error: true}), 'stale');
    assert.equal(backlogStatus({window, buckets: [], error: false}), 'ok');
});

// Rejects: two backlog cards of one queue name on two connections that read the same, and a connection prefix
// on a name that is unique.
// Still passes: two rows of one connection and name (the screen never lists them twice).
test('cardName adds the connection only when the name repeats', () => {
    const a = {name: 'emails', connection: 'database'};
    const b = {name: 'emails', connection: 'reports'};
    const c = {name: 'default', connection: 'database'};

    assert.equal(cardName(a, [a, b, c]), 'database:emails');
    assert.equal(cardName(b, [a, b, c]), 'reports:emails');
    assert.equal(cardName(c, [a, b, c]), 'default');
    assert.equal(cardName(c, null), 'default');
});

// Rejects: an unknown count shown as 0, a delayed or batched part left out, and an unknown batch part shown as none.
test('clearCountText freezes the count of the clear sheet', () => {
    assert.equal(clearCountText({waiting: 12, ready: 9, delayed: 3, in_batches: 2}), '12 waiting (including 3 delayed; 2 in batches, whose batches will not finish).');
    assert.equal(clearCountText({waiting: 1, ready: 1, delayed: 0, in_batches: 0}), '1 waiting (including 0 delayed; none known in batches).');
    assert.equal(clearCountText({waiting: 5, ready: 5, delayed: 0, in_batches: 2, in_batches_at_least: true}), '5 waiting (including 0 delayed; at least 2 in batches, whose batches will not finish).');
    assert.equal(clearCountText({waiting: 5, ready: 5, delayed: 0, in_batches: 0, in_batches_at_least: true}), '5 waiting (including 0 delayed; none known in batches; some jobs are not in Orrery yet).');
    assert.equal(clearCountText({waiting: 4, ready: 4, delayed: 0, in_batches: null}), '4 waiting (including 0 delayed; how many are in batches is unknown).');
    assert.equal(clearCountText(null), 'The number of waiting jobs is unknown.');
    assert.equal(clearCountText({waiting: null, delayed: 0}), 'The number of waiting jobs is unknown.');
});

// Rejects: a queue named in a request path, where a browser resolves `.` and `..` segments (also encoded ones), so
// `x/../default` would clear or read another queue; a sheet without its frozen target and count, without the aliases
// whose rows go too, or that is not destructive.
test('clearSheet and queueRequest name the queue and its aliases', () => {
    for (const name of ['x/../default', 'reports/./daily', '../other/q', '..']) {
        assert.deepEqual(queueRequest('clear', 'database', name), {url: '/api/queues/clear', body: {connection: 'database', queue: name}});
        assert.deepEqual(queueRequest('waiting', 'database', name), {url: '/api/queues/waiting', body: {connection: 'database', queue: name}});
        assert.deepEqual(queueRequest('history', 'database', name, {minutes: 60}), {url: '/api/queues/history', params: {connection: 'database', queue: name, minutes: 60}});
    }

    const sheet = clearSheet({name: 'emails', connection: 'database', connections: ['database', 'database-twin']}, {waiting: 2, delayed: 1, in_batches: 0});
    assert.equal(sheet.title, 'Clear waiting jobs of emails?');
    assert.match(sheet.message, /^2 waiting \(including 1 delayed; none known in batches\)\. Every job of database:emails that no worker has reserved is deleted, with its rows in Orrery \(also those of database-twin, which share its jobs table\)\./);
    assert.equal(sheet.tone, 'danger');
    assert.deepEqual(sheet.selection, {kind: 'clear', connection: 'database', queue: 'emails', aliases: ['database', 'database-twin']});
    assert.match(clearSheet({name: 'emails', connection: 'database'}, null).message, /^The number of waiting jobs is unknown\./);
});

// Rejects: a partial clear (time budget, failed probe, failed mirror delete) shown as plain success, an answer
// without counts shown as success, and a refusal or an unknown outcome reported as done.
test('clearOutcome and clearFailure word every answer', () => {
    const selection = {queue: 'emails'};
    const done = clearOutcome(selection, {deleted: 3, mirror_rows: 3, unresolved_rows: 0, undecodable: 0, count_mismatches: 0, mirror_failed: false, complete: true, reason: null});
    assert.deepEqual(done, {tone: 'success', title: 'Cleared 3 waiting jobs of emails', detail: 'Reserved jobs were kept.'});

    const budget = clearOutcome(selection, {deleted: 900, undecodable: 0, mirror_failed: false, complete: false, reason: 'time_budget'});
    assert.equal(budget.tone, 'warning');
    assert.match(budget.detail, /time limit/);
    assert.match(clearOutcome(selection, {deleted: 0, complete: false, reason: 'probe_failed'}).detail, /still queued/);
    const mirror = clearOutcome(selection, {deleted: 1, undecodable: 2, mirror_failed: true, complete: true, reason: null});
    assert.equal(mirror.tone, 'warning');
    assert.match(mirror.detail, /reconciliation marks them unknown later.*2 payload/);
    assert.equal(clearOutcome(selection, {}).tone, 'warning');

    assert.equal(clearFailure(selection, {response: {status: 409, data: {error: 'in_transaction'}}}).tone, 'danger');
    assert.match(clearFailure(selection, {response: {status: 409, data: {error: 'in_transaction'}}}).detail, /inside a transaction/);
    assert.equal(clearFailure(selection, {response: {status: 403, data: {error: 'forbidden_action'}}}), null);
    assert.match(clearFailure(selection, {response: {status: 503, data: {error: 'write_failed'}}}).title, /outcome unknown/);
    assert.equal(clearFailure(selection, {response: {status: 422, data: {error: 'invalid'}}}).tone, 'danger');
    const partial = clearOutcome(selection, {deleted: 900, undecodable: 0, mirror_failed: false, complete: false, reason: 'write_failed'});
    assert.equal(partial.tone, 'warning');
    assert.match(partial.detail, /A later batch could not be deleted/);
    assert.match(clearFailure(selection, {}).title, /outcome unknown/);
});
