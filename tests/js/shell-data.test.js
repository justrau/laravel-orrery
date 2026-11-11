// Tests of resources/js/shell/data.js.

import {test} from 'node:test';
import assert from 'node:assert/strict';

import {
    batchesCount,
    bodyWith,
    degradationTitle,
    isShapeError,
    heartbeatAge,
    workerState,
    listBody,
    objectBody,
    awaitingOperations,
    awaitingUnknown,
    fenceUnknownConnections,
    sharedRetryAfterNotes,
    sqliteWarningMessages,
    unprovableNote,
    ATTENTION_FAILURE_SECONDS,
    attentionBadge,
    attentionCount,
    attentionTitle,
    countLabel,
    mirroredCount,
    jobNavItems,
    sidebarIsDrawer,
    SIDEBAR_BREAKPOINT,
    configurationWarnings,
    configurationNotes,
    configurationUnknown,
    inspectorShown,
    failureGroups,
    failuresPastHour,
    GROUP_PAGE_SIZE,
    groupsIncomplete,
    RECENT_DEGRADATION_SECONDS,
    RETENTION_BEHIND_SECONDS,
    RETENTION_STALE_SECONDS,
    retentionState,
    SAMPLES_BEHIND_SECONDS,
    samplesState,
    rollbackNote,
    runningBatches,
    shellAttention,
    shellHealth,
    sidebarQueues,
    STALE_TICK_SECONDS,
    watcherHealth,
    isRollbackOnlyWindow,
    liveIndicator,
} from '../../resources/js/shell/data.js';

const NOW = 1790000000;

function groups(count, extra = {}) {
    return {groups: Array.from({length: count}, (_, index) => ({key: `k${index}`, count: 2})), ...extra};
}

// Rejects: an empty list for a response without groups (unknown is not "no failures").
test('failureGroups is null without a group list', () => {
    assert.equal(failureGroups(null), null);
    assert.equal(failureGroups({}), null);
    assert.equal(failureGroups({groups: 'x'}), null);
    assert.deepEqual(failureGroups({groups: []}), []);
});

// Rejects: a full page that counts as complete, and a truncated scan that counts as complete.
test('groupsIncomplete for a truncated scan or a full page', () => {
    assert.equal(groupsIncomplete(groups(3)), false);
    assert.equal(groupsIncomplete(groups(3, {truncated: true})), true);
    assert.equal(groupsIncomplete(groups(GROUP_PAGE_SIZE)), true);
    assert.equal(groupsIncomplete(null), false);
});

// Rejects: 0 for an unknown response, and a sum that skips string counts.
test('failuresPastHour sums the page and says "at least" for an incomplete page', () => {
    assert.equal(failuresPastHour(null), null);
    assert.deepEqual(failuresPastHour({groups: [{count: 2}, {count: '3'}]}), {count: 5, atLeast: false});
    assert.deepEqual(failuresPastHour(groups(GROUP_PAGE_SIZE)), {count: 2 * GROUP_PAGE_SIZE, atLeast: true});
});

// Rejects: a group with an unknown count read as 0 (a definite total, or "0 failed"),
// and a capped or inexact group count read as exact.
test('failuresPastHour with an unknown or capped group count is at least, never a definite sum', () => {
    // A listed group holds at least one failure, so its unknown count adds 1 and makes the total a lower bound.
    assert.deepEqual(failuresPastHour({groups: [{count: 2}, {count: null}]}), {count: 3, atLeast: true});
    assert.deepEqual(failuresPastHour({groups: [{count: null}]}), {count: 1, atLeast: true});
    assert.deepEqual(failuresPastHour({groups: [{}]}), {count: 1, atLeast: true});
    assert.deepEqual(failuresPastHour({groups: [{count: 5000, count_capped: true}]}), {count: 5000, atLeast: true});
    assert.deepEqual(failuresPastHour({groups: [{count: 4, exact: false}]}), {count: 4, atLeast: true});
    assert.deepEqual(failuresPastHour({groups: []}), {count: 0, atLeast: false});
});

function row(extra = {}) {
    return {
        connection: 'database', name: 'default', length: 3, oldest_age: 5, over_limit: false,
        pauses: [], paused_unknown: false, queue_cause_unknown: false, native_unknown: false, backlog_unknown: false, ...extra,
    };
}

const cause = (scope, source = 'orrery', extra = {}) => ({scope, source, issued_at: 1, issued_by: 'cli', possible: false, ...extra});

// Rejects: the state 'ready' for an unknown pause, an unknown backlog, a masked or unread native state,
// or a row without a `pauses` list; and dropping the fields that carry them.
test('sidebarQueues keeps paused_unknown, backlog_unknown and the unknown native parts', () => {
    const [plain, pauseUnknown, backlogUnknown, masked, unread, absent] = sidebarQueues([
        row({name: 'a'}),
        row({name: 'b', paused_unknown: true}),
        row({name: 'c', backlog_unknown: true, length: null, oldest_age: null, over_limit: null}),
        row({name: 'd', queue_cause_unknown: true}),
        row({name: 'e', native_unknown: true}),
        (() => { const value = row({name: 'f'}); delete value.pauses; return value; })(),
    ]);

    assert.equal(plain.state, 'ready');
    assert.equal(plain.pause.knownOff, true);
    assert.equal(plain.backlogUnknown, false);

    assert.equal(pauseUnknown.state, 'unknown');
    assert.equal(pauseUnknown.pause.pausedUnknown, true);

    assert.equal(backlogUnknown.state, 'unknown');
    assert.equal(backlogUnknown.backlogUnknown, true);
    assert.equal(backlogUnknown.ready, null);
    assert.equal(backlogUnknown.overLimit, false);

    assert.equal(masked.state, 'unknown');
    assert.equal(unread.state, 'unknown');
    assert.equal(absent.state, 'unknown', 'a missing pauses list is unknown, not running');
    assert.equal(absent.pause.knownOff, false);
});

// Rejects: 'unknown' for a paused queue whose other part is unknown, a pause shown as ready or over,
// and a possible cause read as a pause.
test('a definite cause wins over an unknown part, and a possible cause is no pause', () => {
    const [orrery, native, global, possible] = sidebarQueues([
        row({name: 'a', pauses: [cause('queue')], paused_unknown: true}),
        row({name: 'b', pauses: [cause('queue', 'queue:pause', {issued_at: null, issued_by: null})], over_limit: true}),
        row({name: 'c', pauses: [cause('global', 'queue:pause --all')], queue_cause_unknown: true, backlog_unknown: true}),
        row({name: 'd', pauses: [cause('queue', 'orrery', {possible: true})], native_unknown: true}),
    ]);

    assert.equal(orrery.state, 'paused');
    assert.deepEqual(orrery.pause.definite.map(entry => [entry.scope, entry.source, entry.issuedBy]), [['queue', 'orrery', 'cli']]);
    assert.equal(native.state, 'paused');
    assert.equal(global.state, 'paused');
    assert.equal(possible.state, 'unknown', 'a possible pause is never "paused"');
    assert.equal(possible.pause.paused, false);
});

// Rejects: 'unknown' winning over a known over-limit, and an over-limit row with a null `over_limit`.
test('sidebarQueues orders the states: paused, unknown backlog, over, unknown pause, ready', () => {
    const [over, overPauseUnknown, nullOver] = sidebarQueues([
        row({name: 'a', over_limit: true}),
        row({name: 'b', over_limit: true, paused_unknown: true}),
        row({name: 'c', over_limit: null}),
    ]);

    assert.equal(over.state, 'over');
    assert.equal(overPauseUnknown.state, 'over');
    assert.equal(nullOver.overLimit, false);
    assert.equal(nullOver.state, 'ready');
});

// Rejects: a label without the connection for a queue name on two connections, and rows without a name.
test('sidebarQueues labels duplicate names with their connection and skips invalid rows', () => {
    const queues = sidebarQueues([
        row({name: 'default'}),
        row({name: 'default', connection: 'other'}),
        row({name: 'mail'}),
        {name: 5, connection: 'x'},
        null,
    ]);

    assert.deepEqual(queues.map(queue => [queue.key, queue.label]), [
        ['database:default', 'default (database)'],
        ['other:default', 'default (other)'],
        ['database:mail', 'mail'],
    ]);
    assert.deepEqual(sidebarQueues(null), []);
    assert.equal(queues[0].ready, 3);
    assert.equal(queues[0].oldestAge, 5);
});

function recentGroup(age, extra = {}) {
    return {key: `g${age}`, count: 1, last_failed_at: NOW - age, ...extra};
}

// Rejects: `|| []`, a definite "0 need attention" while the failure groups did not load.
test('attentionCount with failed groups is at least, never a definite 0', () => {
    const queues = sidebarQueues([row({name: 'a', over_limit: true})]);

    assert.deepEqual(attentionCount(queues, null, NOW), {count: 1, atLeast: true, possible: 0, unknown: ['failure groups']});
    assert.deepEqual(attentionCount([], undefined, NOW), {count: 0, atLeast: true, possible: 0, unknown: ['failure groups']});
    assert.deepEqual(attentionCount([], {groups: []}, NOW), {count: 0, atLeast: false, possible: 0, unknown: []});
});

// Rejects: counting a queue whose pause state is unknown, and leaving it out without a trace.
test('an over-limit queue with an unknown pause is possible, not counted', () => {
    const queues = sidebarQueues([
        row({name: 'a', over_limit: true, paused_unknown: true}),
        row({name: 'b', over_limit: true, native_unknown: true}),
        row({name: 'c', over_limit: true}),
        row({name: 'd', over_limit: true, pauses: [cause('global')]}),
        row({name: 'e', over_limit: true, pauses: [cause('queue', 'queue:pause')]}),
    ]);

    assert.deepEqual(attentionCount(queues.slice(0, 1), {groups: []}, NOW), {count: 0, atLeast: true, possible: 1, unknown: ['pause state']});
    assert.deepEqual(attentionCount(queues, {groups: []}, NOW), {count: 1, atLeast: true, possible: 2, unknown: ['pause state']});
});

// (test_annotation_under_native_unknown_is_possibly_paused): an Orrery queue pause whose
// Laravel flag was not read (the cache store is the app's SQLite database) is only possible.
// Rejects: counting the annotation as a definite pause, and letting it suppress the attention rule.
test('an over-limit queue with a possible pause still needs attention', () => {
    const queues = sidebarQueues([
        row({name: 'a', over_limit: true, native_unknown: true, pauses: [cause('queue', 'orrery', {possible: true})]}),
    ]);

    assert.equal(queues[0].state, 'over', 'the possible pause hid the over-limit queue');
    assert.deepEqual(attentionCount(queues, {groups: []}, NOW), {count: 0, atLeast: true, possible: 1, unknown: ['pause state']});
    assert.equal(attentionTitle(attentionCount(queues, {groups: []}, NOW)), '1 may need attention (pause state unknown)');
});

// Rejects: an unknown backlog that counts as nothing to see, and a definite count beside it.
test('attentionCount marks an unknown backlog as unknown queue state', () => {
    const queues = sidebarQueues([row({name: 'a', backlog_unknown: true, over_limit: null, length: null})]);

    assert.deepEqual(attentionCount(queues, {groups: []}, NOW), {count: 0, atLeast: true, possible: 0, unknown: ['queue state']});
});

// Rejects: an inclusive or missing recency limit, groups without a time that count, and string times that are lost.
test('attentionCount counts the failure groups of the last 15 minutes', () => {
    const data = {groups: [
        recentGroup(10),
        recentGroup(ATTENTION_FAILURE_SECONDS),
        recentGroup(ATTENTION_FAILURE_SECONDS + 1),
        {key: 'x', last_failed_at: String(NOW - 20)},
        {key: 'y', last_failed_at: null},
        null,
    ]};

    assert.deepEqual(attentionCount([], data, NOW), {count: 3, atLeast: false, possible: 0, unknown: []});
});

// Rejects: "at least" for a full page whose last group is old (the page is newest first, so it proves
// that no other recent group exists), and a full page of recent groups read as complete.
test('attentionCount is at least only when the incomplete page may hide a recent group', () => {
    const allRecent = {groups: Array.from({length: GROUP_PAGE_SIZE}, (_, index) => recentGroup(index + 1))};
    const endsOld = {groups: [...allRecent.groups.slice(0, GROUP_PAGE_SIZE - 1), recentGroup(ATTENTION_FAILURE_SECONDS + 100)]};
    const truncatedRecent = {groups: [recentGroup(5)], truncated: true};

    assert.deepEqual(attentionCount([], allRecent, NOW), {count: GROUP_PAGE_SIZE, atLeast: true, possible: 0, unknown: []});
    assert.deepEqual(attentionCount([], endsOld, NOW), {count: GROUP_PAGE_SIZE - 1, atLeast: false, possible: 0, unknown: []});
    assert.deepEqual(attentionCount([], truncatedRecent, NOW), {count: 1, atLeast: true, possible: 0, unknown: []});
});

// Rejects: no badge for an unknown part, "0+" instead of "?", and a badge for a definite 0.
test('attentionBadge shows the count, "+" for at least, and "?" for an unknown 0', () => {
    assert.equal(attentionBadge(null), null);
    assert.equal(attentionBadge({count: 0, atLeast: false, possible: 0, unknown: []}), null);
    assert.equal(attentionBadge({count: 2, atLeast: false, possible: 0, unknown: []}), '2');
    assert.equal(attentionBadge({count: 1200, atLeast: true, possible: 0, unknown: []}), '1,200+');
    assert.equal(attentionBadge({count: 0, atLeast: true, possible: 1, unknown: ['pause state']}), '?');
});

// Rejects: a definite "0 need attention" while a part is unknown, and a title that hides the possible items.
test('attentionTitle never says a definite 0 while a part is unknown', () => {
    assert.equal(attentionTitle(null), null);
    assert.equal(attentionTitle({count: 0, atLeast: false, possible: 0, unknown: []}), 'Nothing needs attention');
    assert.equal(attentionTitle({count: 1, atLeast: false, possible: 0, unknown: []}), '1 needs attention');
    assert.equal(attentionTitle({count: 3, atLeast: false, possible: 0, unknown: []}), '3 need attention');
    assert.equal(attentionTitle({count: 50, atLeast: true, possible: 0, unknown: []}), 'At least 50 need attention');
    assert.equal(
        attentionTitle({count: 2, atLeast: true, possible: 1, unknown: ['pause state']}),
        'At least 2 need attention · 1 more may need attention (pause state unknown)',
    );
    assert.equal(
        attentionTitle({count: 0, atLeast: true, possible: 2, unknown: ['pause state']}),
        '2 may need attention (pause state unknown)',
    );
    assert.equal(
        attentionTitle({count: 0, atLeast: true, possible: 0, unknown: ['failure groups']}),
        'Attention unknown: failure groups did not load',
    );
    assert.equal(
        attentionTitle({count: 1, atLeast: true, possible: 0, unknown: ['failure groups', 'queue state']}),
        'At least 1 need attention · failure groups did not load · queue state unknown',
    );

    for (const title of [
        attentionTitle({count: 0, atLeast: true, possible: 0, unknown: ['queue state']}),
        attentionTitle({count: 0, atLeast: true, possible: 1, unknown: ['pause state', 'failure groups']}),
    ]) {
        assert.doesNotMatch(title, /\b0 need/);
        assert.doesNotMatch(title, /Nothing/);
    }
});

function health(tickAge, windows = []) {
    return {
        tick: {observer_tick_at: tickAge === null ? null : NOW - tickAge},
        windows,
        missing_fence_tables: [],
        retention: {state: 'ok', at: NOW - 30, deleted: 0, behind: false, lag_seconds: null, partitions_unchecked: 0},
    };
}

// Rejects: "healthy" for a failed health read, and "healthy" before the first tick.
test('watcherHealth is unknown without a health response', () => {
    assert.deepEqual(watcherHealth(null, [], NOW), {tone: 'unknown', title: 'Watcher health unknown', tickAge: null, heartbeatAge: null});
    assert.equal(watcherHealth({}, [], NOW).tone, 'unknown');

    const noTick = watcherHealth(health(null), [], NOW);
    assert.equal(noTick.tone, 'warning');
    assert.equal(noTick.title, 'No tick yet');
});

// Rejects: a stale limit that is inclusive, and a tick age from a string time that is lost.
test('watcherHealth warns when the tick is late', () => {
    assert.equal(watcherHealth(health(STALE_TICK_SECONDS), [], NOW).tone, 'healthy');

    const late = watcherHealth(health(STALE_TICK_SECONDS + 1), [], NOW);
    assert.equal(late.title, 'Tick is late');
    assert.equal(late.tickAge, STALE_TICK_SECONDS + 1);

    const fromString = watcherHealth({...health(0), tick: {observer_tick_at: String(NOW - 30)}}, [], NOW);
    assert.equal(fromString.tickAge, 30);
    assert.equal(watcherHealth(health(-10), [], NOW).tickAge, 0, 'a tick from the future has age 0');
});

// Rejects: an old open window of a dead process that keeps the footer red, and a recent window that is ignored.
test('watcherHealth counts only recent degradation windows', () => {
    const since = NOW - RECENT_DEGRADATION_SECONDS;
    const windows = [
        {started_at: since + 10, ended_at: null, last_error_at: null},
        {started_at: since - 500, ended_at: since + 5, last_error_at: null},
        {started_at: since - 500, ended_at: null, last_error_at: since + 1},
        {started_at: since - 500, ended_at: null, last_error_at: since - 1},
        {started_at: since - 500, ended_at: since - 1, last_error_at: since + 100},
        {started_at: String(since + 20), ended_at: null, last_error_at: null},
        null,
    ];

    const result = watcherHealth(health(10, windows), [], NOW);
    assert.equal(result.recentWindows, 4);
    assert.equal(result.tone, 'warning');
    assert.equal(result.title, 'Watcher degraded');

    const old = watcherHealth(health(10, [windows[3], windows[4]]), [], NOW);
    assert.equal(old.recentWindows, 0);
    assert.equal(old.tone, 'healthy');
    assert.equal(old.title, 'Watcher healthy');
});

// Rejects: a business rollback that turns the footer to "Watcher degraded", a rollback count folded into a real degradation, an absent counter read as 0, and a truncated total shown as exact.
test('a window with only rollback drops is not a degradation', () => {
    const since = NOW - RECENT_DEGRADATION_SECONDS;
    const rollback = {started_at: since + 10, ended_at: null, last_error_at: null, dropped: 0, unmatched_observations: 0, unverified: 0, rollback_unverified: 3};

    assert.equal(isRollbackOnlyWindow(rollback), true);
    const result = watcherHealth(health(10, [rollback, {...rollback, rollback_unverified: '2'}]), [], NOW);
    assert.equal(result.tone, 'healthy');
    assert.equal(result.title, 'Watcher healthy');
    assert.equal(result.recentWindows, 0);
    assert.equal(result.rollbackNote, '5 queue operations were not mirrored: their transactions rolled back (a lost COMMIT cannot be ruled out).');
    assert.equal(watcherHealth(health(10, [{...rollback, rollback_unverified: 1}]), [], NOW).rollbackNote, '1 queue operation was not mirrored: its transaction rolled back (a lost COMMIT cannot be ruled out).');
    assert.equal(watcherHealth({...health(10, [rollback]), truncated: true}, [], NOW).rollbackNote, 'At least 3 queue operations were not mirrored: their transactions rolled back (a lost COMMIT cannot be ruled out).', 'a truncated list is a lower bound');

    for (const counter of ['dropped', 'unmatched_observations', 'unverified']) {
        const mixed = {...rollback, [counter]: 1};
        assert.equal(isRollbackOnlyWindow(mixed), false, `${counter} still degrades`);
        assert.equal(watcherHealth(health(10, [mixed]), [], NOW).title, 'Watcher degraded');
    }

    const withError = {...rollback, last_error_at: since + 20};
    assert.equal(watcherHealth(health(10, [withError]), [], NOW).title, 'Watcher degraded', 'an error still degrades');
    assert.equal(watcherHealth(health(10, []), [], NOW).rollbackNote, null);
    assert.equal(rollbackNote(0), null);
    assert.equal(rollbackNote(null), null);
    assert.equal(rollbackNote(2), '2 queue operations were not mirrored: their transactions rolled back (a lost COMMIT cannot be ruled out).');
    assert.equal(isRollbackOnlyWindow({started_at: since + 10, ended_at: null}), false, 'a window without counters still degrades');
    for (const counter of ['dropped', 'unmatched_observations', 'unverified']) {
        const {[counter]: _absent, ...missing} = rollback;
        assert.equal(isRollbackOnlyWindow(missing), false, `an absent ${counter} still degrades`);
    }
});

function healthy(extra = {}) {
    return {
        tick: {observer_tick_at: NOW - 10},
        windows: [],
        missing_fence_tables: [],
        retention: {state: 'ok', at: NOW - 30, deleted: 10, behind: false, lag_seconds: null, partitions_unchecked: 0},
        ...extra,
    };
}

// Rejects: "No tick yet" for a tick that could not be read (the operator checks the scheduler, not the database), and
// "Watcher healthy" for windows that could not be read (zero windows counted); an older server without a tick state
// and with a missing tick keeps "No tick yet".
test('watcherHealth is unknown when the storage could not be read', () => {
    const title = "Watcher health unknown: Orrery's storage could not be read";

    for (const extra of [
        {tick: {observer_tick_at: null, state: 'unreadable'}, windows: null},
        {tick: {observer_tick_at: null, state: 'unreadable'}},
        {windows: null},
    ]) {
        const result = watcherHealth(healthy(extra), [], NOW);
        assert.deepEqual([result.tone, result.title], ['warning', title], JSON.stringify(extra));
    }

    assert.equal(watcherHealth(healthy({tick: {observer_tick_at: null, state: 'none'}}), [], NOW).title, 'No tick yet');
    assert.equal(watcherHealth(healthy({tick: {observer_tick_at: null}}), [], NOW).title, 'No tick yet');
    assert.equal(watcherHealth(healthy({tick: {observer_tick_at: NOW - 10, state: 'ok'}}), [], NOW).title, 'Watcher healthy');
    assert.equal(watcherHealth(healthy({tick: {observer_tick_at: NOW - 10, state: 'ok'}, windows: null}), [], NOW).title, title);
});

// Rejects: the current "Watcher healthy" while retries are refused, and a fence title that hides a late tick.
test('a fresh tick with a missing fence table is not healthy', () => {
    const missing = watcherHealth(healthy({missing_fence_tables: ['database', 'other']}), [], NOW);
    assert.equal(missing.tone, 'warning');
    assert.equal(missing.title, 'Retries refused on database, other: fence table missing; run php artisan orrery:install --fence');
    assert.deepEqual(missing.missingFenceTables, ['database', 'other']);

    assert.equal(watcherHealth(healthy({missing_fence_tables: ['database'], tick: {observer_tick_at: NOW - STALE_TICK_SECONDS - 1}}), [], NOW).title, 'Tick is late');
    assert.equal(watcherHealth(healthy({missing_fence_tables: ['database'], windows: [{started_at: NOW - 5, ended_at: null}]}), [], NOW).title, 'Watcher degraded');
    assert.equal(watcherHealth(healthy(), [], NOW).title, 'Watcher healthy');
    assert.equal(watcherHealth(healthy({missing_fence_tables: [7, 'database']}), [], NOW).title, 'Retries refused on database: fence table missing; run php artisan orrery:install --fence');
});

// Rejects: "Watcher healthy" while a queue connection is not mirrored, an entry without a connection name,
// and a line that hides a missing fence table.
test('an uninstrumented connection is not healthy', () => {
    const one = watcherHealth(healthy({uninstrumented_connections: [{connection: 'database', reason: 'swap_disabled'}]}), [], NOW);
    assert.equal(one.tone, 'warning');
    assert.equal(one.title, "Pending and running jobs are not mirrored for database: the queue is not Orrery's.");
    assert.deepEqual(one.uninstrumented, ['database']);

    const two = watcherHealth(healthy({uninstrumented_connections: [
        {connection: 'database', reason: 'foreign_queue_class'},
        {connection: 'jobs2', reason: 'swap_disabled'},
        {connection: 'other', reason: 'unknown'},
        {reason: 'unknown'},
        null,
    ]}), [], NOW);
    assert.equal(two.title, "Pending and running jobs are not mirrored for database, jobs2: the queue is not Orrery's.");
    assert.deepEqual(two.uninstrumented, ['database', 'jobs2']);
    assert.deepEqual(two.instrumentationUnknown, ['other']);

    const fenceFirst = watcherHealth(healthy({missing_fence_tables: ['database'], uninstrumented_connections: [{connection: 'database', reason: 'swap_disabled'}]}), [], NOW);
    assert.equal(fenceFirst.title, 'Retries refused on database: fence table missing; run php artisan orrery:install --fence');

    assert.equal(watcherHealth(healthy({uninstrumented_connections: []}), [], NOW).title, 'Watcher healthy');
    assert.equal(watcherHealth(healthy(), [], NOW).title, 'Watcher healthy', 'an older response without the field');
});

// Rejects: the reason `unknown` merged into "the queue is not Orrery's", an unknown
// instrumentation shown as healthy, and an unknown line that hides a known foreign queue.
test('an unknown instrumentation has its own footer line', () => {
    const one = watcherHealth(healthy({uninstrumented_connections: [{connection: 'database', reason: 'unknown'}, {connection: 'other', reason: 'unknown'}]}), [], NOW);
    assert.equal(one.tone, 'warning');
    assert.equal(one.title, 'Instrumentation is unknown for database, other.');
    assert.deepEqual(one.uninstrumented, []);
    assert.deepEqual(one.instrumentationUnknown, ['database', 'other']);

    const both = watcherHealth(healthy({uninstrumented_connections: [{connection: 'other', reason: 'unknown'}, {connection: 'database', reason: 'swap_disabled'}]}), [], NOW);
    assert.equal(both.title, "Pending and running jobs are not mirrored for database: the queue is not Orrery's.");

    const odd = watcherHealth(healthy({uninstrumented_connections: [{connection: 'database', reason: 'something_new'}]}), [], NOW);
    assert.equal(odd.title, 'Instrumentation is unknown for database.', 'a reason that the SPA does not know is unknown, not healthy');
});

// Rejects: a mirror pending or running count of 0 shown as 0 while a connection is not mirrored,
// a positive count without "at least", and a count changed while every connection is instrumented.
test('a pending or running count is unknown or at least while a connection is not mirrored', () => {
    const health = reason => ({uninstrumented_connections: [{connection: 'database', reason}]});

    assert.equal(mirroredCount({count: 0, at_least: false}, health('swap_disabled')), null);
    assert.equal(countLabel(mirroredCount({count: 0, at_least: false}, health('unknown'))), '—');
    assert.deepEqual(mirroredCount({count: 12, at_least: false}, health('foreign_queue_class')), {count: 12, at_least: true});
    assert.equal(countLabel(mirroredCount({count: 12, at_least: false}, health('unknown'))), '12+');

    assert.deepEqual(mirroredCount({count: 0, at_least: false}, {uninstrumented_connections: []}), {count: 0, at_least: false});
    assert.deepEqual(mirroredCount({count: 3, at_least: true}, {}), {count: 3, at_least: true});
    assert.deepEqual(mirroredCount({count: 3, at_least: false}, null), {count: 3, at_least: false}, 'no health read: the count as it is');
    assert.equal(mirroredCount(null, health('swap_disabled')), null);
    assert.equal(mirroredCount({count: null, at_least: false}, null), null);
    assert.equal(mirroredCount(undefined, null), null, 'a server before D8 sends no running count');
    assert.deepEqual(mirroredCount({count: '4', at_least: false}, health('swap_disabled')), {count: 4, at_least: true}, 'a numeric string');
});

// The old Pending item counted pending + running but linked to Pending only. Rejects: an item
// whose count belongs to another tab than its link, a stuck count (there is none), an unknown count shown
// as 0, a capped count without "+", and the old Silenced item in place of Running and Stuck.
test('jobNavItems gives each Jobs item the count of the tab it links to', () => {
    const items = jobNavItems({
        pending: {count: 10216, at_least: false},
        running: {count: 14, at_least: false},
        completed: {count: 1000000, at_least: true},
        failed: null,
    });

    assert.deepEqual(items.map(item => item.key), ['pending', 'running', 'completed', 'failed', 'stuck']);
    assert.deepEqual(items.map(item => item.label), ['Pending', 'Running', 'Completed', 'Failed', 'Stuck']);

    for (const item of items) {
        assert.deepEqual(item.to, {path: '/jobs', query: {status: item.key}});
        assert.equal(item.status, item.key);
    }

    assert.deepEqual(items.map(item => item.count), ['10,216', '14', '1M+', '—', null]);
    assert.equal(items[0].title, '10,216 pending jobs, delayed included');
    assert.equal(items[1].title, '14 running jobs');
    assert.equal(items[2].title, 'At least 1,000,000 completed jobs kept');
    assert.equal(items[3].title, 'Failed jobs kept: unknown');
    assert.equal(items[4].title, null);
    assert.deepEqual(jobNavItems(null).map(item => item.count), ['—', '—', '—', '—', null]);
});

// Rejects: a drawer at 1024 and wider, a sidebar that never collapses, and a width that is
// not a number read as narrow.
test('the sidebar is a drawer below 1024 px', () => {
    assert.equal(SIDEBAR_BREAKPOINT, 1024);
    assert.equal(sidebarIsDrawer(375), true);
    assert.equal(sidebarIsDrawer(768), true);
    assert.equal(sidebarIsDrawer(1023), true);
    assert.equal(sidebarIsDrawer(1024), false);
    assert.equal(sidebarIsDrawer(1920), false);
    assert.equal(sidebarIsDrawer(null), false);
    assert.equal(sidebarIsDrawer(NaN), false);
});

// Rejects: "behind" from `behind` alone with a short lag, a stale state read as current, a lag limit that is exclusive,
// and an unreadable or absent state read as ok.
test('retentionState is behind at a lag of 600 s, and unknown when old, absent or unreadable', () => {
    const ok = {state: 'ok', at: NOW - 30, deleted: 10, behind: false, lag_seconds: null, partitions_unchecked: 0};

    assert.deepEqual(retentionState(ok, NOW), {state: 'ok', lagSeconds: null, at: NOW - 30});
    assert.equal(retentionState({...ok, behind: true, lag_seconds: RETENTION_BEHIND_SECONDS - 1}, NOW).state, 'ok');
    assert.deepEqual(retentionState({...ok, behind: true, lag_seconds: RETENTION_BEHIND_SECONDS}, NOW),
        {state: 'behind', lagSeconds: RETENTION_BEHIND_SECONDS, at: NOW - 30});
    assert.equal(retentionState({...ok, behind: true, lag_seconds: String(RETENTION_BEHIND_SECONDS + 5)}, NOW).state, 'behind');
    assert.equal(retentionState({...ok, at: NOW - RETENTION_STALE_SECONDS}, NOW).state, 'ok');
    assert.equal(retentionState({...ok, at: NOW - RETENTION_STALE_SECONDS - 1}, NOW).state, 'unknown');
    assert.equal(retentionState({...ok, at: NOW - RETENTION_STALE_SECONDS - 1, behind: true, lag_seconds: 9999}, NOW).state, 'unknown',
        'an old state is not current, even when it says behind');
    assert.equal(retentionState({...ok, behind: null, partitions_unchecked: 3}, NOW).state, 'unknown');
    assert.equal(retentionState({...ok, at: null}, NOW).state, 'unknown');
    assert.equal(retentionState({state: 'none'}, NOW).state, 'unknown');
    assert.equal(retentionState({state: 'unreadable'}, NOW).state, 'unknown');
    assert.equal(retentionState(undefined, NOW).state, 'unknown');
});

// Rejects: a footer that stays healthy while retention is behind or unknown, and a retention title over a fence warning.
test('watcherHealth warns when retention is behind and is unknown without a current retention state', () => {
    const behind = watcherHealth(healthy({retention: {state: 'ok', at: NOW - 30, deleted: 500, behind: true, lag_seconds: 900, partitions_unchecked: 0}}), [], NOW);
    assert.equal(behind.tone, 'warning');
    assert.equal(behind.title, 'Retention is behind');
    assert.equal(behind.retention.lagSeconds, 900);

    const stale = watcherHealth(healthy({retention: {state: 'ok', at: NOW - RETENTION_STALE_SECONDS - 1, deleted: 0, behind: false, lag_seconds: null, partitions_unchecked: 0}}), [], NOW);
    assert.equal(stale.tone, 'warning');
    assert.equal(stale.title, 'Retention state unknown');

    const absent = watcherHealth({tick: {observer_tick_at: NOW - 10}, windows: [], missing_fence_tables: []}, [], NOW);
    assert.equal(absent.tone, 'warning');
    assert.equal(absent.title, 'Retention state unknown');

    const fenceFirst = watcherHealth(healthy({missing_fence_tables: ['database'], retention: {state: 'unreadable'}}), [], NOW);
    assert.equal(fenceFirst.title, 'Retries refused on database: fence table missing; run php artisan orrery:install --fence');
    assert.equal(fenceFirst.retention.state, 'unknown');
});

// Rejects: a run whose share ran out before it checked the partitions shown as healthy, and the "none"
// and "unreadable" states shown as healthy.
test('a retention run that checked no partition is "Retention state unknown", never healthy', () => {
    const cases = {
        'share ran out': {state: 'ok', at: NOW - 30, deleted: 0, behind: null, lag_seconds: null, partitions_unchecked: 4},
        none: {state: 'none'},
        unreadable: {state: 'unreadable'},
        'older than 3 ticks': {state: 'ok', at: NOW - 181, deleted: 10, behind: false, lag_seconds: null, partitions_unchecked: 0},
    };

    for (const [name, retention] of Object.entries(cases)) {
        const result = watcherHealth(healthy({retention}), [], NOW);
        assert.equal(result.tone, 'warning', name);
        assert.equal(result.title, 'Retention state unknown', name);
        assert.equal(result.retention.state, 'unknown', name);
    }

    assert.equal(RETENTION_STALE_SECONDS, 180);
    assert.equal(watcherHealth(healthy({retention: {...cases['older than 3 ticks'], at: NOW - 180}}), [], NOW).title, 'Watcher healthy');
});

// Rejects: the oldest heartbeat instead of the freshest, and 0 for no heartbeat.
test('watcherHealth reports the freshest heartbeat', () => {
    assert.equal(watcherHealth(health(10), [{seconds_ago: 12}, {seconds_ago: '3'}, {seconds_ago: null}], NOW).heartbeatAge, 3);
    assert.equal(watcherHealth(health(10), [], NOW).heartbeatAge, null);
    assert.equal(watcherHealth(health(10), null, NOW).heartbeatAge, null);
});

// Rejects: a batch counted from its pending and failed counts instead of the server's `running` (an `allowFailures()`
// batch with pending == failed and no active member made the count unknown), a batch whose `running` is false
// counted, and a `running` that is null or absent (an older answer, a failed member read) read as not running.
// Still passes: a server that answers `running` wrongly: the rule is the server's (BatchesTest).
test('runningBatches counts the batches that the server says run', () => {
    assert.equal(runningBatches(null), null);
    assert.equal(runningBatches({}), null);
    assert.equal(runningBatches({batches: null, unknown_reason: 'sqlite_reads_not_allowed'}), null);
    assert.deepEqual(runningBatches({batches: [], available: false}), {count: 0, atLeast: false});

    const batches = [
        {pending_jobs: 3, failed_jobs: 0, running: true},
        {pending_jobs: 2, failed_jobs: 2, running: false},
        {pending_jobs: 2, failed_jobs: 2, running: true},
        {pending_jobs: 5, failed_jobs: 0, finished_at: 1790000000.0, running: false},
        null,
    ];
    assert.deepEqual(runningBatches({batches}), {count: 2, atLeast: false});
    assert.deepEqual(runningBatches({batches: [{pending_jobs: 2, failed_jobs: 2, running: false}]}), {count: 0, atLeast: false});
    assert.deepEqual(runningBatches({batches: [...batches, {pending_jobs: 2, failed_jobs: 2, running: null}]}), {count: 2, atLeast: true});
    assert.deepEqual(runningBatches({batches: [{pending_jobs: 3, failed_jobs: 0}]}), {count: 0, atLeast: true});

    const full = Array.from({length: 50}, () => ({pending_jobs: 1, failed_jobs: 0, running: true}));
    assert.deepEqual(runningBatches({batches: full}), {count: 50, atLeast: true});
});

// Rejects: "Watcher healthy" from the last good health response after the health read failed,
// a footer before anything loaded, and a stale tick aged into "Tick is late" while the read fails.
test('shellHealth is never healthy while the health read fails', () => {
    assert.equal(shellHealth({health: null, workers: null, healthError: false}, NOW), null);

    const good = health(10);
    assert.equal(shellHealth({health: good, workers: [], healthError: false}, NOW).title, 'Watcher healthy');

    const failed = shellHealth({health: good, workers: [], healthError: true}, NOW);
    assert.equal(failed.tone, 'unknown');
    assert.equal(failed.title, 'Watcher health unknown');
    assert.equal(failed.tickAge, 10, 'the last data stays');

    // A tick that only looks late because the health read fails is not "Tick is late".
    assert.equal(shellHealth({health: health(STALE_TICK_SECONDS + 5), workers: [], healthError: true}, NOW).title, 'Watcher health unknown');
    assert.equal(shellHealth({health: health(STALE_TICK_SECONDS + 5), workers: [], healthError: false}, NOW).title, 'Tick is late');
    assert.equal(shellHealth({health: null, workers: [], healthError: true}, NOW).title, 'Watcher health unknown');
});

// Rejects: a footer that keeps computing from the last data after the page left its polls (a screen's own read
// met the expired session first, so no shell read failed): a false "Tick is late" and a heartbeat age that only grows.
// Still passes: a stopped page whose footer keeps the configuration notes of its last read.
test('shellHealth is unknown once the polls stopped', () => {
    const stopped = shellHealth({health: health(STALE_TICK_SECONDS + 5), workers: [{seconds_ago: 3}], healthError: false}, NOW, true);

    assert.equal(stopped.tone, 'unknown');
    assert.equal(stopped.title, 'Watcher health unknown');
    assert.equal(stopped.tickAge, null);
    assert.equal(stopped.heartbeatAge, null);
    assert.equal(shellHealth({health: health(10), workers: [], healthError: false}, NOW, false).title, 'Watcher healthy');
    assert.equal(shellHealth({health: null, workers: null, healthError: false}, NOW, true), null);
});

// Rejects: a null count shown as 0, and an "at least" count without its "+".
test('countLabel shows a capped count with "+" and an unknown count as a dash', () => {
    assert.equal(countLabel({count: 12, at_least: false}), '12');
    assert.equal(countLabel({count: 1000000, at_least: true}), '1M+');
    assert.equal(countLabel({count: 1500, at_least: false}), '1,500');
    assert.equal(countLabel({count: 60000, at_least: true}), '60k+');
    assert.equal(countLabel({count: null, at_least: false}), '—');
    assert.equal(countLabel(null), '—');
    assert.equal(countLabel(undefined), '—');
    assert.equal(countLabel({count: '7', at_least: false}), '7');
});

// Rejects: a definite truncated scan (the scan merges a capped keyed scan with an unkeyed fallback, so the page
// order proves nothing about what the scan missed), and "at least" for a scan that stopped before the window.
test('attentionCount is at least when a truncated scan may hide a recent group', () => {
    const mixed = {groups: [recentGroup(5), recentGroup(ATTENTION_FAILURE_SECONDS + 300)], truncated: true, scanned_from: NOW - 60};

    assert.deepEqual(attentionCount([], mixed, NOW), {count: 1, atLeast: true, possible: 0, unknown: []});
    assert.equal(attentionCount([], {...mixed, scanned_from: null}, NOW).atLeast, true);
    assert.equal(attentionCount([], {...mixed, scanned_from: String(NOW - ATTENTION_FAILURE_SECONDS)}, NOW).atLeast, true);
    assert.equal(attentionCount([], {...mixed, scanned_from: NOW - ATTENTION_FAILURE_SECONDS - 1}, NOW).atLeast, false,
        'a scan that reached past the window saw every recent failure');
});

const shellState = extra => ({workload: null, workloadError: false, groupsHour: {groups: []}, groupsHourError: false, ...extra});

// Rejects: no badge and a definite count while the workload never loaded, and stale over-limit queues
// counted as definite while the workload read fails.
test('shellAttention is at least with queue state unknown while the workload read fails', () => {
    assert.equal(shellAttention(shellState(), NOW), null, 'nothing before the first workload read');

    const never = shellAttention(shellState({workloadError: true, groupsHour: {groups: [recentGroup(5)]}}), NOW);
    assert.deepEqual(never, {count: 1, atLeast: true, possible: 0, unknown: ['queue state']});
    assert.equal(attentionBadge(never), '1+');
    assert.equal(attentionBadge(shellAttention(shellState({workloadError: true}), NOW)), '?');

    const stale = shellAttention(shellState({workloadError: true, workload: [row({name: 'a', over_limit: true}), row({name: 'b'})]}), NOW);
    assert.deepEqual(stale, {count: 0, atLeast: true, possible: 1, unknown: ['queue state']});
    assert.equal(attentionTitle(stale), '1 may need attention (queue state unknown)');

    const fine = shellAttention(shellState({workload: [row({name: 'a', over_limit: true})]}), NOW);
    assert.deepEqual(fine, {count: 1, atLeast: false, possible: 0, unknown: []});

    const groupsFailed = shellAttention(shellState({workload: [], groupsHourError: true}), NOW);
    assert.deepEqual(groupsFailed.unknown, ['failure groups']);
});

// Rejects: a short lag of a run that left partitions unchecked read as healthy (by design).
test('behind with a short lag and unchecked partitions is unknown, not ok', () => {
    const base = {state: 'ok', at: NOW - 30, deleted: 500, behind: true, lag_seconds: 120, partitions_unchecked: 2};

    assert.equal(retentionState(base, NOW).state, 'unknown');
    assert.equal(watcherHealth(healthy({retention: base}), [], NOW).title, 'Retention state unknown');
    assert.equal(retentionState({...base, lag_seconds: null, partitions_unchecked: 0}, NOW).state, 'unknown', 'behind without a lag proves nothing');
    assert.equal(retentionState({...base, partitions_unchecked: 0}, NOW).state, 'ok');
    assert.equal(retentionState({...base, lag_seconds: RETENTION_BEHIND_SECONDS}, NOW).state, 'behind');
});

// Rejects: a footer that stays healthy while the per-minute counts lag, a lag of 0 for an unreadable state,
// and a warning for an older server without the field.
test('watcherHealth warns when the per-minute counts lag more than 10 minutes or cannot be read', () => {
    const at = lag => ({state: 'ok', counted_through: NOW - lag, lag_seconds: lag});

    assert.equal(SAMPLES_BEHIND_SECONDS, 600);
    assert.deepEqual(samplesState(at(SAMPLES_BEHIND_SECONDS), NOW), {state: 'ok', lagSeconds: SAMPLES_BEHIND_SECONDS, reported: true});
    assert.deepEqual(samplesState(at(SAMPLES_BEHIND_SECONDS + 1), NOW), {state: 'behind', lagSeconds: SAMPLES_BEHIND_SECONDS + 1, reported: true});
    assert.equal(samplesState({state: 'ok', counted_through: String(NOW - 900), lag_seconds: 900}, NOW).state, 'behind', 'a numeric string');
    assert.deepEqual(samplesState({state: 'unreadable', counted_through: null, lag_seconds: null}, NOW), {state: 'unknown', lagSeconds: null, reported: true});
    assert.equal(samplesState({state: 'ok', counted_through: null, lag_seconds: null}, NOW).state, 'unknown');
    assert.equal(samplesState({state: 'none', counted_through: null, lag_seconds: null}, NOW).state, 'none');
    assert.equal(samplesState(undefined, NOW).state, 'unknown');
    assert.deepEqual(samplesState({state: 'unknown', counted_through: NOW + 600, lag_seconds: null}, NOW), {state: 'unknown', lagSeconds: null, reported: true}, 'an M after now');

    assert.equal(watcherHealth(healthy({samples: at(30)}), [], NOW).title, 'Watcher healthy');
    assert.equal(watcherHealth(healthy({samples: {state: 'none', counted_through: null, lag_seconds: null}}), [], NOW).title, 'Watcher healthy');
    assert.equal(watcherHealth(healthy(), [], NOW).title, 'Watcher healthy', 'an older response without the field');

    const behind = watcherHealth(healthy({samples: at(SAMPLES_BEHIND_SECONDS + 60)}), [], NOW);
    assert.equal(behind.tone, 'warning');
    assert.equal(behind.title, 'Counts are behind');
    assert.equal(behind.samples.lagSeconds, SAMPLES_BEHIND_SECONDS + 60);

    const unreadable = watcherHealth(healthy({samples: {state: 'unreadable', counted_through: null, lag_seconds: null}}), [], NOW);
    assert.equal(unreadable.tone, 'warning');
    assert.equal(unreadable.title, 'Count state unknown');

    const retentionFirst = watcherHealth(healthy({retention: {state: 'unreadable'}, samples: at(SAMPLES_BEHIND_SECONDS + 60)}), [], NOW);
    assert.equal(retentionFirst.title, 'Retention state unknown');
});

// Rejects: a healthy footer while operations wait for an operator, an unknown count shown as 0 or
// as healthy, an older response without the key shown as unknown, a wait title over a missing fence table, and an
// unprovable count that changes the tone.
test('operations that wait for an operator are a warning, and unprovable failures a note', () => {
    const one = watcherHealth(healthy({awaiting_resolution: {count: 1, at_least: false, operations: []}}), [], NOW);
    assert.equal(one.tone, 'warning');
    assert.equal(one.title, '1 operation waits for an operator: Resolve on the job page, or php artisan orrery:resolve --all');
    assert.deepEqual(one.awaiting, {count: 1, atLeast: false});
    assert.equal(watcherHealth(healthy({awaiting_resolution: {count: 50, at_least: true}}), [], NOW).title, 'At least 50 operations wait for an operator: Resolve on the job page, or php artisan orrery:resolve --all');
    assert.equal(watcherHealth(healthy({awaiting_resolution: {count: 0, at_least: false}}), [], NOW).title, 'Watcher healthy');
    assert.equal(awaitingOperations(healthy({awaiting_resolution: null})), null, 'unknown, never 0');
    const unknown = watcherHealth(healthy({awaiting_resolution: null}), [], NOW);
    assert.deepEqual([unknown.tone, unknown.title], ['warning', 'Waiting operations unknown'], 'a present null is unknown');
    assert.equal(watcherHealth(healthy(), [], NOW).title, 'Watcher healthy', 'an absent key (an older server) stays healthy');
    assert.equal(awaitingUnknown(healthy({awaiting_resolution: null, sqlite_shared_mirror: {reason: 'shared'}})), false, 'a refused mirror reports itself');
    assert.equal(watcherHealth(healthy({missing_fence_tables: ['database'], awaiting_resolution: {count: 2}}), [], NOW).title, 'Retries refused on database: fence table missing; run php artisan orrery:install --fence');

    const unprovable = watcherHealth(healthy({failed_records: {adapter: 'sql', unprovable_failures: {count: 3, at_least: false}}}), [], NOW);
    assert.equal(unprovable.tone, 'healthy');
    assert.equal(unprovable.unprovableNote, "3 failed jobs have no proven failed record: retry and forget them with Laravel's queue:retry or queue:forget.");
    assert.equal(unprovableNote(healthy({failed_records: {unprovable_failures: {count: 1, at_least: false}}})), "1 failed job has no proven failed record: retry and forget them with Laravel's queue:retry or queue:forget.");
    assert.equal(unprovableNote(healthy({failed_records: {unprovable_failures: {count: 5000, at_least: true}}})), "At least 5000 failed jobs have no proven failed record: retry and forget them with Laravel's queue:retry or queue:forget.");
    assert.equal(unprovableNote(healthy({failed_records: {unprovable_failures: {count: 0, at_least: false}}})), null);
    // A censored zero: none among the newest failed rows that the server reads, the rest unknown, never nothing.
    assert.equal(unprovableNote(healthy({failed_records: {unprovable_failures: {count: 0, at_least: true}}})), "No failed job among the newest 10,000 lacks a proven failed record; the older failed jobs were not checked, so it is unknown beyond them.");
    assert.equal(unprovableNote(healthy({failed_records: null})), null, 'unknown: no note, never "0"');
});

const WAL = 'SQLite: enable WAL (journal_mode wal) and a busy_timeout so Orrery can observe the queue without affecting it.';

// Gate fix 2. Rejects: "Watcher healthy" while the fence state of a rollback-mode SQLite queue is unknown, a fence
// state that reads as missing (unknown is not missing), an unknown fence that hides a missing one, and an entry
// without a name.
test('an unknown fence state is a warning after a missing fence table', () => {
    const unknown = watcherHealth(healthy({fence_unknown_connections: ['database', 'other', 7, '']}), [], NOW);
    assert.equal(unknown.tone, 'warning');
    assert.equal(unknown.title, 'Fence state unknown for database, other');
    assert.deepEqual(unknown.fenceUnknown, ['database', 'other']);
    assert.deepEqual(fenceUnknownConnections(healthy({fence_unknown_connections: null})), []);
    assert.deepEqual(fenceUnknownConnections(null), []);

    const missingFirst = watcherHealth(healthy({missing_fence_tables: ['database'], fence_unknown_connections: ['other']}), [], NOW);
    assert.equal(missingFirst.title, 'Retries refused on database: fence table missing; run php artisan orrery:install --fence');
    assert.equal(watcherHealth(healthy({fence_unknown_connections: ['database'], tick: {observer_tick_at: NOW - STALE_TICK_SECONDS - 1}}), [], NOW).title, 'Tick is late');
    assert.equal(watcherHealth(healthy({fence_unknown_connections: []}), [], NOW).title, 'Watcher healthy');
});

// Gate fix 2. Rejects: "Watcher healthy" beside an "enable WAL" hint, a hint repeated once per file, a hint that
// hides an earlier state, and a hint that disappears behind the fence title (it stays as a note).
test('the SQLite warnings are a warning and stay visible as notes', () => {
    const warnings = [{file: '/app/a.sqlite', message: WAL}, {file: '/app/b.sqlite', message: WAL}, {file: '/app/c.sqlite', message: 'Configured for WAL, but the file answered delete.'}, {file: 'x'}, null];
    assert.deepEqual(sqliteWarningMessages(healthy({sqlite_warnings: warnings})), [WAL, 'Configured for WAL, but the file answered delete.']);
    assert.deepEqual(sqliteWarningMessages(healthy({sqlite_warnings: null})), []);

    const only = watcherHealth(healthy({sqlite_warnings: warnings}), [], NOW);
    assert.equal(only.tone, 'warning');
    assert.equal(only.title, WAL);
    assert.deepEqual(only.configNotes, ['Configured for WAL, but the file answered delete.'], 'the title is not repeated as a note');

    const rollback = watcherHealth(healthy({fence_unknown_connections: ['database'], sqlite_warnings: warnings.slice(0, 1)}), [], NOW);
    assert.equal(rollback.title, 'Fence state unknown for database');
    assert.deepEqual(rollback.configNotes, [WAL]);

    const uninstrumentedFirst = watcherHealth(healthy({sqlite_warnings: warnings.slice(0, 1), uninstrumented_connections: [{connection: 'database', reason: 'swap_disabled'}]}), [], NOW);
    assert.equal(uninstrumentedFirst.title, "Pending and running jobs are not mirrored for database: the queue is not Orrery's.");
    assert.deepEqual(uninstrumentedFirst.configNotes, [WAL]);
    assert.deepEqual(watcherHealth(healthy(), [], NOW).configNotes, []);
});

// Gate fix 4. Rejects: "Watcher healthy" while two connections share a jobs table with different retry_after values,
// a warning without the connection names or the values, a retention title before this configuration hazard, and an
// entry whose values do not match its connections.
test('a jobs table shared with different retry_after values is a warning that names the connections', () => {
    const entry = {connections: ['database', 'database-secondary'], table: 'jobs', values: [900, 90]};
    const text = 'database (retry_after 900 s) and database-secondary (90 s) share the jobs table: a worker of database-secondary can run a job again when workers of both serve the same queue.';
    assert.deepEqual(sharedRetryAfterNotes(healthy({shared_jobs_table_retry_after: [entry, {connections: ['a'], table: 'jobs', values: [1, 2]}, null]})), [text]);
    assert.deepEqual(sharedRetryAfterNotes(healthy({shared_jobs_table_retry_after: [{connections: ['a', 'b', 'c'], table: 'q', values: [60, 30, 60]}]})), [
        'a (retry_after 60 s), b (30 s) and c (60 s) share the q table: a worker of b can run a job again when workers of all of them serve the same queue.',
    ]);
    assert.deepEqual(sharedRetryAfterNotes(healthy({shared_jobs_table_retry_after: null})), []);

    const result = watcherHealth(healthy({shared_jobs_table_retry_after: [entry]}), [], NOW);
    assert.equal(result.tone, 'warning');
    assert.equal(result.title, text);
    assert.deepEqual(result.configNotes, []);

    const sqliteFirst = watcherHealth(healthy({sqlite_warnings: [{file: 'f', message: WAL}], shared_jobs_table_retry_after: [entry]}), [], NOW);
    assert.equal(sqliteFirst.title, WAL);
    assert.deepEqual(sqliteFirst.configNotes, [text]);

    const behind = watcherHealth(healthy({shared_jobs_table_retry_after: [entry], retention: {state: 'ok', at: NOW - 30, deleted: 500, behind: true, lag_seconds: 900, partitions_unchecked: 0}}), [], NOW);
    assert.equal(behind.title, text, 'the configuration hazard comes before retention');
    assert.equal(watcherHealth(healthy({shared_jobs_table_retry_after: []}), [], NOW).title, 'Watcher healthy');
});

// Review fix (medium). Rejects: an unknown fence state that hides operations waiting for an operator or an
// uninstrumented connection (in no-reads mode both can hold at once).
test('fence unknown never hides waiting operations', () => {
    const waiting = watcherHealth(healthy({fence_unknown_connections: ['database'], awaiting_resolution: {count: 2}}), [], NOW);
    assert.ok(waiting.title.startsWith('2 operations wait for an operator'), waiting.title);
    assert.deepEqual(waiting.fenceUnknown, ['database']);

    const uninstrumented = watcherHealth(healthy({fence_unknown_connections: ['database'], uninstrumented_connections: [{connection: 'database', reason: 'swap_disabled'}]}), [], NOW);
    assert.equal(uninstrumented.title, "Pending and running jobs are not mirrored for database: the queue is not Orrery's.");
    assert.deepEqual(uninstrumented.fenceUnknown, ['database']);
});

// Review fix (low). Rejects: a retry_after text that claims a double run for workers that never share a queue.
test('the retry_after warning says when a job can run twice', () => {
    const [note] = sharedRetryAfterNotes(healthy({shared_jobs_table_retry_after: [{connections: ['database', 'database-secondary'], table: 'jobs', values: ['900', 90]}]}));
    assert.equal(note, 'database (retry_after 900 s) and database-secondary (90 s) share the jobs table: a worker of database-secondary can run a job again when workers of both serve the same queue.');
});

const INDEX_HINT = 'Run php artisan orrery:install --indexes to build the missing orrery_jobs indexes.';
const UPGRADE = 'The Orrery schema is older than this release. Run php artisan orrery:resolve --all first.';

// Rejects: a footer that ignores each new health field, a warning for a field that is fine
// (false, valid, an empty list), a warning from an absent field (an older server), and the hint of the server
// replaced by a hard-coded command.
test('configurationWarnings names each D8 configuration problem', () => {
    assert.deepEqual(configurationWarnings(healthy()), []);
    assert.deepEqual(configurationWarnings(null), []);
    assert.deepEqual(configurationWarnings(healthy({
        schema: {installed: 9, expected: 9, hint: null},
        redaction: {valid: true, errors: []},
        guard_invalid: false,
        app_key_missing: false,
        bulk_config: {valid: true, forget_max: 10000},
        retention_config: {valid: true, errors: [], unmatched: []},
        missing_indexes: [],
    })), [], 'every field fine');

    const all = configurationWarnings(healthy({
        schema: {installed: 6, expected: 9, hint: UPGRADE},
        redaction: {valid: false, errors: [{field: 'redact.patterns.2'}, {field: 'redact.keys'}]},
        guard_invalid: true,
        app_key_missing: true,
        bulk_config: {valid: false, forget_max: null},
        retention_config: {valid: false, errors: ['trim_overrides.1.completed must be a whole number of minutes'], unmatched: null},
        missing_indexes: [{index: 'orrery_jobs_st_attempts', hint: INDEX_HINT}, {index: 'orrery_jobs_st_gap', hint: INDEX_HINT}, {hint: 'x'}],
    }));

    assert.deepEqual(all.map(warning => warning.key), ['schema', 'redaction', 'guard', 'app_key', 'bulk_config', 'retention_config', 'missing_indexes']);
    assert.equal(all[0].text, `The Orrery schema is version 6, this release needs 9. ${UPGRADE}`);
    assert.equal(all[1].text, 'The redaction configuration is invalid (redact.patterns.2, redact.keys): job values are not shown until it is fixed.');
    assert.equal(all[2].text, 'orrery.guard names no usable guard: a signed-out request gets 403 instead of 401.');
    assert.equal(all[3].text, 'APP_KEY is empty: bulk forget is refused, and failure-group keys are not secret.');
    assert.equal(all[4].text, 'orrery.bulk.forget_max is not a whole number from 1 to 100,000: every bulk forget is refused.');
    assert.equal(all[5].text, 'The retention configuration is invalid, so retention does not run: trim_overrides.1.completed must be a whole number of minutes');
    assert.equal(all[6].text, `The stuck lists and stuck alerts need the indexes orrery_jobs_st_attempts and orrery_jobs_st_gap. ${INDEX_HINT}`);

    // Without an installed version the server's hint says what is wrong (a refused sibling file, tables before the
    // marker, no tables): it stands alone, never behind "not installed".
    const REFUSED = 'Orrery cannot use its SQLite mirror file /app/database.orrery.sqlite: its schema is from before Orrery 0.1; stop the workers and delete the file with its -wal and -shm files, and Orrery rebuilds it.';
    assert.equal(configurationWarnings(healthy({schema: {installed: null, expected: 9, hint: REFUSED}}))[0].text, REFUSED);
    assert.equal(configurationWarnings(healthy({schema: {installed: null, expected: 9, hint: UPGRADE}}))[0].text, UPGRADE);
    assert.equal(configurationWarnings(healthy({schema: {installed: null, expected: 9, hint: null}}))[0].text, 'The Orrery schema is not installed.');
    assert.equal(configurationWarnings(healthy({missing_indexes: [{index: 'orrery_jobs_st_gap', hint: null}]}))[0].text, 'The stuck lists and stuck alerts need the index orrery_jobs_st_gap.');
});

// Rejects: an unmatched override shown as a warning (it may be meant for a queue that has not run yet), an
// unmatched list that the server could not read (null) shown as "none", and 1-based entry numbers.
test('configurationNotes names the retention overrides that match no queue', () => {
    assert.deepEqual(configurationNotes(healthy({retention_config: {valid: true, errors: [], unmatched: [0, 2]}})), ['orrery.trim_overrides[0] and [2] match no known queue.']);
    assert.deepEqual(configurationNotes(healthy({retention_config: {valid: true, errors: [], unmatched: [1]}})), ['orrery.trim_overrides[1] matches no known queue.']);
    assert.deepEqual(configurationNotes(healthy({retention_config: {valid: true, errors: [], unmatched: null}})), []);
    assert.deepEqual(configurationNotes(healthy()), []);

    const result = watcherHealth(healthy({retention_config: {valid: true, errors: [], unmatched: [3]}}), [], NOW);
    assert.equal(result.title, 'Watcher healthy');
    assert.deepEqual(result.configNotes, ['orrery.trim_overrides[3] matches no known queue.']);
});

// Unknown is never zero. Rejects: a check that the server could not read (null) shown as healthy, an absent
// field of an older server shown as unknown, and an unknown line while the mirror refusal explains it.
test('configurationUnknown lists the checks that could not be read', () => {
    assert.deepEqual(configurationUnknown(healthy()), []);
    assert.deepEqual(configurationUnknown(healthy({schema: null, redaction: null, guard_invalid: null, retention_config: null, missing_indexes: null})),
        ['schema version', 'redaction', 'guard', 'retention configuration', 'indexes']);
    assert.deepEqual(configurationUnknown(healthy({schema: null, sqlite_shared_mirror: 'refused'})), []);

    const unknown = watcherHealth(healthy({schema: null, missing_indexes: null}), [], NOW);
    assert.equal(unknown.tone, 'warning');
    assert.equal(unknown.title, 'Configuration checks unknown: schema version and indexes');
});

// Rejects: a refused mirror shown as "No tick yet" (the refusal reason never reached the screen), a refused or
// uninstalled schema hidden behind the tick rule, and a refusal title that a later rule replaces.
test('watcherHealth names a refused mirror before the tick', () => {
    const refusal = 'Orrery refuses orrery.database: it names the SQLite file of the queue connection.';
    const refused = watcherHealth(healthy({tick: {observer_tick_at: null}, sqlite_shared_mirror: refusal, schema: null,
        awaiting_resolution: null, retention: {state: 'unreadable'}}), [], NOW);
    assert.deepEqual([refused.tone, refused.title], ['warning', refusal]);

    const sibling = 'The sibling file cannot be created.';
    const siblingRefused = watcherHealth(healthy({tick: {observer_tick_at: null}, sqlite_shared_mirror: null,
        schema: {installed: null, expected: 9, hint: sibling}}), [], NOW);
    assert.deepEqual([siblingRefused.tone, siblingRefused.title], ['warning', sibling]);
    assert.ok(!siblingRefused.configNotes.includes(sibling), 'the title is not repeated as a note');

    assert.equal(watcherHealth(healthy({tick: {observer_tick_at: null}, sqlite_shared_mirror: null}), [], NOW).title, 'No tick yet');
    assert.equal(watcherHealth(healthy({tick: {observer_tick_at: null}, schema: {installed: 6, expected: 9, hint: null}}), [], NOW).title,
        'No tick yet', 'an installed older schema keeps the tick rule first');
});

// Rejects: a schema warning hidden behind a missing fence table (the bulk runs and the new reads fail on an older
// schema), a configuration warning that hides a late tick or waiting operations, and the other problems lost
// below the title (they stay visible as notes).
test('watcherHealth orders the configuration warnings and keeps them as notes', () => {
    const schema = {installed: 6, expected: 9, hint: UPGRADE};
    const app = 'APP_KEY is empty: bulk forget is refused, and failure-group keys are not secret.';

    assert.equal(watcherHealth(healthy({schema, missing_fence_tables: ['database']}), [], NOW).title, `The Orrery schema is version 6, this release needs 9. ${UPGRADE}`);
    assert.equal(watcherHealth(healthy({schema, tick: {observer_tick_at: NOW - STALE_TICK_SECONDS - 1}}), [], NOW).title, 'Tick is late');

    const waiting = watcherHealth(healthy({app_key_missing: true, awaiting_resolution: {count: 2}}), [], NOW);
    assert.ok(waiting.title.startsWith('2 operations wait for an operator'), waiting.title);
    assert.deepEqual(waiting.configNotes, [app]);

    const both = watcherHealth(healthy({app_key_missing: true, guard_invalid: true, uninstrumented_connections: [{connection: 'database', reason: 'swap_disabled'}]}), [], NOW);
    assert.equal(both.tone, 'warning');
    assert.equal(both.title, 'orrery.guard names no usable guard: a signed-out request gets 403 instead of 401.');
    assert.deepEqual(both.configNotes, [app], 'the title is not repeated');

    assert.equal(watcherHealth(healthy({missing_indexes: [{index: 'orrery_jobs_st_gap', hint: INDEX_HINT}]}), [], NOW).tone, 'warning');
    assert.equal(watcherHealth(healthy({bulk_config: {valid: false, forget_max: null}}), [], NOW).tone, 'warning');
    assert.equal(watcherHealth(healthy({redaction: {valid: false, errors: []}}), [], NOW).title, 'The redaction configuration is invalid: job values are not shown until it is fixed.');
});

// Rejects: the kept wide-layout choice ("open") covering a phone screen with the inspector, an
// overlay that the wide layout obeys, and a missing state shown as open.
test('inspectorShown follows the kept choice when wide and the overlay when narrow', () => {
    assert.equal(inspectorShown({narrow: false, inspectorOpen: true, overlayOpen: false}), true);
    assert.equal(inspectorShown({narrow: false, inspectorOpen: false, overlayOpen: true}), false);
    assert.equal(inspectorShown({narrow: true, inspectorOpen: true, overlayOpen: false}), false);
    assert.equal(inspectorShown({narrow: true, inspectorOpen: false, overlayOpen: true}), true);
    assert.equal(inspectorShown(null), false);
});

// Rejects: the wide "Live · 09:32:07" without the zone (the narrow form had it), a fresh poll
// read as stale, an old one read as live, and a time for a part that never loaded.
test('liveIndicator shows the update time with its zone in both widths', () => {
    const at = 1790587927;

    assert.deepEqual(liveIndicator(at, at + 5, false),
        {tone: 'live', text: 'Live · 09:32:07 UTC', short: 'Live · 09:32 UTC', title: 'Updated every few seconds'});
    assert.equal(liveIndicator(at, at + 31, false).text, 'Stale · 09:32:07 UTC');
    assert.equal(liveIndicator(at, at + 31, false).title, 'Last update 31 s ago');
    assert.equal(liveIndicator(at, at + 5, true).tone, 'stale');
    assert.equal(liveIndicator(at, null, false).tone, 'live');
    assert.deepEqual(liveIndicator(null, at, true), {tone: 'stale', text: 'Stale · no data yet', short: 'Stale', title: 'The live data did not load'});
    assert.deepEqual(liveIndicator(null, at, false), {tone: 'unknown', text: 'Connecting…', short: 'Connecting…', title: null});

    globalThis.Orrery = {displayTimezone: 'Asia/Tokyo'};

    try {
        assert.equal(liveIndicator(at, at, false).text, 'Live · 18:32:07 GMT+9');
    } finally {
        delete globalThis.Orrery;
    }
});

// Rejects: a 200 answer whose body is not a list (an HTML page of a proxy, an error object) read as an empty
// list, which shows "No database queues" and a fresh, successful live read.
test('listBody and objectBody throw on a body of the wrong shape', () => {
    assert.deepEqual(listBody([{a: 1}]), [{a: 1}]);
    assert.deepEqual(listBody([]), []);
    assert.throws(() => listBody({queues: []}), TypeError);
    assert.throws(() => listBody('<html>'), TypeError);
    assert.throws(() => listBody(null), TypeError);

    assert.deepEqual(objectBody({a: 1}), {a: 1});
    assert.throws(() => objectBody([]), TypeError);
    assert.throws(() => objectBody('<html>'), TypeError);
    assert.throws(() => objectBody(null), TypeError);
});

// Rejects: no count for {count: 0, atLeast: true} (some batches could not be read, so running ones cannot
// be ruled out), a definite "0 running", and a count for a known zero.
test('batchesCount says unknown when no running batch is known but some may run', () => {
    assert.deepEqual(batchesCount({count: 0, atLeast: true}), {text: '—', title: 'Running batches unknown'});
    assert.deepEqual(batchesCount({count: 0, atLeast: false}), {text: null, title: null});
    assert.deepEqual(batchesCount({count: 3, atLeast: true}), {text: 'at least 3 running', title: null});
    assert.deepEqual(batchesCount({count: 1200, atLeast: false}), {text: '1,200 running', title: null});
    // Before the first read nothing; a failed read or an unreadable list is unknown, never "no running batch".
    assert.deepEqual(batchesCount(null), {text: null, title: null});
    assert.deepEqual(batchesCount({count: 2, atLeast: false}, true), {text: '—', title: 'Running batches unknown'});
    assert.deepEqual(batchesCount({count: null, atLeast: false}), {text: '—', title: 'Running batches unknown'});
});

// Rejects: a heartbeat age frozen at the server's read time (it never advances in the browser), and a
// negative age when the worker's host clock runs ahead ("heartbeat -2 s ago").
// Still passes: an age computed against the browser clock instead of nowSeconds() when the caller passes that.
test('heartbeatAge grows from last_seen_at and is never negative', () => {
    assert.equal(heartbeatAge({last_seen_at: NOW - 5, seconds_ago: 1}, NOW + 30), 35);
    assert.equal(heartbeatAge({last_seen_at: String(NOW + 3), seconds_ago: -3}, NOW), 0);
    assert.equal(heartbeatAge({seconds_ago: 12.5}, NOW), 12.5);
    assert.equal(heartbeatAge({seconds_ago: -2}, NOW), 0);
    assert.equal(heartbeatAge({seconds_ago: null}, NOW), null);
    assert.equal(heartbeatAge(null, NOW), null);
});

// Rejects: a screen read that takes any 200 body, so a login page answered with status 200 reads as "none";
// and an unknown list (null) refused where the endpoint answers it.
// Still passes: a body whose list holds rows of the wrong shape (each screen reads its rows defensively).
test('bodyWith accepts only an object with the named list', () => {
    const body = {jobs: [], total: null};

    assert.equal(bodyWith(body, 'jobs'), body);
    assert.throws(() => bodyWith('<html>login</html>', 'jobs'), TypeError);
    assert.throws(() => bodyWith({}, 'jobs'), TypeError);
    assert.throws(() => bodyWith({batches: null}, 'batches'), TypeError);
    assert.deepEqual(bodyWith({batches: null, unknown_reason: 'x'}, 'batches', {nullable: true}), {batches: null, unknown_reason: 'x'});
    assert.throws(() => bodyWith([], 'jobs'), TypeError);

    assert.equal(isShapeError(new TypeError('x')), true);
    assert.equal(isShapeError({response: {status: 500}}), false);
    assert.equal(isShapeError(null), false);
});

// Rejects (nit): a degradation tooltip that states an exact count while the server kept only the newest windows, and
// a tooltip for no window.
// Still passes: a count of windows older than 15 minutes (watcherHealth() filters them first).
test('degradationTitle says "at least" for truncated windows', () => {
    assert.equal(degradationTitle({recentWindows: 3, windowsTruncated: false}), '3 degradation windows in the last 15 minutes');
    assert.equal(degradationTitle({recentWindows: 1, windowsTruncated: true}), 'At least 1 degradation window in the last 15 minutes');
    assert.equal(degradationTitle({recentWindows: 0}), null);
    assert.equal(degradationTitle(null), null);
    assert.equal(watcherHealth({...health(10), windows: [], truncated: true}, [], NOW).windowsTruncated, true);
});

// Rejects: a quiet worker shown with the green dot, a quiet label without
// the heartbeat age, a missing state guessed as live, and the age frozen at
// the read. It would still pass a label that rounds the age differently.
test('workerState: amber for a quiet worker with the age of its heartbeat', () => {
    assert.deepEqual(workerState({state: 'quiet', last_seen_at: NOW - 42}, NOW), {key: 'quiet', label: 'No heartbeat for 42 s'});
    assert.deepEqual(workerState({state: 'quiet', last_seen_at: NOW - 42}, NOW + 30), {key: 'quiet', label: 'No heartbeat for 1 min 12 s'});
    assert.deepEqual(workerState({state: 'quiet'}, NOW), {key: 'quiet', label: 'No recent heartbeat'});
    assert.deepEqual(workerState({state: 'live', heartbeat_fresh: true, last_seen_at: NOW - 3}, NOW), {key: 'live', label: 'Fresh heartbeat'});
    assert.deepEqual(workerState({state: 'live', heartbeat_fresh: false, active_leases: 1}, NOW), {key: 'live', label: 'No fresh heartbeat: live through an active lease'});
    assert.deepEqual(workerState({heartbeat_fresh: true}, NOW), {key: 'unknown', label: 'Worker state unknown'});
    assert.deepEqual(workerState(null, NOW), {key: 'unknown', label: 'Worker state unknown'});
});
