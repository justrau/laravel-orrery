// Tests of resources/js/screens/overview/overview.js. `pnpm test` runs in UTC.

import {test} from 'node:test';
import assert from 'node:assert/strict';

import {
    controlErrorText,
    aliasesOf,
    approximately,
    attentionItems,
    attentionSplit,
    attentionSummary,
    readAttentionExpanded,
    writeAttentionExpanded,
    attentionSubtitle,
    completedInWindow,
    controlOutcome,
    controlRequests,
    controlSheet,
    countOf,
    globalControlSelection,
    groupCountText,
    groupNeedsAttention,
    groupQueues,
    groupsSubtitle,
    hasGlobalPause,
    limitFooter,
    nativeContinueCommands,
    num,
    overLimitSinceText,
    overviewAttention,
    queueControlSelection,
    queuesSubtitle,
    queueState,
    rateText,
    retentionOf,
    throughputAnnotations,
    throughputBuckets,
    throughputRate,
    throughputSubtitle,
} from '../../resources/js/screens/overview/overview.js';

// 2026-09-28 09:00:00 UTC
const NOW = 1790586000;

// Rejects: Number(), which turns null into 0.
test('num reads numbers and numeric strings only', () => {
    assert.equal(num('12.5'), 12.5);
    assert.equal(num(3), 3);
    assert.equal(num(null), null);
    assert.equal(num('x'), null);
});

// Rejects: `connection` when `connections` lists the aliases, and an empty alias that is kept.
test('aliasesOf prefers the merged aliases', () => {
    assert.deepEqual(aliasesOf({connection: 'a', connections: ['a', 'b', '', 7]}), ['a', 'b']);
    assert.deepEqual(aliasesOf({connection: 'a', connections: []}), ['a']);
    assert.deepEqual(aliasesOf({connection: 'a'}), ['a']);
    assert.deepEqual(aliasesOf(null), []);
});

const cause = (scope, source = 'orrery', extra = {}) => ({scope, source, issued_at: NOW, issued_by: 'cli', possible: false, ...extra});
const known = {pauses: [], paused_unknown: false};

// Rejects: an unknown pause state shown as running (unknown is never "not paused").
test('queueState: a pause wins, then an unknown pause state', () => {
    assert.equal(queueState({pauses: [cause('queue')], paused_unknown: true, over_limit: true, processes: 2}).pill.key, 'paused');

    const unknown = queueState({pauses: [], paused_unknown: true, over_limit: false, processes: 2});
    assert.deepEqual(unknown.pill, {key: 'unknown', label: 'Pause state unknown'});
    assert.equal(unknown.pause.pausedUnknown, true);
    assert.equal(unknown.pause.paused, false);
});

// Rejects: "Running" for a queue over its limit, and "Running" with zero workers.
test('queueState: over limit before running, and running needs a live worker', () => {
    assert.deepEqual(queueState({...known, over_limit: true, processes: 2}).pill, {key: 'over', label: 'Over limit'});
    assert.deepEqual(queueState({...known, processes: '2'}).pill, {key: 'running', label: 'Running'});
    assert.deepEqual(queueState({...known, processes: 0}).pill, {key: 'idle', label: 'No workers'});
});

// Rejects: "Running" with a green dot for a queue whose every worker is quiet,
// and "Workers quiet" while one worker is live. It would still pass a pill
// that names the quiet count instead of the label.
test('queueState: a queue whose every worker is quiet is not running', () => {
    assert.deepEqual(queueState({...known, processes: 2, quiet_processes: 2}).pill, {key: 'quiet', label: 'Workers quiet'});
    assert.deepEqual(queueState({...known, processes: 2, quiet_processes: 1}).pill, {key: 'running', label: 'Running'});
    assert.deepEqual(queueState({...known, processes: 0, quiet_processes: 0}).pill, {key: 'idle', label: 'No workers'});
});

// Rejects: "Workers quiet" or "Running" for a queue whose workers are quiet
// or of unknown state, with at least one unknown (busy and dead look the
// same on an uninstrumented connection). It would still pass another label.
test('queueState: workers of unknown state are never quiet', () => {
    const unknown = {key: 'unknown', label: 'Worker state unknown'};

    assert.deepEqual(queueState({...known, processes: 2, quiet_processes: 1, unknown_state_processes: 1}).pill, unknown);
    assert.deepEqual(queueState({...known, processes: 2, quiet_processes: 0, unknown_state_processes: 2}).pill, unknown);
    assert.deepEqual(queueState({...known, processes: 3, quiet_processes: 1, unknown_state_processes: 1}).pill, {key: 'running', label: 'Running'});
});

// Rejects: a pauses field that is not a list (or an invalid cause) read as a pause, or as known off.
test('queueState reads only a list of causes as pauses', () => {
    assert.equal(queueState({pauses: true, processes: 1}).pause.paused, false);
    assert.equal(queueState({pauses: 'yes', processes: 1}).pill.key, 'unknown');
    assert.equal(queueState({pauses: [{scope: 'x', source: 'orrery'}], paused_unknown: false, processes: 1}).pill.key, 'running');
    assert.equal(queueState({...known, over_limit: 'true', processes: 1}).overLimit, false);
});

// Rejects: the state order broken for a card: over limit hidden behind an unknown pause, and an unknown backlog shown as running.
test('queueState follows the shared state order', () => {
    assert.deepEqual(queueState({...known, over_limit: true, paused_unknown: true, processes: 2}).pill, {key: 'over', label: 'Over limit'});
    assert.equal(queueState({...known, over_limit: true, paused_unknown: true, processes: 2}).pause.pausedUnknown, true);

    const backlog = queueState({...known, backlog_unknown: true, over_limit: null, length: null, processes: 2});
    assert.deepEqual(backlog.pill, {key: 'unknown', label: 'Backlog unknown'});
    assert.equal(backlog.backlogUnknown, true);
    assert.equal(backlog.overLimit, false);
});

// Rejects: Laravel's pause read as running, a label that hides a cause, and Laravel's global pause without its command.
test('every cause shows on the card', () => {
    const native = queueState({...known, connection: 'database', name: 'emails', pauses: [cause('queue', 'queue:pause')], over_limit: true, processes: 2});
    assert.deepEqual(native.pill, {key: 'paused', label: 'Paused (queue:pause)'});
    assert.equal(native.hint, null, 'Orrery\'s Continue lifts a queue:pause');

    const all = queueState({...known, pauses: [cause('global', 'queue:pause --all')], queue_cause_unknown: true, processes: 2});
    assert.deepEqual(all.pill, {key: 'paused', label: 'Paused (queue:pause --all)'});
    assert.equal(all.hint, "Resume with php artisan queue:continue --all · Own pause state unknown while Laravel's global pause is on");

    const both = queueState({...known, pauses: [cause('connection'), cause('queue')], processes: 2});
    // The queue's own pause is never dropped beside the connection's.
    assert.deepEqual(both.pill, {key: 'paused', label: 'Paused (connection, queue)'});
    assert.equal(both.hint, null);

    // A definite cause wins over an unknown part.
    assert.equal(queueState({pauses: [cause('queue')], paused_unknown: true, processes: 2}).pill.key, 'paused');
});

// Rejects: an unread or masked native state read as running, and a possible pause read as a pause.
test('an unknown native state is never Running, and a possible pause is not Paused', () => {
    const unread = queueState({...known, native_unknown: true, processes: 3});
    assert.deepEqual(unread.pill, {key: 'unknown', label: 'Pause state unknown'});
    assert.equal(unread.hint, "Laravel's pause state unknown; a queue:continue run from the command line is not seen");
    assert.equal(queueState({processes: 3}).pill.key, 'unknown', 'no pauses list');

    const possible = queueState({...known, native_unknown: true, pauses: [cause('queue', 'orrery', {possible: true})], processes: 3});
    assert.deepEqual(possible.pill, {key: 'unknown', label: 'Possibly paused'});
    assert.deepEqual(queueState({...known, native_unknown: true, pauses: [cause('queue', 'orrery', {possible: true})], over_limit: true, processes: 3}).pill, {key: 'over', label: 'Over limit'});
});

// Rejects: "No workers" for an unknown worker count, and a throw (or "No workers") for a missing row.
test('queueState of unknown workers or a missing row is unknown', () => {
    assert.deepEqual(queueState({...known, processes: null}).pill, {key: 'unknown', label: 'Workers unknown'});
    assert.deepEqual(queueState({...known}).pill, {key: 'unknown', label: 'Workers unknown'});
    assert.deepEqual(queueState(null).pill, {key: 'unknown', label: 'Unknown'});
    assert.equal(queueState(null).pause.paused, false);
});

// Rejects: a censored start without "at least", and "Within limit" for a queue without a limit.
test('overLimitSinceText and limitFooter', () => {
    assert.equal(overLimitSinceText({over_limit_since: NOW + 17 * 60}, NOW + 3600), 'since 09:17 UTC');
    assert.equal(overLimitSinceText({over_limit_since: NOW, over_limit_since_censored: true}, NOW + 3600), 'at least since 09:00 UTC');
    // A start of an earlier day carries its date.
    assert.equal(overLimitSinceText({over_limit_since: NOW - 3 * 86400}, NOW + 3600), 'since 2026-09-25 09:00 UTC');
    assert.equal(overLimitSinceText({over_limit_since: null}, NOW), null);

    assert.deepEqual(limitFooter({over_limit: true, over_limit_since: NOW}, NOW), {tone: 'over', text: 'Over limit since 09:00 UTC'});
    assert.deepEqual(limitFooter({over_limit: true, over_limit_since: NOW}, NOW + 86400), {tone: 'over', text: 'Over limit since 2026-09-28 09:00 UTC'});
    assert.deepEqual(limitFooter({over_limit: true}), {tone: 'over', text: 'Over limit'});
    assert.deepEqual(limitFooter({over_limit: false, threshold: 60}), {tone: 'plain', text: 'Within limit'});
    assert.deepEqual(limitFooter({over_limit: false, threshold: 0}), {tone: 'plain', text: 'No limit'});
    assert.deepEqual(limitFooter(null), {tone: 'plain', text: 'Limit state unknown'});
    // An unknown backlog has an unknown limit state, never "Within limit".
    assert.deepEqual(limitFooter({backlog_unknown: true, over_limit: null, threshold: 60}), {tone: 'plain', text: 'Limit state unknown'});
    assert.deepEqual(limitFooter({over_limit: null, threshold: 60}), {tone: 'plain', text: 'Limit state unknown'});
});

// Rejects: 0 for an unknown count, a capped count without "at least", and the list's `truncated` as the qualifier.
test('countOf and groupCountText', () => {
    assert.equal(countOf('1204'), '1,204');
    assert.equal(countOf(null), '—');
    assert.equal(groupCountText({count: 27, exact: true}), '27');
    assert.equal(groupCountText({count: 5000, count_capped: true, exact: false}), 'at least 5,000');
    assert.equal(groupCountText({count: 27, count_capped: false, exact: false}), 'at least 27');
    assert.equal(groupCountText({count: null}), '—');
});

// Rejects: an exclusive 15-minute limit, and an unknown time that needs attention.
test('groupNeedsAttention: a failure in the last 15 minutes', () => {
    assert.equal(groupNeedsAttention({last_failed_at: NOW - 900}, NOW), true);
    assert.equal(groupNeedsAttention({last_failed_at: String(NOW - 10)}, NOW), true);
    assert.equal(groupNeedsAttention({last_failed_at: NOW - 901}, NOW), false);
    assert.equal(groupNeedsAttention({last_failed_at: null}, NOW), false);
});

// Rejects: a name repeated for each connection, and an empty string for no queue.
test('groupQueues lists the distinct queue names', () => {
    assert.equal(groupQueues({queues: ['database:emails', 'other:emails', 'database:default']}), 'emails, default');
    assert.equal(groupQueues({queues: []}), '—');
    assert.equal(groupQueues(null), '—');
});

function queueRow(name, extra = {}) {
    return {name, connection: 'database', over_limit: true, pauses: [], paused_unknown: false, length: 120, processes: 1, oldest_age: 90, threshold: 60, ...extra};
}

// Rejects: a paused queue that still needs attention, an unknown pause state that is dropped, and a queue within its limit.
test('attentionItems takes the unpaused queues over their limit, largest wait first', () => {
    const items = attentionItems([
        queueRow('small', {oldest_age: 70}),
        queueRow('paused', {pauses: [cause('queue')]}),
        queueRow('within', {over_limit: false}),
        queueRow('big', {oldest_age: '600', paused_unknown: true, processes: 3, over_limit_since: NOW}),
        queueRow('unknown age', {oldest_age: null}),
        queueRow('native', {pauses: [cause('queue', 'queue:pause')]}),
        queueRow('native unknown', {native_unknown: true, pauses: [cause('queue', 'orrery', {possible: true})], oldest_age: 80}),
        queueRow('backlog', {backlog_unknown: true, over_limit: null, length: null}),
        {name: 'broken'},
    ], null, [], NOW);

    assert.deepEqual(items.map(item => item.label), ['big', 'native unknown', 'small', 'unknown age']);
    // A possible pause never hides an over-limit queue.
    assert.equal(items[1].detail, "120 ready on 1 worker · possibly paused · Laravel's pause state unknown; a queue:continue run from the command line is not seen");
    assert.equal(items[0].type, 'queue');
    assert.equal(items[0].key, 'queue:database:big');
    assert.equal(items[0].title, 'big has waited 10 min, over its 60 s limit');
    assert.equal(items[0].detail, '120 ready on 3 workers · over the limit since 09:00 UTC · pause state unknown');
    assert.equal(items[2].detail, '120 ready on 1 worker');
    assert.equal(items[0].to, '/queues/database/big');
});

// Rejects: a queue label without its connection when two connections have the queue, and a link that is not encoded or that encodes the name's "/" as %2F.
test('attentionItems labels a duplicate queue name with its connection', () => {
    const items = attentionItems([
        queueRow('a/b'),
        queueRow('a/b', {connection: 'other', over_limit: false}),
    ], null, [], NOW);

    assert.equal(items.length, 1);
    assert.equal(items[0].label, 'a/b (database)');
    assert.equal(items[0].to, '/queues/database/a/b');
});

// Rejects: an old failure group in the list, a list that is not newest first, a capped count without "at least", and a start without "or earlier" for fallback rows.
test('attentionItems takes the recent failure groups, active ones before the queues with workers', () => {
    const groupsData = {
        truncated: true,
        groups: [
            {key: 'old', count: 9, last_failed_at: NOW - 3000, first_failed_at: NOW - 3500, exception_class: 'E', job: 'J', queues: []},
            {key: 'recent', count: 4, count_capped: true, exact: false, last_failed_at: NOW - 600, first_failed_at: NOW - 700, exception_class: 'App\\Ex\\Boom', job: 'App\\Jobs\\Sync', message: 'down', queues: ['database:emails'], queues_complete: false},
            {key: 'newest', count: '2', exact: false, count_capped: false, last_failed_at: NOW - 30, first_failed_at: NOW - 60, exception_class: 'E', job: 'J', message: '', queues: []},
        ],
    };
    const markers = [{at: NOW - 760, kind: 'deploy', label: 'v2'}];

    const items = attentionItems([queueRow('q')], groupsData, markers, NOW);

    // The active group (last 5 min) ranks above the backed-up queue, the fading one (10 min ago) below it.
    assert.deepEqual(items.map(item => item.key), ['group:newest', 'queue:database:q', 'group:recent']);
    assert.equal(items[2].title, 'at least 4 failed with Boom, first in the last hour at 08:48 UTC');
    assert.equal(items[2].detail, 'down · Sync · emails and more · last 10 min ago');
    // A lower-bound count says ≥: the bulk run then freezes the whole group.
    assert.equal(items[2].retryLabel, 'Retry ≥ 4…');
    assert.equal(items[2].marker.label, 'v2');
    assert.equal(items[0].title, 'at least 2 failed with E, first in the last hour at 08:59 UTC or earlier');
    assert.equal(items[0].detail, 'J · — · last 30 s ago');
    assert.equal(items[0].marker, null);

    // The list's `truncated` alone (groups may be missing) does not qualify a group's count.
    const exact = attentionItems([], {truncated: true, groups: [{key: 'k', count: 3, exact: true, count_capped: false, last_failed_at: NOW - 10, first_failed_at: NOW - 70, exception_class: 'E', queues: []}]}, [], NOW);
    assert.equal(exact[0].title, '3 failed with E, first in the last hour at 08:58 UTC');
    assert.equal(exact[0].retryLabel, 'Retry 3…');
});

function failureGroup(key, extra = {}) {
    return {key, count: 5, exact: true, count_capped: false, last_failed_at: NOW - 60, first_failed_at: NOW - 600, exception_class: 'E', job: 'J', queues: [], ...extra};
}

// Rejects: the old order (every queue before every group, largest wait first), a stalled queue ranked by
// its wait against a backed-up queue, quiet workers counted as draining, and fading groups above the
// queues. It would still pass another wording of the stalled title.
test('attentionItems ranks stalled queues, active groups, backed-up queues, then fading groups', () => {
    const items = attentionItems([
        queueRow('backed', {oldest_age: 6000, threshold: 60, processes: 2}),
        queueRow('orphan', {oldest_age: 120, threshold: 60, processes: 0}),
        queueRow('quiet', {oldest_age: 90, threshold: 60, processes: 2, quiet_processes: 2}),
        queueRow('half quiet', {oldest_age: 90, threshold: 60, processes: 2, quiet_processes: 1}),
    ], {groups: [failureGroup('fading', {last_failed_at: NOW - 600}), failureGroup('active')]}, [], NOW);

    assert.deepEqual(items.map(item => item.key), [
        'queue:database:orphan', 'queue:database:quiet', 'group:active',
        'queue:database:backed', 'queue:database:half quiet', 'group:fading',
    ]);
    assert.equal(items[0].title, 'orphan has no worker and has waited 2 min, over its 60 s limit');
    assert.equal(items[1].title, 'quiet has only quiet workers and has waited 1 min 30 s, over its 60 s limit');
    assert.equal(items[3].title, 'backed has waited 1 h 40 min, over its 60 s limit');
});

// Rejects: backed-up queues ordered by the wait alone (not by how far over the limit), a paused
// stalled queue in the list, and an unknown worker count ranked as stalled or dropped. It would
// still pass any detail word order that ends with "workers unknown".
test('attentionItems orders backed-up queues by wait over limit, and an unknown worker count is not stalled', () => {
    const items = attentionItems([
        queueRow('long limit', {oldest_age: 900, threshold: 600, processes: 1}),
        queueRow('short limit', {oldest_age: 300, threshold: 60, processes: 1}),
        queueRow('unknown', {oldest_age: 100, threshold: 60, processes: null}),
        queueRow('paused orphan', {processes: 0, pauses: [cause('queue')]}),
    ], null, [], NOW);

    assert.deepEqual(items.map(item => item.key), ['queue:database:short limit', 'queue:database:unknown', 'queue:database:long limit']);
    assert.match(items[1].detail, /workers unknown$/);
    assert.equal(items[1].title, 'unknown has waited 1 min 40 s, over its 60 s limit');
});

// Rejects: "has no worker" (tier 1) for a queue of an uninstrumented connection, where a busy
// worker drops off the list (no lease names it), and a detail without the reason. It would still
// pass another wording before "(uninstrumented)".
test('attentionItems never calls a queue of an uninstrumented connection stalled', () => {
    const items = attentionItems([
        queueRow('foreign', {processes: 0, instrumented: false}),
        queueRow('own', {processes: 0, instrumented: true}),
    ], null, [], NOW);

    assert.deepEqual(items.map(item => [item.key, item.tier]), [['queue:database:own', 1], ['queue:database:foreign', 3]]);
    assert.match(items[1].detail, /workers unknown \(uninstrumented\)$/);
    assert.equal(items[1].title, 'foreign has waited 1 min 30 s, over its 60 s limit');
});

// Rejects: a rule `quiet + unknown === workers` → stalled (a worker of unknown state can be busy),
// and a detail that says only "on 2 workers" without the unknown and quiet counts.
test('attentionItems keeps queues with workers of unknown state in tier 3 and names them', () => {
    const items = attentionItems([
        queueRow('all unknown', {processes: 2, quiet_processes: 0, unknown_state_processes: 2}),
        queueRow('mixed', {processes: 2, quiet_processes: 1, unknown_state_processes: 1}),
        queueRow('one unknown', {processes: 1, quiet_processes: 0, unknown_state_processes: 1}),
    ], null, [], NOW);

    assert.deepEqual(items.map(item => item.tier), [3, 3, 3]);
    const byKey = Object.fromEntries(items.map(item => [item.key, item.detail]));
    assert.equal(byKey['queue:database:all unknown'], '120 ready on 2 workers · 2 workers of unknown state');
    assert.equal(byKey['queue:database:mixed'], '120 ready on 2 workers · 1 quiet, 1 of unknown state');
    assert.equal(byKey['queue:database:one unknown'], '120 ready on 1 worker · 1 worker of unknown state');
});

// Rejects: an at-least count compared above a larger exact count, an unknown count ranked first,
// fading groups ordered by count, and ties that follow the input order (cards that jump between polls).
test('attentionItems orders groups by count then last failure, and breaks ties by key', () => {
    const groups = [
        failureGroup('b-tie', {count: 7}),
        failureGroup('unknown', {count: null, last_failed_at: NOW - 5}),
        failureGroup('at-least', {count: 8, exact: false, count_capped: true, last_failed_at: NOW - 200}),
        failureGroup('exact', {count: 9, last_failed_at: NOW - 250}),
        failureGroup('a-tie', {count: 7}),
        failureGroup('fade-old', {count: 99, last_failed_at: NOW - 800}),
        failureGroup('fade-new', {count: 1, last_failed_at: NOW - 400}),
    ];
    const keys = data => attentionItems([], {groups: data}, [], NOW).map(item => item.key);

    assert.deepEqual(keys(groups), ['group:exact', 'group:at-least', 'group:a-tie', 'group:b-tie', 'group:unknown', 'group:fade-new', 'group:fade-old']);
    assert.deepEqual(keys([...groups].reverse()), keys(groups));
    assert.deepEqual(
        attentionItems([queueRow('b'), queueRow('a')], null, [], NOW).map(item => item.key),
        ['queue:database:a', 'queue:database:b'],
    );
});

// Rejects: exactly one item hidden behind a button, a split at another size, and a list cut short.
test('attentionSplit shows 3 items and hides the rest, but never hides exactly one', () => {
    const list = count => Array.from({length: count}, (_, index) => ({key: `k${index}`, type: 'queue'}));

    assert.deepEqual(attentionSplit(list(4)).visible.length, 4);
    assert.deepEqual(attentionSplit(list(4)).hidden, []);
    assert.deepEqual(attentionSplit(list(3)).hidden, []);
    assert.deepEqual(attentionSplit(list(5)).visible.map(item => item.key), ['k0', 'k1', 'k2']);
    assert.deepEqual(attentionSplit(list(5)).hidden.map(item => item.key), ['k3', 'k4']);
    assert.deepEqual(attentionSplit(null), {visible: [], hidden: []});
});

// Rejects: a summary without the counts by type, a wrong singular or plural, and a zero part named.
test('attentionSummary counts the hidden items by type', () => {
    const items = (queues, groups) => [
        ...Array.from({length: queues}, () => ({type: 'queue'})),
        ...Array.from({length: groups}, () => ({type: 'group'})),
    ];

    assert.equal(attentionSummary(items(9, 4)), 'Show 13 more · 9 queues over limit, 4 failure groups');
    assert.equal(attentionSummary(items(1, 1)), 'Show 2 more · 1 queue over limit, 1 failure group');
    assert.equal(attentionSummary(items(0, 2)), 'Show 2 more · 2 failure groups');
    assert.equal(attentionSummary(items(2, 0)), 'Show 2 more · 2 queues over limit');
});

// Rejects: a summary that leaves out the failure groups without a notice of their own (they show
// only after expanding), and an exact total while those groups are an at-least count.
test('attentionSummary counts the groups without a notice of their own', () => {
    const items = (queues, groups) => [
        ...Array.from({length: queues}, () => ({type: 'queue'})),
        ...Array.from({length: groups}, () => ({type: 'group'})),
    ];

    assert.equal(attentionSummary(items(2, 1), {count: 4, atLeast: false}), 'Show 7 more · 2 queues over limit, 5 failure groups');
    assert.equal(attentionSummary(items(2, 0), {count: 1, atLeast: true}), 'Show at least 3 more · 2 queues over limit, at least 1 failure group');
    assert.equal(attentionSummary(items(2, 0), null), 'Show 2 more · 2 queues over limit');
});

// Rejects: a storage that throws into the page, and a stored value other than "1" read as expanded.
test('readAttentionExpanded and writeAttentionExpanded keep the state and survive a throwing storage', () => {
    const values = new Map();
    const storage = {getItem: key => (values.has(key) ? values.get(key) : null), setItem: (key, value) => values.set(key, String(value)), removeItem: key => values.delete(key)};
    const throwing = {getItem() { throw new Error('x'); }, setItem() { throw new Error('x'); }, removeItem() { throw new Error('x'); }};

    assert.equal(readAttentionExpanded(storage), false);
    assert.equal(writeAttentionExpanded(true, storage), true);
    assert.equal(readAttentionExpanded(storage), true);
    writeAttentionExpanded(false, storage);
    assert.equal(readAttentionExpanded(storage), false);
    values.set([...values.keys()][0] || 'orreryAttentionExpanded', 'yes');
    assert.equal(readAttentionExpanded(storage), false);
    assert.equal(readAttentionExpanded(throwing), false);
    assert.equal(writeAttentionExpanded(true, throwing), false);
    assert.equal(readAttentionExpanded(null), false);
});

// Rejects: a throw on missing data, and a group without a last failure that needs attention.
test('attentionItems is empty without data', () => {
    assert.deepEqual(attentionItems(null, null, null, NOW), []);
    assert.deepEqual(attentionItems([], {groups: [{key: 'x', last_failed_at: null}]}, [], NOW), []);
});

// Rejects: a host counted once per worker, and "1 workers".
test('queuesSubtitle counts the workers and hosts', () => {
    assert.equal(queuesSubtitle(null), 'Oldest wait against each queue’s limit');
    assert.equal(queuesSubtitle([{host: 'a'}]), 'Oldest wait against each queue’s limit · 1 worker on 1 host');
    assert.equal(queuesSubtitle([{host: 'a'}, {host: 'b'}, {host: 'a'}, {}]), 'Oldest wait against each queue’s limit · 4 workers on 2 hosts');
});

// Rejects: the horizon of another series, and 0 for a missing horizon.
test('retentionOf reads one series horizon', () => {
    assert.equal(retentionOf({retention_from: {completed: '100', failed: 200}}, 'completed'), 100);
    assert.equal(retentionOf({retention_from: {completed: 100}}, 'failed'), null);
    assert.equal(retentionOf(null, 'completed'), null);
});

// Rejects: a null value turned into 0, and a bucket without a start that is kept.
test('throughputBuckets keeps unknown values as null', () => {
    assert.deepEqual(throughputBuckets({buckets: [
        {at: '60', completed: '5', failed: null, partial: true},
        {at: null, completed: 1, failed: 1},
        {at: 120, completed: 0, failed: 2},
    ]}), [
        {at: 60, completed: 5, failed: null, partial: true},
        {at: 120, completed: 0, failed: 2, partial: false},
    ]);
    assert.deepEqual(throughputBuckets(null), []);
});

// Rejects: a total that treats a missing slot or a slot before the horizon as zero without "at least".
test('completedInWindow is "at least" when a slot is unknown', () => {
    const complete = {from: 0, to: 180, bucket_seconds: 60, buckets: [{at: 0, completed: 1}, {at: 60, completed: 2}, {at: 120, completed: 0}]};

    assert.deepEqual(completedInWindow(complete), {count: 3, atLeast: false});
    assert.deepEqual(completedInWindow({...complete, buckets: complete.buckets.slice(0, 2)}), {count: 3, atLeast: true});
    assert.deepEqual(completedInWindow({...complete, retention_from: {completed: 60}}), {count: 2, atLeast: true});
    assert.equal(completedInWindow({...complete, from: null}), null);
    assert.equal(completedInWindow(null), null);
});

// Rejects: the exact value, and "~NaN" for an unknown value.
test('approximately keeps two significant digits', () => {
    assert.equal(approximately(612), '~610');
    assert.equal(approximately(1288), '~1,300');
    assert.equal(approximately(7), '~7');
    assert.equal(approximately(0), '~0');
    assert.equal(approximately(null), '~0');
});

function minutes(values, failed = []) {
    return values.map((completed, index) => ({at: index * 60, completed, failed: failed[index] ?? 0, partial: false}));
}

// Rejects: a peak at or below 1.5 × the median, a partial bucket as the peak, and a burst shorter than 3 minutes.
test('throughputAnnotations marks the peak, the dip and the failure burst', () => {
    const buckets = minutes([10, 10, 30, 10, 2, 3, 10, 10], [0, 2, 3, 2, 0, 2, 2, 0]);
    buckets.push({at: 480, completed: 999, failed: 9, partial: true});

    const annotations = throughputAnnotations(buckets, 60);

    assert.deepEqual(annotations.map(note => note.kind), ['peak', 'dip', 'burst']);
    assert.equal(annotations[0].at, 120);
    assert.equal(annotations[0].text, 'Peak 30/min');
    assert.deepEqual([annotations[1].at, annotations[1].to, annotations[1].text], [240, 300, 'Dip to ~2/min']);
    assert.deepEqual([annotations[2].at, annotations[2].to], [60, 180]);
    assert.equal(annotations[2].text, '7 failures 00:01–00:03 UTC');
    assert.equal(annotations[2].target, 'band');
});

// Rejects: a peak or dip on a flat series, and a run across an unknown bucket.
test('throughputAnnotations stays quiet for a flat or short series', () => {
    assert.deepEqual(throughputAnnotations(minutes([10, 10, 10, 10]), 60), []);
    assert.deepEqual(throughputAnnotations(minutes([1, 50]), 60), []);
    assert.deepEqual(throughputAnnotations(minutes([10, null, 10, 10, 10]), 60), []);
});

// Rejects: a missing part written as 0, and a truncated scan without "at least".
test('groupsSubtitle joins the known parts', () => {
    assert.equal(
        groupsSubtitle({scanned: '41', truncated: true, as_of: NOW, retention_from: NOW - 86400}, {count: 312, at_least: false}),
        'at least 41 in the past hour · 312 still failed · last 1 day',
    );
    assert.equal(groupsSubtitle({scanned: 5}, undefined), '5 in the past hour');
    // A failed counts read is unknown, never 0.
    assert.equal(groupsSubtitle({scanned: 5}, null), '5 in the past hour · — still failed');
    assert.equal(groupsSubtitle({scanned: 5}, {count: 1000000, at_least: true}), '5 in the past hour · at least 1,000,000 still failed');
    assert.equal(groupsSubtitle(null, undefined), '');
});

// Rejects: a selection with only the primary connection of a merged row.
test('queueControlSelection and globalControlSelection freeze the target', () => {
    const frozen = queueControlSelection('pause', {name: 'emails', connection: 'a', connections: ['a', 'b']});
    assert.deepEqual({...frozen, pause: null}, {type: 'control', action: 'pause', scope: 'queue', queue: 'emails', connections: ['a', 'b'], pause: null, cacheNote: null});
    assert.equal(frozen.pause.knownOff, false, 'a row without causes is unknown');
    assert.equal(queueControlSelection('pause', {name: 'emails', connection: 'a', ...known}).pause.knownOff, true);
    assert.equal(queueControlSelection('continue', {name: 'emails', connection: 'a', ...known, pauses: [cause('queue', 'queue:pause')]}).pause.paused, true);
    assert.deepEqual(globalControlSelection('terminate'), {type: 'control', action: 'terminate', scope: 'global', queue: null, connections: []});
});

// Rejects: one request for a merged row (an alias would stay paused), and a queue request without its connection.
test('controlRequests sends one queue request per alias', () => {
    const selection = queueControlSelection('pause', {name: 'emails', connection: 'a', connections: ['a', 'b']});

    assert.deepEqual(controlRequests(selection), [
        {action: 'pause', scope: 'queue', connection: 'a', queue: 'emails'},
        {action: 'pause', scope: 'queue', connection: 'b', queue: 'emails'},
    ]);
});

// Rejects: a global request with a connection or a queue, which the server would read as narrower.
test('controlRequests sends one global request', () => {
    assert.deepEqual(controlRequests(globalControlSelection('continue')), [{action: 'continue', scope: 'global'}]);
    assert.deepEqual(controlRequests({...globalControlSelection('pause'), connections: ['x'], queue: 'q'}), [{action: 'pause', scope: 'global'}]);
});

// Rejects: a request with an undefined connection.
test('controlRequests of a queue without aliases sends nothing', () => {
    assert.deepEqual(controlRequests(queueControlSelection('continue', {name: 'emails'})), []);
});

// Rejects: a global continue that hides that it removes every pause, and a message without the aliases.
test('controlSheet words each action', () => {
    assert.equal(controlSheet(globalControlSelection('terminate')).tone, 'danger');
    assert.equal(controlSheet(globalControlSelection('continue')).confirmLabel, 'Continue all queues');
    assert.equal(controlSheet(globalControlSelection('pause')).title, 'Pause all queues…');

    const one = queueControlSelection('pause', {name: 'emails', connection: 'a'});
    assert.equal(controlSheet(one, 'emails').message, 'Workers finish their current job and take no new jobs from emails until you continue.');

    // A queue pause names the health hint that it may not reach every worker, as the Queues screen does.
    const note = 'A queue pause is kept in the file cache store: workers on other servers do not see it.';
    const local = queueControlSelection('pause', {name: 'emails', connection: 'a'}, note);
    assert.equal(controlSheet(local, 'emails').message, `Workers finish their current job and take no new jobs from emails until you continue. ${note}`);
    assert.equal(queueControlSelection('pause', {name: 'emails', connection: 'a'}, '').cacheNote, null);

    const two = queueControlSelection('continue', {name: 'emails', connections: ['a', 'b'], ...known, pauses: [cause('queue')]});
    assert.deepEqual(controlSheet(two, 'emails'), {
        title: 'Continue emails…',
        message: 'Workers take jobs from emails on a, b again.',
        confirmLabel: 'Continue emails',
        tone: 'primary',
    });
});

// Rejects: a Continue sheet that promises the queue resumes while a broader pause still holds it.
test('controlSheet of a queue Continue names the pauses that stay', () => {
    const selection = queueControlSelection('continue', {name: 'emails', connection: 'database', ...known, pauses: [cause('global', 'queue:pause --all'), cause('queue')], queue_cause_unknown: true});

    assert.equal(controlSheet(selection, 'emails').message, "Lifts the queue pause of emails. It stays paused by Laravel's global pause (queue:continue --all).");

    const global = queueControlSelection('continue', {name: 'emails', connection: 'database', ...known, pauses: [cause('global'), cause('queue')]});
    assert.equal(controlSheet(global, 'emails').message, 'Lifts the queue pause of emails. It stays paused by the pause of all queues.');
    assert.match(controlSheet(globalControlSelection('continue')).message, /Pauses of connections and of single queues stay/);
});

// Rejects: a queue pause or a global continue taken as a global pause.
test('hasGlobalPause finds only a global pause', () => {
    assert.equal(hasGlobalPause([{signal: 'pause', scope: 'queue'}, {signal: 'pause', scope: 'global'}]), true);
    assert.equal(hasGlobalPause([{signal: 'continue', scope: 'global'}, null]), false);
    assert.equal(hasGlobalPause(null), false);
});

function health(tone, title) {
    return {tone, title};
}

const HEALTHY = health('healthy', 'Watcher healthy');

// Rejects: "Everything is healthy" while the footer says "Tick is late" (the old subtitle ignored the watcher).
test('attentionSubtitle never says healthy with a late tick', () => {
    const late = health('warning', 'Tick is late');
    const none = {count: 0, atLeast: false, possible: 0, unknown: []};
    const two = {count: 2, atLeast: false, possible: 0, unknown: []};

    assert.equal(attentionSubtitle(none, late), 'Tick is late');
    assert.equal(attentionSubtitle(two, late), '2 need attention · tick is late');
    assert.doesNotMatch(attentionSubtitle(two, late), /healthy/);
    assert.equal(attentionSubtitle(none, health('warning', 'Watcher degraded')), 'Watcher degraded');
    assert.equal(attentionSubtitle(none, health('warning', 'Retries refused on redis2: fence table missing')), 'Retries refused on redis2: fence table missing');
    assert.equal(attentionSubtitle(none, health('unknown', 'Watcher health unknown')), 'Watcher health unknown');
    // No health yet is unknown, never healthy.
    assert.equal(attentionSubtitle(none, null), 'Watcher health unknown');
    assert.equal(attentionSubtitle(two, null), '2 need attention · watcher health unknown');
});

// Rejects: "healthy" next to an unknown part, a definite count that hides the possible items, and "at least 0".
test('attentionSubtitle names the possible and unknown parts', () => {
    assert.equal(attentionSubtitle({count: 0, atLeast: false, possible: 0, unknown: []}, HEALTHY), 'Everything is healthy');
    assert.equal(attentionSubtitle({count: 1, atLeast: false, possible: 0, unknown: []}, HEALTHY), '1 needs attention · the rest is healthy');
    assert.equal(attentionSubtitle({count: 2, atLeast: true, possible: 0, unknown: []}, HEALTHY), 'At least 2 need attention');
    assert.equal(
        attentionSubtitle({count: 0, atLeast: true, possible: 1, unknown: ['pause state']}, HEALTHY),
        '1 may need attention · pause state unknown',
    );
    assert.equal(
        attentionSubtitle({count: 2, atLeast: true, possible: 1, unknown: ['pause state']}, health('warning', 'Tick is late')),
        'At least 2 need attention · 1 more may need attention · pause state unknown · tick is late',
    );
    assert.equal(attentionSubtitle({count: 0, atLeast: true, possible: 0, unknown: ['failure groups']}, HEALTHY), 'Failure groups unknown');
    assert.equal(
        attentionSubtitle({count: 1, atLeast: true, possible: 0, unknown: ['failure groups', 'queue state']}, HEALTHY),
        'At least 1 needs attention · failure groups and queue state unknown',
    );
    assert.equal(
        attentionSubtitle({count: 0, atLeast: true, possible: 1, unknown: ['failure groups', 'pause state', 'queue state']}, health('warning', 'Tick is late')),
        '1 may need attention · failure groups, pause state and queue state unknown · tick is late',
    );
    assert.equal(attentionSubtitle(null, HEALTHY), null);
});

// 2026-09-28 09:00:30 UTC: the current minute starts at NOW.
const AS_OF = NOW + 30;

function rateResponse(overrides = {}) {
    const buckets = [];

    for (let at = NOW - 10 * 60; at <= NOW; at += 60) {
        buckets.push({at, completed: 10, failed: 2, partial: at === NOW});
    }

    return {as_of: AS_OF, from: AS_OF - 3600, to: AS_OF, bucket_seconds: 60, buckets, ...overrides};
}

// Rejects: the since-snapshot stats value, and the partial current bucket in the sum.
test('throughputRate sums the five minutes before the current one', () => {
    const data = rateResponse();
    data.buckets.find(bucket => bucket.at === NOW).completed = 9999;
    data.buckets.find(bucket => bucket.at === NOW - 60).completed = 40;

    // (4 × 10 + 40 completed) + 5 × 2 failed = 90 over 5 minutes.
    assert.equal(throughputRate(data), 18);
    assert.equal(throughputRate({...data, buckets: data.buckets.map(bucket => ({...bucket, at: String(bucket.at), completed: String(bucket.completed)}))}), 18);
    assert.equal(throughputRate(null), null);
});

// Rejects: taking an older complete bucket in place of a missing one.
test('throughputRate is unknown with a hole between present buckets', () => {
    const data = rateResponse();
    data.buckets = data.buckets.filter(bucket => bucket.at !== NOW - 180);

    assert.equal(throughputRate(data), null);
});

// Rejects: null read as 0.
test('throughputRate is unknown with a null series value', () => {
    const completed = rateResponse();
    completed.buckets.find(bucket => bucket.at === NOW - 300).completed = null;
    assert.equal(throughputRate(completed), null);

    const failed = rateResponse();
    failed.buckets.find(bucket => bucket.at === NOW - 120).failed = null;
    assert.equal(throughputRate(failed), null);
});

test('throughputRate is unknown when the retention boundary makes a bucket partial', () => {
    const data = rateResponse();
    data.buckets.find(bucket => bucket.at === NOW - 300).partial = true;

    assert.equal(throughputRate(data), null);
    // Without as_of the five minutes cannot be anchored.
    assert.equal(throughputRate({...rateResponse(), as_of: null}), null);
});

// Rejects: the stats rate, a rate rounded to a whole number below 10, and an unknown rate written as 0.
test('throughputSubtitle shows the rate of the five minutes and the past hour', () => {
    const data = rateResponse({buckets: rateResponse().buckets.map(bucket => ({...bucket, completed: 1, failed: 0}))});

    assert.equal(throughputSubtitle(data).split(' · ')[0], '1/min');
    data.buckets[data.buckets.length - 2].completed = 3;
    assert.equal(throughputSubtitle(data).split(' · ')[0], '1.4/min');
    assert.equal(throughputSubtitle(rateResponse()), '12/min · last 5 min · at least 110 completed in the past hour');

    const hole = rateResponse();
    hole.buckets = hole.buckets.filter(bucket => bucket.at !== NOW - 60);
    assert.equal(throughputSubtitle(hole).split(' · ')[0], '—/min');
    assert.equal(throughputSubtitle(null), '—/min · last 5 min');
    // Nit: a fresh install (every minute unknown) never reads "at least 0 completed".
    const unknown = rateResponse();
    unknown.buckets = [];
    assert.equal(throughputSubtitle(unknown), '—/min · last 5 min · completed in the past hour unknown');
});

// Rejects: a rate rounded to 0 below 1/min, a fraction kept above 10/min, and 0 for an unknown rate.
test('rateText rounds the rate and shows unknown as a dash', () => {
    assert.equal(rateText(1284.4), '1,284');
    assert.equal(rateText(0.24), '0.2');
    assert.equal(rateText(0), '0');
    assert.equal(rateText(null), '—');
    assert.equal(rateText(Number.NaN), '—');
});

// Rejects: "Everything is healthy" or "the rest is healthy" for a lower-bound count (a full group page may hide more).
test('attentionSubtitle never calls a lower bound healthy', () => {
    const none = attentionSubtitle({count: 0, atLeast: true, possible: 0, unknown: []}, HEALTHY);

    assert.equal(none, 'Attention unknown: more may need attention');
    assert.doesNotMatch(none, /healthy/);
    assert.doesNotMatch(attentionSubtitle({count: 3, atLeast: true, possible: 0, unknown: []}, HEALTHY), /healthy/);
});

function liveState(extra = {}) {
    return {workload: null, workloadError: false, groupsHour: null, groupsHourError: false, ...extra};
}

// Rejects: the Overview's own copy of the attention rule, which counted a stale over-limit queue as "At least 1".
test('overviewAttention follows the shell: a stale over-limit queue is possible, not definite', () => {
    const state = liveState({
        workload: [{name: 'q', connection: 'database', over_limit: true, pauses: [], paused_unknown: false}],
        workloadError: true,
        groupsHour: {groups: []},
    });
    const attention = overviewAttention(state, NOW);

    assert.equal(attention.count, 0);
    assert.equal(attention.possible, 1);
    assert.ok(attention.unknown.includes('queue state'));
    assert.equal(attentionSubtitle(attention, HEALTHY), '1 may need attention · queue state unknown');
});

// Rejects: a definite count from the groups while the queues are still loading, and a count before anything loaded.
test('overviewAttention counts the groups alone only while the workload is not loaded', () => {
    const groupsHour = {groups: [{key: 'k', last_failed_at: NOW - 60}]};

    assert.equal(overviewAttention(liveState(), NOW), null);

    const groupsOnly = overviewAttention(liveState({groupsHour}), NOW);
    assert.equal(groupsOnly.count, 1);
    assert.equal(groupsOnly.atLeast, true);
    assert.ok(groupsOnly.unknown.includes('queue state'));

    const failedGroups = overviewAttention(liveState({groupsHourError: true}), NOW);
    assert.equal(failedGroups.count, 0);
    assert.deepEqual(failedGroups.unknown.sort(), ['failure groups', 'queue state']);
});

// Rejects: a promise that the queue resumes while the pause state is unknown.
test('controlSheet of a Continue with an unknown pause state does not promise the queue resumes', () => {
    const selection = queueControlSelection('continue', {name: 'emails', connection: 'database', ...known, native_unknown: true, pauses: [cause('queue', 'orrery', {possible: true})]});
    const message = controlSheet(selection, 'emails').message;

    assert.equal(message, 'Lifts the pause of emails. Whether another pause also holds emails is unknown, so workers may still not take its jobs.');
    assert.doesNotMatch(message, /again/);
});

// Rejects: a queue command for a pause that Orrery's Continue lifts, and no command for Laravel's global pause.
test('nativeContinueCommands names only what Orrery cannot lift', () => {
    const all = queueControlSelection('continue', {name: 'emails', connections: ['a', 'b'], ...known, pauses: [cause('global', 'queue:pause --all')], queue_cause_unknown: true});

    assert.deepEqual(nativeContinueCommands(all), ['php artisan queue:continue --all']);
    assert.deepEqual(nativeContinueCommands({...all, action: 'pause'}), []);
    assert.deepEqual(nativeContinueCommands(queueControlSelection('continue', {name: 'emails', connection: 'a', ...known, pauses: [cause('queue', 'queue:pause')]})), []);
    assert.deepEqual(nativeContinueCommands(null), []);
});

// Rejects: a failed /api/control read that keeps the last pause state with no stale mark (Pause all then
// shows for a global pause set elsewhere), and a stale text on a read that never succeeded.
test('controlErrorText marks the pause state as stale or unknown', () => {
    assert.equal(controlErrorText([]), 'The pause state could not be refreshed. Pause all and Continue all follow the last state read.');
    assert.equal(controlErrorText(null), 'The pause state could not be loaded. Pause all and Continue all may not match it.');
});

// Rejects: the Overview's Continue notice green whatever the server answers: a queue that another pause still
// holds, or whose pause state is unknown, reads "Continue sent"; and a failed signal without its connection.
// Still passes: a notice whose detail lists the signals in another order than they were sent.
test('controlOutcome words a control write from the answers', () => {
    const queue = {type: 'control', action: 'continue', scope: 'queue', queue: 'emails', label: 'emails', connections: ['database']};
    const body = {action: 'continue', scope: 'queue', connection: 'database', queue: 'emails'};

    assert.deepEqual(controlOutcome(queue, [{body, data: {still_paused_by: [], still_paused_unknown: false}}]),
        {tone: 'success', title: 'Continue sent for emails.', detail: null});
    assert.deepEqual(controlOutcome(queue, [{body, data: {still_paused_by: [{scope: 'connection', value: 'database', source: 'orrery'}]}}]), {
        tone: 'warning',
        title: 'Continue emails: still paused.',
        detail: 'database:emails stays paused by the pause of connection database.',
    });
    assert.equal(controlOutcome(queue, [{body, data: {}}]).title, 'Continue emails: sent; the pause state is unknown.');

    const global = {...globalControlSelection('continue'), label: null};
    assert.equal(controlOutcome(global, [{body: {action: 'continue', scope: 'global'}, data: {still_paused_by: [{scope: 'global', value: '*', source: 'queue:pause --all'}]}}]).detail,
        "all queues stays paused by Laravel's global pause (queue:continue --all).");

    const pause = {...queue, action: 'pause', connections: ['database', 'other']};
    assert.deepEqual(controlOutcome(pause, [{body, data: {}}, {body: {...body, connection: 'other'}, data: {}}]),
        {tone: 'success', title: 'Pause sent for emails.', detail: 'One signal on each of database, other.'});
    assert.deepEqual(controlOutcome(pause, [{body, data: {}}, {body: {...body, connection: 'other'}, failed: true, text: 'HTTP 500'}]),
        {tone: 'danger', title: 'Pause emails: 1 of 2 signals failed.', detail: 'other: HTTP 500'});
    assert.deepEqual(controlOutcome(globalControlSelection('terminate'), [{body: {action: 'terminate', scope: 'global'}, failed: true, text: 'no response'}]),
        {tone: 'danger', title: 'Terminate all workers failed.', detail: 'no response'});
});

// Rejects: a 503 `record_failed` (the queue is paused; only Orrery's record failed) shown as a failed pause in the
// danger tone, and a mixed answer that drops the record's note.
// Still passes: any title that keeps the word "done" and the warning tone.
test('controlOutcome words a pause whose Orrery record failed as a warning', () => {
    const pause = {type: 'control', action: 'pause', scope: 'queue', queue: 'emails', label: 'emails', connections: ['database']};
    const body = {action: 'pause', scope: 'queue', connection: 'database', queue: 'emails'};
    const recorded = {body, failed: true, status: 503, text: 'HTTP 503', data: {error: 'record_failed', paused: true}};
    const outcome = controlOutcome(pause, [recorded]);

    assert.equal(outcome.tone, 'warning');
    assert.equal(outcome.title, "Pause emails: done; Orrery's record of it was not saved.");
    assert.match(outcome.detail, /^database:emails is paused\. /);

    const mixed = controlOutcome({...pause, connections: ['database', 'other']},
        [recorded, {body: {...body, connection: 'other'}, failed: true, status: 500, text: 'HTTP 500'}]);
    assert.equal(mixed.tone, 'danger');
    assert.equal(mixed.title, 'Pause emails: 1 of 2 signals failed.');
    assert.match(mixed.detail, /^other: HTTP 500 · database:emails is paused\. /);
});
