// Tests of resources/js/screens/jobs/jobs.js: the rules of the Jobs list
// screen.
// `pnpm test` runs them with TZ=UTC and LANG=en_US.UTF-8.

import {test} from 'node:test';
import assert from 'node:assert/strict';

import {
    STUCK_SIGNALS,
    attemptsText,
    availableText,
    bulkSelectionKey,
    bulkState,
    columnsOf,
    compactLine,
    emptyState,
    enforcedRuleText,
    filterFields,
    jobLink,
    lastVerifiedParts,
    leaseCell,
    filterFailure,
    listFailure,
    markedText,
    operationCell,
    listOrder,
    clearedFiltersQuery,
    mergeBarChange,
    mismatchAction,
    autoLoadApplies,
    pageAfter,
    pastTheEnd,
    pagerRequestFor,
    pagerShown,
    pollAction,
    shownTotal,
    stuckBarFilters,
    stuckTime,
    requestFor,
    resultDetail,
    restartPlan,
    resultTitle,
    retriedText,
    runtimeText,
    scannedToText,
    shortId,
    shortName,
    signalChip,
    signalFromQuery,
    signalTabs,
    signalText,
    tagChips,
    timeCell,
    timeColumn,
    timeHeader,
    workerCell,
} from '../../resources/js/screens/jobs/jobs.js';
import {filtersFromQuery} from '../../resources/js/support/lists.js';

const UUID = '0b6f1c9e-3b8a-4d2f-9a51-7c2e4b1d9f00';
const NOW = Date.UTC(2026, 10, 8, 9, 32, 7) / 1000;

function filters(overrides = {}) {
    return {status: 'failed', connection: null, queue: null, class: null, tag: null, batch: null, from: null, to: null, q: null, order: 'desc', ...overrides};
}

function base64url(text) {
    return Buffer.from(text, 'utf8').toString('base64').replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
}

// Rejects: an unknown or missing signal that reaches the request (the server answers 422), and a list of signals in another order than the sub-tabs.
test('signalFromQuery keeps a known signal, else the first one', () => {
    assert.deepEqual(STUCK_SIGNALS, ['reservation_expired', 'outcome_not_observed', 'repeated_releases', 'attempts_not_capped']);
    assert.equal(signalFromQuery({signal: 'repeated_releases'}), 'repeated_releases');
    assert.equal(signalFromQuery({signal: 'crashed'}), 'reservation_expired');
    assert.equal(signalFromQuery({signal: ['repeated_releases']}), 'reservation_expired');
    assert.equal(signalFromQuery(null), 'reservation_expired');
});

// Rejects: a count on a sub-tab that is not open (the API has no per-signal count), a 0 for an unknown total, and a lost "+" of a capped one.
test('signalTabs shows the list total on the open sub-tab only', () => {
    const tabs = signalTabs('outcome_not_observed', {count: 10000, at_least: true});

    assert.deepEqual(tabs.map(tab => tab.label), ['Reservation expired', 'Outcome not observed', 'Repeated releases', 'Attempts not capped']);
    assert.deepEqual(tabs.map(tab => tab.count), [null, '10,000+', null, null]);
    assert.deepEqual(tabs.map(tab => tab.active), [false, true, false, false]);
    assert.equal(signalTabs('reservation_expired', {count: null, at_least: false})[0].count, '—');
    assert.equal(signalTabs('reservation_expired', null)[0].count, null);
});

// Rejects: a tab without its own column set (failed rows without the exception, running rows without the worker and lease, stuck rows without the signals) and a time column that is not the tab's order column.
test('columnsOf and timeColumn give each tab its columns and order column', () => {
    assert.deepEqual(columnsOf('failed'), ['job', 'exception', 'queue', 'attempts', 'runtime', 'time']);
    assert.deepEqual(columnsOf('pending'), ['job', 'queue', 'attempts', 'available', 'time']);
    assert.deepEqual(columnsOf('running'), ['job', 'queue', 'worker', 'lease', 'attempt', 'time']);
    assert.deepEqual(columnsOf('stuck'), ['job', 'queue', 'signals', 'rule', 'time']);
    assert.deepEqual(columnsOf('retrying'), ['job', 'operation', 'time']);

    for (const status of ['completed', 'silenced', 'unknown']) {
        assert.deepEqual(columnsOf(status), ['job', 'attempts', 'runtime', 'time'], status);
    }

    assert.equal(timeColumn('pending'), 'pushed_at');
    assert.equal(timeColumn('running'), 'reserved_at');
    assert.equal(timeColumn('completed'), 'finished_at');
    assert.equal(timeColumn('silenced'), 'finished_at');
    assert.equal(timeColumn('unknown'), 'finished_at');
    assert.equal(timeColumn('failed'), 'failed_at');
    assert.equal(timeColumn('retrying'), 'failed_at');
    assert.equal(timeColumn('stuck'), 'reserved_at');
});

// Rejects: an order arrow that does not follow the display order, and a header that names another column than the tab's order column.
test('timeHeader names the order column with the order arrow', () => {
    assert.equal(timeHeader('failed', 'desc'), 'Failed at ▾');
    assert.equal(timeHeader('pending', 'asc'), 'Pushed at ▴');
    assert.equal(timeHeader('running', 'desc'), 'Reserved at ▾');
    assert.equal(timeHeader('completed', 'desc'), 'Finished at ▾');
});

// Rejects: search, tag and time filters offered on the stuck tab (its endpoint takes only the queue and the class), and a tab without the search.
test('filterFields gives the stuck tab only the queue and the class', () => {
    assert.deepEqual(filterFields('stuck'), ['queue', 'class']);
    assert.deepEqual(filterFields('failed'), ['q', 'queue', 'class', 'tag', 'range']);
});

// Rejects: newer_than on a later page, in the order asc or on the stuck tab (the server answers has_newer only for the first page in the order desc), a cursor in the wrong order, and a stuck request without its signal.
test('requestFor builds the request of a page', () => {
    const head = 'head-cursor';

    assert.deepEqual(requestFor(filters({queue: 'emails', connection: 'db'}), {order: 'desc', cursor: null}, {perPage: 50, head}), {
        endpoint: '/api/jobs',
        params: {status: 'failed', connection: 'db', queue: 'emails', newer_than: head, per_page: 50},
    });

    assert.deepEqual(requestFor(filters(), {order: 'desc', cursor: 'c1'}, {perPage: 25, head}), {
        endpoint: '/api/jobs',
        params: {status: 'failed', cursor: 'c1', per_page: 25},
    });

    assert.deepEqual(requestFor(filters(), {order: 'asc', cursor: null}, {perPage: 50, head}), {
        endpoint: '/api/jobs',
        params: {status: 'failed', order: 'asc', per_page: 50},
    });

    assert.deepEqual(requestFor(filters({status: 'stuck', tag: 'x', connection: 'db'}), {order: 'desc', cursor: null}, {perPage: 50, head, signal: 'repeated_releases'}), {
        endpoint: '/api/jobs/stuck',
        params: {signal: 'repeated_releases', connection: 'db', per_page: 50},
    });

    assert.deepEqual(requestFor(filters(), {order: 'desc', cursor: null}, {perPage: 50, head: null}).params, {status: 'failed', per_page: 50});
});

// Rejects: a poll that replaces the rows under the reader while new entries wait (or may wait) behind the banner, a "maybe" banner when no newer_than was sent, and a reload that ignores the auto-load toggle.
test('pollAction keeps the rows behind the banner while has_newer is true or unknown', () => {
    const sent = {sentNewerThan: true};

    assert.equal(pollAction({has_newer: true}, false, sent), 'banner');
    assert.equal(pollAction({has_newer: true}, true, sent), 'apply');
    assert.equal(pollAction({has_newer: false}, false, sent), 'apply');
    // Unknown (the seek spent its budget): never applied silently; auto-load reloads.
    assert.equal(pollAction({has_newer: null}, false, sent), 'maybe');
    assert.equal(pollAction({has_newer: null}, true, sent), 'apply');
    // Without newer_than (a later page, asc, stuck) has_newer is always null: apply.
    assert.equal(pollAction({has_newer: null}, false, {sentNewerThan: false}), 'apply');
    assert.equal(pollAction(null, false, sent), 'apply');
    assert.equal(pollAction({has_newer: true}, false, {sentNewerThan: true, status: 'failed'}), 'banner');
    assert.equal(pollAction({has_newer: true}, false, {sentNewerThan: true, status: 'completed'}), 'banner');
});

// Rejects: the first page of a state tab frozen behind "New entries" on a busy queue while its lease and heartbeat
// cells count on against the live clock (finished jobs shown as "Lease expired"): those rows are a current state, so
// a poll replaces them.
test('pollAction applies a state tab poll also while newer entries wait', () => {
    for (const status of ['pending', 'running', 'retrying']) {
        assert.equal(pollAction({has_newer: true}, false, {sentNewerThan: true, status}), 'apply', status);
        assert.equal(pollAction({has_newer: null}, false, {sentNewerThan: true, status}), 'apply', status);
    }
});

// Rejects: a 0 for an unknown total, a lost "at least", the plural of one job, and the same noun on every tab.
test('resultTitle gives the filtered total with the tab noun', () => {
    assert.equal(resultTitle('failed', {count: 86, at_least: false}), '86 failed jobs');
    assert.equal(resultTitle('failed', {count: 1, at_least: false}), '1 failed job');
    assert.equal(resultTitle('completed', {count: 10000, at_least: true}), 'At least 10,000 completed jobs');
    assert.equal(resultTitle('pending', {count: 0, at_least: false}), '0 pending jobs');
    assert.equal(resultTitle('running', {count: null, at_least: false}), 'Running jobs · total unknown');
    assert.equal(resultTitle('retrying', {count: 2, at_least: false}), '2 jobs being retried');
    assert.equal(resultTitle('unknown', {count: 3, at_least: false}), '3 jobs with an unknown outcome');
    assert.equal(resultTitle('stuck', null), 'Stuck jobs · total unknown');
});

// Rejects: a detail that hides an active filter, that names the wrong order, and a stuck detail that names filters its endpoint ignores.
test('resultDetail says which filters match and the order', () => {
    const range = {key: '24h', from: NOW - 86400};

    assert.equal(resultDetail(filters({connection: 'database', queue: 'emails', from: NOW - 86400}), range), 'match database:emails · the last 24 h · newest failure first');
    assert.equal(resultDetail(filters(), null), 'newest failure first');
    assert.equal(resultDetail(filters({status: 'pending', order: 'asc'}), null), 'oldest push first');
    assert.equal(resultDetail(filters({q: 'mail'}), null), 'match the search “mail” · newest failure first');
    assert.equal(resultDetail(filters({status: 'stuck', tag: 'x', class: 'App\\Jobs\\A'}), null, 'repeated_releases'), 'Repeated releases · match class App\\Jobs\\A');
});

// Rejects: a "searched back to" line from a cursor that cannot be read, one that drops the time of a readable one, "back to" in the order asc, and a time read from the count key of a stuck signal.
test('scannedToText reads the time of the last examined row when the cursor holds one', () => {
    const cursor = base64url(JSON.stringify({v: 1, k: ['1762586400.500000', 17], d: 'after', f: 'x'}));

    assert.equal(scannedToText(cursor, {status: 'failed', order: 'desc'}), 'Searched back to 2025-11-08 07:20:00 UTC');
    assert.equal(scannedToText(cursor, {status: 'failed', order: 'asc'}), 'Searched up to 2025-11-08 07:20:00 UTC');
    assert.equal(scannedToText(cursor, {status: 'stuck', signal: 'reservation_expired', order: 'asc'}), 'Searched up to 2025-11-08 07:20:00 UTC');

    // The other signals are read in the order of a count (gap, releases, attempts): the key holds no time.
    for (const signal of ['outcome_not_observed', 'repeated_releases', 'attempts_not_capped']) {
        assert.equal(scannedToText(cursor, {status: 'stuck', signal, order: 'desc'}), null, signal);
    }

    assert.equal(scannedToText(base64url(JSON.stringify({v: 1, k: [null, 17]})), {status: 'failed', order: 'desc'}), null);
    assert.equal(scannedToText('not a cursor', {status: 'failed', order: 'desc'}), null);
    assert.equal(scannedToText(null, {status: 'failed', order: 'desc'}), null);
});

// Rejects: a per-row verification claim, a missing connection, and "never checked" shown as a time (or a time shown as never).
test('lastVerifiedParts gives one part per connection', () => {
    assert.deepEqual(lastVerifiedParts({database: NOW - 27, 'database-reports': null}, NOW), [
        {connection: 'database', text: 'at 09:31:40'},
        {connection: 'database-reports', text: 'never checked'},
    ]);
    assert.equal(lastVerifiedParts({}, NOW), null);
    assert.deepEqual(lastVerifiedParts({database: NOW - 86400 * 3}, NOW), [{connection: 'database', text: 'at 2026-11-05 09:32'}]);
    assert.equal(lastVerifiedParts(null), null);
    assert.equal(lastVerifiedParts([]), null);
});

// Rejects: "now" for a delayed job, a delay for an unknown time, and a job available in the past shown as delayed.
test('availableText says when a pending job can run', () => {
    assert.equal(availableText(NOW + 1673, NOW), 'delayed until 10:00:00');
    assert.equal(availableText(NOW - 5, NOW), 'now');
    assert.equal(availableText(null, NOW), '—');
    assert.equal(availableText(NOW + 86400, NOW), 'delayed until 2026-11-09 09:32');
});

// Rejects: 0 ms for an unknown runtime, and one unit for every size.
test('runtimeText formats a runtime and keeps unknown as —', () => {
    assert.equal(runtimeText(412), '412 ms');
    assert.equal(runtimeText(30000), '30.0 s');
    assert.equal(runtimeText(1800), '1.8 s');
    assert.equal(runtimeText(125000), '2 min 5 s');
    assert.equal(runtimeText(0), '0 ms');
    assert.equal(runtimeText(null), '—');
});

// Rejects: the plural of one attempt, and a retry count shown when the job was never retried.
test('attemptsText and retriedText', () => {
    assert.equal(attemptsText(1), '1 attempt');
    assert.equal(attemptsText(3), '3 attempts');
    assert.equal(attemptsText(null), 'attempts unknown');
    assert.equal(retriedText({retried_count: 0}), null);
    assert.equal(retriedText({retried_count: 1}), 'retried once');
    assert.equal(retriedText({retried_count: 4, retried_count_inferred: true}), 'retried 4 times (inferred)');
});

// Rejects: more than two chips, a lost count of the hidden tags, and a truncated tag list that looks complete.
test('tagChips shows two tags and counts the rest', () => {
    assert.deepEqual(tagChips(['a', 'b', 'c', 'd'], false), {shown: ['a', 'b'], more: '+2', title: 'a, b, c, d'});
    assert.deepEqual(tagChips(['a'], false), {shown: ['a'], more: null, title: 'a'});
    assert.deepEqual(tagChips(['a', 'b', 'c'], true), {shown: ['a', 'b'], more: '+1 and more', title: 'a, b, c, and more tags that were not kept'});
    assert.deepEqual(tagChips(['a'], true), {shown: ['a'], more: 'more not kept', title: 'a, and more tags that were not kept'});
    assert.deepEqual(tagChips(null, false), {shown: [], more: null, title: ''});
});

// Rejects: a worker shown as live without a fact, "idle" or "live" for an unknown liveness, a silent worker without its heartbeat time, and an unidentified worker shown with an empty id.
test('workerCell words the worker and its liveness', () => {
    assert.deepEqual(workerCell({worker: {id: 'web-2:41822:1762586400', heartbeat_at: NOW - 4, liveness: 'live', silent_since: null}}, NOW), {
        id: 'web-2:41822:1762586400', tone: 'live', text: '● live · heartbeat 4 s ago',
    });
    assert.deepEqual(workerCell({worker: {id: 'worker-1:9120:1', heartbeat_at: NOW - 326, liveness: 'silent', silent_since: NOW - 247}}, NOW), {
        id: 'worker-1:9120:1', tone: 'silent', text: '● silent · heartbeat 09:26:41',
    });
    assert.deepEqual(workerCell({worker: {id: null, heartbeat_at: null, liveness: 'unknown', silent_since: null}}, NOW), {
        id: 'not identified', tone: 'unknown', text: 'liveness unknown',
    });
    assert.deepEqual(workerCell({worker: {id: 'w:1:2', heartbeat_at: null, liveness: 'live', silent_since: null}}, NOW), {
        id: 'w:1:2', tone: 'live', text: '● live',
    });
    assert.deepEqual(workerCell({worker: null}, NOW), {id: '—', tone: 'unknown', text: null});
});

// Rejects: an expired lease shown as running, a future lease shown as expired, the silent time dropped from an expired lease, and a lease invented for an unknown value.
test('leaseCell words the lease of a running row', () => {
    assert.deepEqual(leaseCell({lease_expires_at: NOW + 72, worker: null}, NOW), {expired: false, text: 'expires in 1 min 12 s', detail: 'until 09:33:19'});
    assert.deepEqual(leaseCell({lease_expires_at: NOW - 247, worker: {liveness: 'silent', silent_since: NOW - 247}}, NOW), {
        expired: true, text: 'Lease expired 4 min ago', detail: 'Worker silent since 09:28:00',
    });
    assert.deepEqual(leaseCell({lease_expires_at: NOW - 10, worker: {liveness: 'live', silent_since: null}}, NOW), {expired: true, text: 'Lease expired 10 s ago', detail: null});
    assert.deepEqual(leaseCell({lease_expires_at: null}, NOW), {expired: false, text: '—', detail: null});
});

// Rejects: a held row whose wait for an operator is not said, an awaiting reason or a state shown as its raw code, a missing actor shown as empty, and the actor treated as anything but text.
test('operationCell words the holding operation', () => {
    const awaiting = operationCell({id: '4182', action: 'retry', state: 'claimed', awaiting_reason: 'sqlite_host_file', actor: 'ops@acme.test', created_at: NOW - 1163}, NOW);

    assert.equal(awaiting.label, 'Retry · awaiting operator');
    assert.equal(awaiting.tone, 'warning');
    assert.equal(awaiting.text, "Waiting for an operator: this step writes the application's SQLite file. Run php artisan orrery:resolve 4182.");
    assert.equal(awaiting.started, 'Started 09:12:44 by ops@acme.test');

    const running = operationCell({id: '9', action: 'forget', state: 'published', awaiting_reason: null, actor: null, created_at: null}, NOW);

    assert.equal(running.label, 'Forget · published');
    assert.equal(running.tone, 'accent');
    assert.equal(running.text, 'Published: the job is back on its queue.');
    assert.equal(running.started, 'Started at an unknown time by an unknown actor');

    const bare = operationCell({id: '7', action: null, state: null, awaiting_reason: null, actor: '<b>x</b>', created_at: NOW}, NOW);

    assert.equal(bare.label, 'Operation · state unknown');
    assert.equal(bare.started, 'Started 09:32:07 by <b>x</b>');
    assert.deepEqual(operationCell(null), {label: 'Operation unknown', tone: 'neutral', text: 'The operation that holds this job could not be read.', started: null});
});

// Rejects: a signal shown by its code, and every signal in one tone.
test('signalChip labels each signal', () => {
    assert.deepEqual(signalChip('reservation_expired'), {label: 'Reservation expired', tone: 'warning'});
    assert.deepEqual(signalChip('outcome_not_observed'), {label: 'Outcome not observed', tone: 'warning'});
    assert.deepEqual(signalChip('repeated_releases'), {label: 'Repeated releases', tone: 'neutral'});
    assert.deepEqual(signalChip('attempts_not_capped'), {label: 'Attempts not capped', tone: 'neutral'});
    assert.deepEqual(signalChip('later_signal'), {label: 'later_signal', tone: 'neutral'});
});

// Rejects: a fact sentence that claims a crash or a pickup, a wrong plural, a lost number, and a deadline shown in another form than a local time.
test('signalText fills each signal with its facts', () => {
    const row = {reserved_at: NOW - 337};

    assert.equal(signalText('reservation_expired', {lease_expires_at: NOW - 247, retry_after: 90}, row, NOW), 'Reserved at 09:26:30; the 90 s lease ended at 09:28:00.');
    assert.equal(signalText('outcome_not_observed', {gap: 1}, row, NOW), '1 attempt ended without an observed outcome (gap 1).');
    assert.equal(signalText('outcome_not_observed', {gap: 2}, row, NOW), '2 attempts ended without an observed outcome (gap 2).');
    assert.equal(signalText('repeated_releases', {releases: 24}, row, NOW), 'Released 24 times in this cycle.');
    assert.equal(signalText('attempts_not_capped', {attempts: 31, rule: 'none'}, row, NOW), '31 attempts, and no attempt limit applies (tries 0).');
    assert.equal(signalText('attempts_not_capped', {attempts: 31, rule: 'deadline', deadline: NOW + 8873}, row, NOW), '31 attempts; no attempt limit applies before its retryUntil deadline 12:00:00.');
    assert.equal(signalText('reservation_expired', {}, {reserved_at: null}, NOW), 'Reserved at —; the — lease ended at —.');

    for (const signal of STUCK_SIGNALS) {
        assert.doesNotMatch(signalText(signal, {gap: 1, releases: 1, attempts: 1, rule: 'none'}, row, NOW), /crash|picked up|dead/i);
    }
});

// Rejects: a rule shown by its kind code, a lost source of the tries, an unknown limit shown as unlimited, and a deadline of another day shown as a bare clock (it would read as a time that passed).
test('enforcedRuleText words the enforced rule', () => {
    assert.equal(enforcedRuleText({kind: 'attempt_limit', tries: 3, tries_source: 'payload', deadline: null}, NOW), 'Fails after 3 attempts (from the payload)');
    assert.equal(enforcedRuleText({kind: 'attempt_limit', tries: 1, tries_source: 'worker', deadline: null}, NOW), "Fails after 1 attempt (from the worker's --tries)");
    assert.equal(enforcedRuleText({kind: 'deadline', tries: null, tries_source: 'unknown', deadline: NOW + 8873}, NOW), 'Runs until 12:00:00, then fails (retryUntil)');
    assert.equal(enforcedRuleText({kind: 'deadline_passed', deadline: NOW - 60}, NOW), 'Its retryUntil deadline 09:31:07 has passed: it fails at its next attempt');
    assert.equal(enforcedRuleText({kind: 'none', tries: 0}, NOW), 'No attempt limit (tries 0)');
    assert.equal(enforcedRuleText({kind: 'unknown', tries: null}, NOW), 'Attempt limit unknown (no payload or worker value)');
    assert.equal(enforcedRuleText(null, NOW), '—');
    // A time of another day keeps its date.
    assert.equal(enforcedRuleText({kind: 'deadline', deadline: NOW + 86400}, NOW), 'Runs until 2026-11-09 09:32, then fails (retryUntil)');
    assert.equal(signalText('attempts_not_capped', {attempts: 21, rule: 'deadline', deadline: NOW + 86400}, {}, NOW), '21 attempts; no attempt limit applies before its retryUntil deadline 2026-11-09 09:32.');
});

// Rejects: the index state for another error, a hint that is not the response's own, a cursor of another list that is not reset, and a failure shown as an empty list.
test('listFailure tells the index state, a stale cursor and an error apart', () => {
    const indexMissing = {response: {status: 503, data: {error: 'index_missing', hint: 'php artisan orrery:install --indexes', index: 'st_gap'}}};

    assert.deepEqual(listFailure(indexMissing), {
        kind: 'index_missing',
        title: 'This list needs an index that is not built yet',
        text: 'Reservation expired still works; the other signals wait for the index.',
        hint: 'php artisan orrery:install --indexes',
    });

    assert.equal(listFailure({response: {status: 503, data: {error: 'index_missing'}}}).hint, null);
    assert.equal(listFailure({response: {status: 422, data: {error: 'cursor_mismatch', field: 'cursor'}}}).kind, 'reset');

    const timeout = listFailure({response: {status: 503, data: {error: 'read_timeout'}}});

    assert.equal(timeout.kind, 'error');
    assert.equal(timeout.title, 'The jobs list could not be loaded');
    assert.equal(timeout.text, 'A read took too long. Narrow the selection and try again.');

    assert.equal(listFailure({response: {status: 500, data: {}}}).text, 'The server answered with an error (HTTP 500). Try again.');
    // A body of the wrong shape (a login page with status 200) is an unreadable answer, not "no answer".
    assert.equal(listFailure(new TypeError('The answer has no jobs list.')).text, "The server's answer could not be read. Reload the page; you may need to sign in again.");
    assert.equal(listFailure({code: 'ECONNABORTED'}).text, 'The server did not answer. Try again.');
});

// Rejects: an empty list that hides active filters, a "no rows" text while filters are set, and a stuck tab that says no job is kept.
test('emptyState tells no rows from no matching rows', () => {
    assert.deepEqual(emptyState(filters(), null), {filtered: false, partial: false, title: 'No failed jobs are kept', text: 'None are in this list right now.'});

    const filtered = emptyState(filters({connection: 'database', queue: 'emails', class: 'App\\Jobs\\SendInvoice'}), null);

    assert.equal(filtered.filtered, true);
    assert.equal(filtered.title, 'No failed jobs match these filters');
    assert.equal(filtered.text, 'database:emails · class App\\Jobs\\SendInvoice');

    assert.equal(emptyState(filters({q: 'mail'}), null).title, 'No failed jobs match these filters');
    assert.equal(emptyState(filters({status: 'stuck'}), null, 'repeated_releases').title, 'No job shows this signal now');
    assert.equal(emptyState(filters({status: 'stuck', tag: 'x'}), null, 'repeated_releases').filtered, false);
});

// Rejects: an empty page of a limited search shown as "No … match these filters" (a match can be beyond the rows
// searched), an empty later page shown as "none are kept" with the all-clear mark, and a pager hidden on an empty
// page that has a cursor (the search is a dead end).
test('an empty page with a cursor keeps the pager and says the search is partial', () => {
    const narrow = filters({class: 'App\\Jobs\\Rare'});
    const limited = {search_limited: true, prev_cursor: null, next_cursor: 'n1'};

    assert.deepEqual(emptyState(narrow, null, null, limited), {
        filtered: true,
        partial: true,
        title: 'No match in the rows searched so far',
        text: 'class App\\Jobs\\Rare · use Older to search on',
    });
    assert.equal(emptyState(narrow, null, null, {search_limited: true, prev_cursor: 'p', next_cursor: null}).text, 'class App\\Jobs\\Rare');

    const later = emptyState(filters(), null, null, {search_limited: false, prev_cursor: 'p', next_cursor: null});
    assert.deepEqual([later.partial, later.title], [true, 'No failed jobs on this page']);
    assert.equal(emptyState(narrow, null, null, {search_limited: false, prev_cursor: null, next_cursor: null}).title, 'No failed jobs match these filters');

    assert.equal(pagerShown(limited, 0), true);
    assert.equal(pagerShown({prev_cursor: 'p', next_cursor: null}, 0), true);
    assert.equal(pagerShown({prev_cursor: null, next_cursor: null}, 0), false);
    assert.equal(pagerShown({prev_cursor: null, next_cursor: null}, 3), true);
    assert.equal(pagerShown(null, 3), false);
});

// Rejects: bulk buttons on a tab other than Failed, on a list known to be empty, for a read-only failed-record driver (and its message missing), for a viewer (removed, not disabled), while a search is set (the run would act on more jobs than the list shows), and labels that hide a capped or unknown total.
test('bulkState shows Retry all and Forget all only where a run can start', () => {
    const shown = bulkState('failed', true, filters({connection: 'db', queue: 'q'}), {count: 86, at_least: false});

    assert.deepEqual(shown, {visible: true, blocker: null, readOnlyText: null, filterText: null, retryLabel: 'Retry all 86…', forgetLabel: 'Forget all 86…', menuLabel: 'Act on all 86'});

    assert.equal(bulkState('pending', true, filters({status: 'pending'}), {count: 3, at_least: false}).visible, false);
    assert.equal(bulkState('failed', false, filters(), {count: 3, at_least: false}).visible, false);

    const search = bulkState('failed', true, filters({q: 'mail'}), {count: 3, at_least: false});

    assert.equal(search.visible, false);
    assert.equal(search.blocker, 'Clear the search to act on all matching jobs: the search is not part of a bulk run.');

    assert.equal(bulkState('failed', true, filters({batch: 'b-1'}), {count: 3, at_least: false}).visible, false);
    assert.equal(bulkState('failed', true, filters(), {count: 10000, at_least: true}).retryLabel, 'Retry all 10,000+…');
    assert.equal(bulkState('failed', true, filters(), null).retryLabel, 'Retry all…');
    assert.equal(bulkState('failed', true, filters(), null).menuLabel, 'Act on all');
    // A known empty list offers nothing to act on; a capped or unknown one does.
    assert.equal(bulkState('failed', true, filters(), {count: 0, at_least: false}).visible, false);
    assert.equal(bulkState('failed', true, filters(), {count: 0, at_least: true}).visible, true);

    // A read-only failed-record driver: no run, and its message once.
    const readOnly = bulkState('failed', true, filters(), {count: 3, at_least: false}, {readOnly: {reason: 'file', message: 'Read-only driver.'}});

    assert.equal(readOnly.visible, false);
    assert.equal(readOnly.readOnlyText, 'Read-only driver.');
    assert.equal(shown.readOnlyText, null);
    assert.equal(bulkState('failed', false, filters(), null, {readOnly: {message: 'Read-only driver.'}}).readOnlyText, 'Read-only driver.');
    assert.equal(bulkState('pending', true, filters({status: 'pending'}), null, {readOnly: {message: 'x'}}).readOnlyText, null);
});

// Rejects: a selection key that misses a filter change (an open sheet would keep the old selection), and one that ignores the action.
test('bulkSelectionKey changes with the filters and the action', () => {
    const one = bulkSelectionKey(filters({queue: 'a', connection: 'db'}), 'retry');

    assert.equal(one, bulkSelectionKey(filters({queue: 'a', connection: 'db'}), 'retry'));
    assert.notEqual(one, bulkSelectionKey(filters({queue: 'b', connection: 'db'}), 'retry'));
    assert.notEqual(one, bulkSelectionKey(filters({queue: 'a', connection: 'db'}), 'forget'));
    assert.notEqual(one, bulkSelectionKey(filters({queue: 'a', connection: 'db', from: 5}), 'retry'));
});

// Rejects: a link built from an id that is not a uuid (the detail route matches only a uuid).
test('jobLink links a uuid row to its detail', () => {
    assert.equal(jobLink({id: UUID}), `/jobs/${UUID}`);
    assert.equal(jobLink({id: '17'}), null);
    assert.equal(jobLink(null), null);
});

// Rejects: a short id longer than 8 characters, and a short name that keeps the namespace.
test('shortId and shortName', () => {
    assert.equal(shortId(UUID), '0b6f1c9e');
    assert.equal(shortId(null), '—');
    assert.equal(shortName('App\\Mail\\OrderShipped'), 'OrderShipped');
    assert.equal(shortName(''), '—');
});

// Rejects: a redacted marker rendered as plain text (or as markup), and a message without a marker split.
test('markedText splits the redaction markers out of a message', () => {
    assert.deepEqual(markedText('password=[redacted] rejected'), [
        {text: 'password=', marker: false},
        {text: '[redacted]', marker: true},
        {text: ' rejected', marker: false},
    ]);
    assert.deepEqual(markedText('plain'), [{text: 'plain', marker: false}]);
    assert.deepEqual(markedText(null), []);
});

// Rejects: a narrow-width line without the queue or the attempts, a 0 runtime for unknown, and a line of another tab's columns.
test('compactLine gives the third line of a narrow row', () => {
    const row = {queue: 'emails', attempts: 3, runtime_ms: 30000, tags: ['order:18422', 'customer:1'], reserved_at: NOW - 30};

    assert.equal(compactLine(row, 'failed'), 'emails · 3 attempts · 30.0 s · order:18422');
    assert.equal(compactLine({...row, runtime_ms: null, tags: []}, 'completed'), 'emails · 3 attempts · runtime —');
    assert.equal(compactLine(row, 'pending'), 'emails · 3 attempts · order:18422');
});

// Rejects: Newest or Oldest on the stuck tab (its endpoint has one fixed order: an asc request would be answered desc and shown reversed), and a stuck cursor request in another order.
test('pagerRequestFor keeps the stuck list in its one order', () => {
    const page = {order: 'desc', response: {prev_cursor: 'p', next_cursor: 'n'}};

    assert.equal(pagerRequestFor('stuck', 'oldest', page), null);
    assert.equal(pagerRequestFor('stuck', 'newest', page), null);
    assert.deepEqual(pagerRequestFor('stuck', 'older', page), {order: 'desc', cursor: 'n'});
    assert.deepEqual(pagerRequestFor('stuck', 'newer', page), {order: 'desc', cursor: 'p'});
    // A page that claims the order asc (an order=asc URL) still pages desc: the stuck endpoint has no other order.
    assert.deepEqual(pagerRequestFor('stuck', 'older', {...page, order: 'asc'}), {order: 'desc', cursor: 'n'});
    assert.deepEqual(pagerRequestFor('failed', 'oldest', page), {order: 'asc', cursor: null});
    assert.deepEqual(pagerRequestFor('failed', 'older', page), {order: 'desc', cursor: 'n'});

    assert.equal(listOrder(filters({status: 'stuck', order: 'asc'})), 'desc');
    assert.equal(listOrder(filters({order: 'asc'})), 'asc');
});

// Rejects: a first page reached through a `before` cursor that keeps its cursor (the poll would never send newer_than again), a head kept from another page or a non-string head, and a later page that drops the head.
test('pageAfter makes every first page the head of the list', () => {
    assert.deepEqual(pageAfter({order: 'desc', cursor: 'before-1'}, {prev_cursor: null, head: 'h2'}, 'h1'), {page: {order: 'desc', cursor: null}, head: 'h2'});
    assert.deepEqual(pageAfter({order: 'desc', cursor: null}, {prev_cursor: null, head: null}, 'h1'), {page: {order: 'desc', cursor: null}, head: null});
    assert.deepEqual(pageAfter({order: 'desc', cursor: null}, {prev_cursor: null, head: 7}, 'h1'), {page: {order: 'desc', cursor: null}, head: null});
    assert.deepEqual(pageAfter({order: 'desc', cursor: 'after-1'}, {prev_cursor: 'p', head: null}, 'h1'), {page: {order: 'desc', cursor: 'after-1'}, head: 'h1'});
});

// Rejects: a cursor_mismatch that is ignored when the request carried a head (every poll would answer 422 for ever), and a reload loop for a mismatch that no cursor of this screen caused.
test('mismatchAction reloads the first page without the head', () => {
    assert.equal(mismatchAction({cursor: 'c'}), 'reload');
    assert.equal(mismatchAction({newer_than: 'h'}), 'reload');
    assert.equal(mismatchAction({status: 'failed'}), 'error');
});

// Rejects: a later page whose lower-bound total replaces the list total in the title and the bulk labels, a later page whose exact live total is ignored for an older first-page total, and a first page that keeps an older total.
test('shownTotal keeps the first page total on later pages', () => {
    const first = {count: 86, at_least: false};

    assert.deepEqual(shownTotal({prev_cursor: 'p', total: {count: 12, at_least: true}}, first), first);
    assert.deepEqual(shownTotal({prev_cursor: null, total: {count: 90, at_least: false}}, first), {count: 90, at_least: false});
    assert.deepEqual(shownTotal({prev_cursor: 'p', total: {count: 12, at_least: true}}, null), {count: 12, at_least: true});
    assert.equal(shownTotal(null, first), null);
    // A later page without a residual filter counts the whole list (not a lower bound): it is the live total.
    assert.deepEqual(shownTotal({prev_cursor: 'p', total: {count: 400, at_least: false}}, first), {count: 400, at_least: false});
    assert.deepEqual(shownTotal({prev_cursor: 'p', total: null}, first), first);
});

// Rejects: a filter bar on the stuck tab that counts, words or shows a filter its request drops (search, tag, batch, time), and a change on the stuck tab that wipes those filters from the URL.
test('stuckBarFilters and mergeBarChange keep the stuck bar to what the request sends', () => {
    const all = filters({status: 'stuck', connection: 'db', queue: 'q', class: 'A', tag: 't', batch: 'b', from: 1, to: 2, q: 'mail'});
    const bar = stuckBarFilters(all);

    assert.deepEqual([bar.tag, bar.batch, bar.from, bar.to, bar.q], [null, null, null, null, null]);
    assert.deepEqual([bar.connection, bar.queue, bar.class, bar.status], ['db', 'q', 'A', 'stuck']);

    const merged = mergeBarChange(all, {...bar, class: 'B'});

    assert.deepEqual([merged.class, merged.tag, merged.batch, merged.from, merged.q], ['B', 't', 'b', 1, 'mail']);
    assert.equal(mergeBarChange(all, {...bar, status: 'failed'}).status, 'failed');
});

// Rejects: a pending stuck row whose push time is shown as a reservation, and a reserved row marked as pushed.
test('stuckTime names the push time of a pending row', () => {
    assert.deepEqual(stuckTime({status: 'pending', reserved_at: null, pushed_at: NOW - 115}, NOW), {primary: 'pushed 09:30:12', relative: '1 min ago', title: 'Pushed 2026-11-08 09:30:12 UTC'});
    // A released row is pending again and keeps the reservation of its last attempt: it shows its push.
    assert.equal(stuckTime({status: 'pending', reserved_at: NOW - 30, pushed_at: NOW - 115}, NOW).primary, 'pushed 09:30:12');
    assert.deepEqual(stuckTime({status: 'reserved', reserved_at: NOW - 115, pushed_at: NOW - 300}, NOW), {primary: '09:30:12', relative: '1 min ago', title: '2026-11-08 09:30:12 UTC'});
    assert.equal(timeHeader('stuck', 'desc'), 'Reserved or pushed at');
});

// Rejects: a clock without its date for a time of another day (a 7-day list would mix days), a 0 or "just now" for an unknown time, and a cell without the full time and zone in its title.
test('timeCell shows the clock today and the date on another day', () => {
    assert.deepEqual(timeCell(NOW - 115, NOW), {primary: '09:30:12', relative: '1 min ago', title: '2026-11-08 09:30:12 UTC'});
    assert.deepEqual(timeCell(NOW - 86400 * 2, NOW), {primary: '2026-11-06 09:32', relative: '2 d ago', title: '2026-11-06 09:32:07 UTC'});
    assert.deepEqual(timeCell('1762594212.5', NOW), {primary: '2025-11-08 09:30', relative: '365 d ago', title: '2025-11-08 09:30:12 UTC'});
    assert.deepEqual(timeCell(null, NOW), {primary: '—', relative: null, title: ''});
});

// Rejects: a "Clear filters" of the stuck empty state that wipes the filters the stuck bar hides (FilterBar keeps them), one that drops the signal or another key of the screen, and a status-tab clear that keeps a filter.
test('clearedFiltersQuery clears what the bar shows and keeps the rest', () => {
    const query = {status: 'stuck', signal: 'repeated_releases', connection: 'db', queue: 'q', class: 'A', tag: 't', q: 'mail', from: '1', order: 'asc', extra: 'x'};
    const stuck = clearedFiltersQuery(query, filtersFromQuery(query));

    assert.deepEqual(stuck, {signal: 'repeated_releases', extra: 'x', status: 'stuck', tag: 't', from: '1', q: 'mail', order: 'asc'});

    const failedQuery = {status: 'failed', connection: 'db', queue: 'q', class: 'A', tag: 't', q: 'mail', order: 'asc', extra: 'x'};

    assert.deepEqual(clearedFiltersQuery(failedQuery, filtersFromQuery(failedQuery)), {extra: 'x', status: 'failed', order: 'asc'});
});

// Rejects: the browser's day while orrery.display_timezone is set (the test runs in UTC; Tokyo is 9 h ahead,
// so NOW + 6 h is already the next day there and needs its date).
test('a row moment uses the day of the display time zone', () => {
    const before = globalThis.Orrery;

    globalThis.Orrery = {displayTimezone: 'Asia/Tokyo'};

    try {
        assert.equal(availableText(NOW + 6 * 3600, NOW), 'delayed until 2026-11-09 00:32');
        assert.equal(availableText(NOW + 3600, NOW), 'delayed until 19:32:07');
    } finally {
        if (before === undefined) delete globalThis.Orrery;
        else globalThis.Orrery = before;
    }

    assert.equal(availableText(NOW + 6 * 3600, NOW), 'delayed until 15:32:07');
});

// Rejects: Retry all and Forget all on a list whose URL filter was refused (the run would act on every queue
// instead of the asked one), and a server 422 on a filter shown as a generic error with "Try again".
test('an invalid filter hides the bulk actions and names the filter', () => {
    const problem = {code: 'queue_needs_connection', field: 'queue', values: {queue: 'database:default'}};

    assert.equal(bulkState('failed', true, filters(), {count: 67, at_least: false}, {filterProblem: problem}).visible, false);
    assert.equal(bulkState('failed', true, filters(), {count: 67, at_least: false}, {filterProblem: problem}).forgetLabel, null);
    assert.equal(bulkState('failed', true, filters(), {count: 67, at_least: false}, {filterProblem: null}).visible, true);
    // "Start a new run…" of a recovered run names the refused filter, never "no members".
    assert.equal(bulkState('failed', true, filters(), null, {filterProblem: problem}).filterText,
        'The link names the queue “database:default” without its connection, so the list is not filtered and not shown. Choose the queue in the filter, or clear the filters.');
    assert.equal(bulkState('failed', true, filters(), null, {filterProblem: {kind: 'filter', text: 'Server refused.'}}).filterText, 'Server refused.');
    assert.equal(bulkState('failed', true, filters(), null).filterText, null);

    assert.deepEqual(filterFailure(problem), {
        kind: 'filter',
        title: 'This link has a filter that cannot be applied',
        text: 'The link names the queue “database:default” without its connection, so the list is not filtered and not shown. Choose the queue in the filter, or clear the filters.',
        hint: null,
    });

    const refused = listFailure({response: {status: 422, data: {error: 'invalid', message: 'queue needs connection.', field: 'queue'}}});

    assert.equal(refused.kind, 'filter');
    assert.equal(refused.text, 'The filter “queue” of this link cannot be applied, so the list is not shown. Choose the filter again, or clear the filters.');
    assert.equal(listFailure({response: {status: 422, data: {error: 'invalid', field: 'cursor'}}}).kind, 'error');
    assert.equal(listFailure({response: {status: 500, data: {error: 'invalid', field: 'queue'}}}).kind, 'error');
});

// Rejects: a row time that follows the toolbar's "times in" zone across a daylight-saving change (Vilnius:
// 03:30 on 2026-10-25 is GMT+3, the note at 14:00 says GMT+2), so it would read an hour off.
test('timeCell names the zone of a row whose offset differs from now', () => {
    const now = Date.UTC(2026, 9, 25, 12, 0) / 1000;
    globalThis.Orrery = {displayTimezone: 'Europe/Vilnius'};

    try {
        assert.equal(timeCell(Date.UTC(2026, 9, 25, 0, 30) / 1000, now).primary, '03:30:00 GMT+3');
        assert.equal(timeCell(Date.UTC(2026, 9, 25, 9, 0) / 1000, now).primary, '11:00:00');
        assert.equal(timeCell(Date.UTC(2026, 9, 20, 9, 0) / 1000, now).primary, '2026-10-20 12:00 GMT+3');
    } finally {
        delete globalThis.Orrery;
    }
});

// Rejects: "Start a new run…" that opens a sheet on the tab's current filters instead of the recovered run's
// selection (a narrow run reopened as a run over every failed job), a run reopened on a search or batch filter,
// and a recovered source of another kind applied as list filters.
test('restartPlan reopens the recovered run only on its own selection', () => {
    const source = {kind: 'list', connection: 'database', queue: 'emails', class: 'App\\Jobs\\Send', tag: null, from: 100, to: 200};

    assert.deepEqual(restartPlan(source, filters({connection: 'database', queue: 'emails', class: 'App\\Jobs\\Send', from: 100, to: 200})),
        {open: true, query: null, note: null});

    const moved = restartPlan(source, filters());
    assert.equal(moved.open, false);
    assert.deepEqual(moved.query, {status: 'failed', connection: 'database', queue: 'emails', class: 'App\\Jobs\\Send', from: '100', to: '200'});
    assert.match(moved.note, /filters of that run/);

    assert.equal(restartPlan(source, filters({connection: 'database', queue: 'emails', class: 'App\\Jobs\\Send', from: 100, to: 200, q: 'x'})).open, false);
    assert.equal(restartPlan(source, filters({status: 'completed', connection: 'database', queue: 'emails', class: 'App\\Jobs\\Send', from: 100, to: 200})).open, false);
    assert.deepEqual(restartPlan({kind: 'list', connection: null, queue: null, class: null, tag: null, from: null, to: null}, filters()),
        {open: true, query: null, note: null});

    for (const other of [{kind: 'groups', keys: ['k'], from: 0, to: 1, queue: null}, {kind: 'batch', batch_id: 'b'}, null, {kind: 'list', connection: 5}]) {
        const plan = restartPlan(other, filters());
        assert.equal(plan.open, false);
        assert.equal(plan.query, null);
        assert.equal(typeof plan.note, 'string');
    }
});

// Rejects: an empty page behind the end of the list (its rows left after the cursor was taken) read as the
// list's first page, so the screen said "No … are kept" under a total above 0; and a reload for a real first page.
// Still passes: an empty later page that the server still answers with a prev_cursor (it pages back itself).
test('pastTheEnd finds an empty page behind the end of the list', () => {
    assert.equal(pastTheEnd({order: 'desc', cursor: 'after-9'}, {jobs: [], prev_cursor: null, total: {count: 9, at_least: false}}), true);
    assert.equal(pastTheEnd({order: 'desc', cursor: 'after-9'}, {jobs: [], prev_cursor: 'before-10'}), false);
    assert.equal(pastTheEnd({order: 'desc', cursor: 'after-9'}, {jobs: [{id: 'x'}], prev_cursor: null}), false);
    assert.equal(pastTheEnd({order: 'desc', cursor: null}, {jobs: [], prev_cursor: null}), false);
    assert.equal(pastTheEnd(null, {jobs: []}), false);
    assert.equal(pastTheEnd({cursor: 'after-9'}, null), false);
});

// Rejects (nit): the "Auto-load new" switch on a state tab (Pending, Running, Retrying), where every poll replaces
// the page and the switch does nothing, and on the stuck list or an oldest-first list.
// Still passes: a status that no tab has (the screen never sends one).
test('autoLoadApplies only where the switch acts', () => {
    assert.equal(autoLoadApplies('completed', 'desc'), true);
    assert.equal(autoLoadApplies('failed', 'desc'), true);
    assert.equal(autoLoadApplies('completed', 'asc'), false);
    assert.equal(autoLoadApplies('pending', 'desc'), false);
    assert.equal(autoLoadApplies('running', 'desc'), false);
    assert.equal(autoLoadApplies('retrying', 'desc'), false);
    assert.equal(autoLoadApplies('stuck', 'desc'), false);
});
