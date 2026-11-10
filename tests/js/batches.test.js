// Tests of resources/js/screens/batches/batches.js: the rules of the
// Batches screen.
// `npm test` runs them with TZ=UTC and LANG=en_US.UTF-8.

import {test} from 'node:test';
import assert from 'node:assert/strict';

import {
    MEMBER_TABS,
    MEMBERS_CAPTION,
    PAGE_SIZE,
    QUERY_MAX,
    batchSource,
    batchState,
    batchTitle,
    bulkPanelMounted,
    bulkStorageKey,
    CANCEL_TEXT,
    cancelFailure,
    cancelOutcome,
    cancelSheet,
    cancelUrl,
    cancelVisible,
    contentShown,
    countParts,
    countText,
    headerTimes,
    leaveDecision,
    listParams,
    listState,
    memberTabs,
    narrowParts,
    olderCursor,
    pagerShown,
    pollReads,
    optionsView,
    progressBar,
    progressText,
    queryFromRoute,
    readFailure,
    retryState,
    rowMeta,
    rowTime,
    shortBatchId,
    shortTime,
    staleText,
    stateChip,
    unknownReasonText,
} from '../../resources/js/screens/batches/batches.js';

// 2026-11-08 09:00:04 UTC.
const CREATED = 1794128404;
const NOW = CREATED + 1800;

function batch(extra = {}) {
    return {
        id: '9c4b2a7e-31f0-4d8a-b6e2-5f1a0c9d7e44',
        name: 'November invoices',
        total_jobs: 540,
        pending_jobs: 128,
        processed_jobs: 412,
        failed_jobs: 23,
        progress: 76,
        created_at: CREATED,
        cancelled_at: null,
        finished_at: null,
        options: null,
        ...extra,
    };
}

// Rejects: a state read from pending alone (pending 0 as finished), finished_at read before cancelled_at (a cancelled batch that later finished shown as finished), and a batch whose pending jobs all failed shown as running.
test('batchState reads cancelled, then finished, else running', () => {
    assert.equal(batchState(batch()), 'running');
    assert.equal(batchState(batch({pending_jobs: 0, failed_jobs: 0})), 'running');
    assert.equal(batchState(batch({finished_at: CREATED + 60})), 'finished');
    assert.equal(batchState(batch({finished_at: CREATED + 60, cancelled_at: CREATED + 30})), 'cancelled');
    assert.equal(batchState(batch({finished_at: '1794128464.5'})), 'finished');
    assert.equal(batchState(null), 'running');
    // Laravel keeps a failed job pending: every pending job failed, so the batch waits for a retry.
    assert.equal(batchState(batch({pending_jobs: 1, failed_jobs: 1})), 'failures');
    assert.equal(batchState(batch({pending_jobs: 2, failed_jobs: 1})), 'running');
    assert.equal(batchState(batch({pending_jobs: null, failed_jobs: 1})), 'running');
    // Never "ran": a failure stays counted after its retry succeeded, so pending jobs may still run.
    assert.deepEqual(stateChip('failures'), {label: 'Open with failures', tone: 'warning'});
    assert.deepEqual(stateChip('running'), {label: 'Running', tone: 'accent'});
    assert.deepEqual(stateChip('finished'), {label: 'Finished', tone: 'success'});
    assert.deepEqual(stateChip('cancelled'), {label: 'Cancelled', tone: 'neutral'});
});

// Rejects: an empty title for a batch without a name, and a name cut by bytes instead of characters.
test('batchTitle names an unnamed batch by its short id', () => {
    assert.deepEqual(batchTitle(batch()), {text: 'November invoices', unnamed: false});
    assert.deepEqual(batchTitle(batch({name: null, id: '9a3f0000-0000-0000-0000-00000000e1c0'})), {text: 'Unnamed batch · 9a3f…e1c0', unnamed: true});
    assert.deepEqual(batchTitle(batch({name: ''})).unnamed, true);
    assert.equal(shortBatchId('b-1'), 'b-1');
    assert.equal(shortBatchId('ąčęėįšųūž-0123456789'), 'ąčęė…6789');
    // An astral character counts as one character, never half a surrogate pair.
    assert.equal(shortBatchId('😀abcdefghijk😀'), '😀abc…ijk😀');
});

// Rejects: a row time that always shows the creation time, and a date without a day for an older batch.
test('rowTime shows the state and the moment of that state', () => {
    assert.deepEqual(rowTime(batch(), NOW), {text: 'running · 09:00', title: '2026-11-08 09:00:04 UTC'});
    assert.equal(rowTime(batch({finished_at: CREATED + 2520}), NOW + 3600).text, 'finished · 09:42');
    assert.equal(rowTime(batch({cancelled_at: CREATED - 9900}), NOW).text, 'cancelled · 06:15');
    assert.equal(rowTime(batch({pending_jobs: 1, failed_jobs: 1}), NOW).text, 'open with failures · 09:00');
    assert.equal(shortTime(CREATED - 35000, NOW), 'Yesterday 23:16');
    assert.equal(shortTime(CREATED - 86400 * 3, NOW), '5 Nov 09:00');
    assert.equal(shortTime(null, NOW), '—');
});

// Rejects: a null count shown as 0, a pending part on a finished batch, and a failed part that is red at 0.
test('countParts gives the count line of a row', () => {
    assert.deepEqual(countParts(batch()), [
        {text: '412 of 540 processed', danger: false},
        {text: '23 failed', danger: true},
        {text: '128 pending', danger: false},
    ]);
    assert.deepEqual(countParts(batch({total_jobs: 1200, processed_jobs: 1200, pending_jobs: 0, failed_jobs: 0, finished_at: CREATED})), [
        {text: '1,200 of 1,200 processed', danger: false},
        {text: '0 failed', danger: false},
    ]);
    assert.deepEqual(countParts(batch({total_jobs: 300, processed_jobs: 96, failed_jobs: 6, pending_jobs: 204, cancelled_at: CREATED})).map(part => part.text), ['96 of 300 processed', '6 failed', 'cancelled']);
    assert.deepEqual(countParts(batch({total_jobs: null, processed_jobs: null, failed_jobs: null, pending_jobs: null})).map(part => part.text), ['— of — processed', '— failed', '— pending']);
    assert.equal(countText(null), '—');
    assert.equal(countText(0), '0');
    assert.equal(countText(1200), '1,200');
});

// Rejects: a bar from `progress` that ignores the failed share, a bar for unknown counts, and shares that pass 100 %.
test('progressBar splits the processed and the failed share by state', () => {
    const running = progressBar(batch());

    assert.equal(running.length, 2);
    assert.equal(running[0].tone, 'accent');
    assert.ok(Math.abs(running[0].percent - 76.296) < 0.01);
    assert.equal(running[1].tone, 'danger');
    assert.ok(Math.abs(running[1].percent - 4.259) < 0.01);
    assert.deepEqual(progressBar(batch({processed_jobs: 48, total_jobs: 48, failed_jobs: 0, finished_at: CREATED})), [{tone: 'success', percent: 100}]);
    assert.equal(progressBar(batch({total_jobs: 300, processed_jobs: 96, failed_jobs: 6, cancelled_at: CREATED}))[0].tone, 'paused');
    assert.equal(progressBar(batch({total_jobs: 5, processed_jobs: 4, pending_jobs: 1, failed_jobs: 1}))[0].tone, 'accent', 'open with failures may still run');
    assert.equal(progressBar(batch({total_jobs: null})), null);
    assert.equal(progressBar(batch({total_jobs: 0, processed_jobs: 0})), null);

    const over = progressBar(batch({total_jobs: 10, processed_jobs: 9, failed_jobs: 5}));

    assert.equal(over[0].percent + over[1].percent, 100);
    assert.equal(progressText(batch()), '76%');
    assert.equal(progressText(batch({progress: null})), '—');
});

// Rejects: a header without a zone label, a cancelled batch called "not finished", a failure count called a count of jobs (Laravel's failed_jobs counts every failure, also two of one job), and a header part that starts in upper case after the separator.
test('headerTimes names the creation and the end with the zone', () => {
    assert.equal(headerTimes(batch()), 'created 2026-11-08 09:00:04 UTC · not finished');
    assert.equal(headerTimes(batch({finished_at: CREATED + 60})), 'created 2026-11-08 09:00:04 UTC · finished 2026-11-08 09:01:04 UTC');
    assert.equal(headerTimes(batch({cancelled_at: CREATED + 30})), 'created 2026-11-08 09:00:04 UTC · cancelled 2026-11-08 09:00:34 UTC');
    assert.equal(headerTimes(batch({pending_jobs: 3, failed_jobs: 3})), 'created 2026-11-08 09:00:04 UTC · not finished: 3 failures. A failed job stays pending until a retry succeeds; other jobs may still run.');
    assert.equal(headerTimes(batch({pending_jobs: 1, failed_jobs: 1})), 'created 2026-11-08 09:00:04 UTC · not finished: 1 failure. A failed job stays pending until a retry succeeds; other jobs may still run.');
});

// Rejects: options shown as raw values (a callback's code or a redacted secret's name read as data), a missing queue shown as empty, a too-large omission shown as no options, an empty options array read as an unknown allowFailures (Laravel's default is "no"), and plain options with a key of a marker name (`class`, `omitted`) read as a parser marker.
test('optionsView shows the known options, the callbacks set and the other names', () => {
    const view = optionsView({
        connection: 'database',
        queue: 'emails',
        allowFailures: true,
        then: [{closure: true}],
        catch: [{closure: true}],
        progress: [],
        finally: [{closure: true}],
        api_key: '[redacted]',
    });

    assert.deepEqual(view, {connection: 'database', queue: 'emails', allowFailures: 'yes', callbacks: ['then', 'catch', 'finally'], others: ['api_key'], omitted: null, unreadable: false});
    assert.equal(optionsView({allowFailures: false}).allowFailures, 'no');
    assert.equal(optionsView({}).queue, '—');
    // Laravel's default: a batch without allowFailures does not allow failures.
    assert.equal(optionsView({}).allowFailures, 'no');
    assert.deepEqual(optionsView([]), {connection: '—', queue: '—', allowFailures: 'no', callbacks: [], others: [], omitted: null, unreadable: false});
    for (const marker of [{unavailable: 'error'}, {unavailable: 'limit', limit: 'depth'}, {encrypted: true}, {omitted: 'depth'}, {omitted: 'string_too_large', bytes: 9}, {class: 'App\\Options', properties: []}, {class: 'App\\Options', custom: true, bytes: 12}, {array: [{key: 'queue', value: {encrypted: true}}]}]) {
        assert.equal(optionsView(marker).unreadable, true, JSON.stringify(marker));
    }
    // A key of a marker name among plain options is an option, not a marker.
    assert.deepEqual(optionsView({queue: 'q', class: 'x'}), {connection: '—', queue: 'q', allowFailures: 'no', callbacks: [], others: ['class'], omitted: null, unreadable: false});
    assert.equal(optionsView({queue: 'q', omitted: 'depth'}).unreadable, false);
    assert.deepEqual(optionsView({queue: 'q', omitted: 'too_large', bytes: 70000}).others, ['omitted', 'bytes']);
    assert.equal(optionsView({encrypted: true, queue: 'q'}).unreadable, false);
    // An array node that the projection could not make plain (JobProjection::plain()) is a marker; an option named `array` among others is not.
    assert.deepEqual(optionsView({queue: 'q', array: 'x'}).others, ['array']);
    assert.equal(optionsView(null).connection, '—');
    assert.equal(optionsView('a:1:{}').unreadable, true);
    assert.equal(optionsView([1]).unreadable, true);
    assert.equal(optionsView({omitted: 'too_large', bytes: 70000}).omitted, 'Options not shown: 70,000 bytes, over the size that Orrery reads.');
});

// Rejects: member counts shown as 0 when unknown, a capped count without "at least", tabs in another order, and links that drop the batch filter (the whole list) or the status.
test('memberTabs links each status to the Jobs list of the batch', () => {
    const tabs = memberTabs({
        pending: {count: 124, at_least: false},
        running: {count: 4, at_least: false},
        completed: {count: 1000, at_least: true},
        silenced: {count: 0, at_least: false},
        failed: {count: 7, at_least: false},
        retrying: {count: 2, at_least: false},
        unknown: {count: null, at_least: false},
    }, 'b-1');

    assert.deepEqual(tabs.map(tab => tab.key), [...MEMBER_TABS]);
    assert.deepEqual(tabs.map(tab => tab.count), ['124', '4', 'at least 1,000', '0', '7', '2', '—']);
    assert.deepEqual(tabs.map(tab => tab.unknown), [false, false, false, false, false, false, true]);
    assert.deepEqual(tabs.filter(tab => tab.danger).map(tab => tab.key), ['failed']);
    assert.deepEqual(tabs[4].to, {path: '/jobs', query: {status: 'failed', batch: 'b-1'}});
    assert.deepEqual(memberTabs(null, 'b-1').map(tab => tab.count), MEMBER_TABS.map(() => '—'));
    assert.equal(memberTabs({failed: {count: 0, at_least: false}}, 'b-1')[4].danger, false);
    assert.match(MEMBERS_CAPTION, /Laravel's/);
});

// Rejects: a retry button without the operate ability, with a read-only failed-record driver, or for a batch known to have no failed member; and one hidden when the count is unknown.
test('retryState shows the retry only when it can act', () => {
    const members = count => ({failed: {count, at_least: false}});

    assert.deepEqual(retryState(true, members(3)), {visible: true, readOnlyText: null});
    assert.deepEqual(retryState(false, members(3)), {visible: false, readOnlyText: null});
    assert.deepEqual(retryState(true, members(0)), {visible: false, readOnlyText: null});
    assert.deepEqual(retryState(true, {failed: {count: 0, at_least: true}}).visible, true);
    assert.deepEqual(retryState(true, members(null)).visible, true);
    assert.deepEqual(retryState(true, null).visible, true);
    assert.deepEqual(retryState(true, members(3), {readOnly: {reason: 'provider_read_only', message: 'Read-only.'}}), {visible: false, readOnlyText: 'Read-only.'});
    assert.deepEqual(retryState(false, members(3), {readOnly: {message: 'Read-only.'}}), {visible: false, readOnlyText: null});
});

// Rejects: a list source for a batch (a list source cannot carry a batch id) and one stored run for every batch (another batch's run recovered here).
test('batchSource and bulkStorageKey are per batch', () => {
    assert.deepEqual(batchSource('b-1'), {kind: 'batch', batch_id: 'b-1'});
    assert.notEqual(bulkStorageKey('b-1'), bulkStorageKey('b-2'));
    assert.equal(bulkStorageKey('b-1'), 'orreryBulkRun:batch:b-1');
});

// Rejects: an empty `query` or `before_id` sent (the server reads an empty search), a cursor on a short last page, and a search longer than the server accepts.
test('listParams, olderCursor and queryFromRoute build the list requests', () => {
    assert.deepEqual(listParams(''), {});
    assert.deepEqual(listParams('inv', 'b-9'), {query: 'inv', before_id: 'b-9'});
    assert.deepEqual(listParams(null, ''), {});

    const page = count => ({batches: Array.from({length: count}, (_, index) => ({id: `b-${index}`}))});

    assert.equal(olderCursor(page(PAGE_SIZE)), `b-${PAGE_SIZE - 1}`);
    assert.equal(olderCursor(page(PAGE_SIZE - 1)), null);
    assert.equal(olderCursor({batches: null}), null);
    assert.equal(queryFromRoute({query: 'inv'}), 'inv');
    assert.equal(queryFromRoute({}), '');
    assert.equal([...queryFromRoute({query: 'ž'.repeat(300)})].length, QUERY_MAX);
});

// Rejects: unknown batches shown as an empty list, a missing table shown as "No batches", and a search's empty result without the search.
test('listState tells unknown, unavailable, empty and rows apart', () => {
    assert.equal(listState(null).kind, 'loading');
    assert.deepEqual(listState({batches: null, unknown_reason: 'unsupported_repository'}).kind, 'unknown');
    assert.match(listState({batches: null, unknown_reason: 'unsupported_repository'}).text, /database batch repository/);
    assert.match(listState({batches: null, unknown_reason: 'sqlite_reads_not_allowed'}).text, /SQLite file/);
    assert.equal(listState({batches: [], available: false}).kind, 'unavailable');
    assert.deepEqual(listState({batches: [], available: true}, '').title, 'No batches');
    assert.deepEqual(listState({batches: [], available: true}, 'inv').title, 'No batch matches this search');
    assert.equal(listState({batches: [batch()], available: true}).kind, 'rows');
    assert.deepEqual(listState({batches: [], available: true}, '', {paged: true}), {kind: 'empty', title: 'No older batches', text: 'No batch is older than the last one shown.'});
    assert.doesNotMatch(listState({batches: [], available: true}).text, /job_batches/);
    assert.match(unknownReasonText(null), /did not say why/);
    assert.match(unknownReasonText('something_new'), /something_new/);
});

// Rejects: a 404 of the detail shown as a server error (polling on), a missing table read as "no longer kept", and a code shown raw.
test('readFailure words the read errors', () => {
    const failure = (status, error) => ({response: {status, data: error ? {error} : {}}});

    assert.deepEqual(readFailure(failure(404, 'not_found'), {detail: true}).kind, 'not_found');
    assert.equal(readFailure(failure(404, 'not_found'), {detail: true}).gone, true);
    assert.equal(readFailure(failure(404, 'batching_unavailable'), {detail: true}).kind, 'unavailable');
    assert.equal(readFailure(failure(503, 'read_timeout')).text, 'A read took too long. Narrow the selection and try again.');
    assert.equal(readFailure(failure(503, 'read_timeout')).gone, false);
    assert.equal(readFailure(failure(500)).text, 'The server answered with an error (HTTP 500). Try again.');
    assert.equal(readFailure({code: 'ERR_NETWORK'}, {detail: true}).text, 'The server did not answer. Try again.');
    // A body of the wrong shape is an unreadable answer, and it never reads as a gone batch.
    assert.equal(readFailure(new TypeError('x'), {detail: true}).text, "The server's answer could not be read. Reload the page; you may need to sign in again.");
    assert.equal(readFailure(new TypeError('x'), {detail: true}).gone, false);
    assert.equal(readFailure(failure(503, 'read_failed'), {detail: true}).title, 'The batch could not be loaded');
});

// Rejects: a BulkRun that unmounts (and so stops the run) when the batch read fails or the batch is gone while a run is shown, and one mounted for a viewer or without a batch.
test('bulkPanelMounted keeps the run panel through a failed batch read', () => {
    assert.equal(bulkPanelMounted({canOperate: true, batchId: 'b-1', batchKnown: false, running: true, shown: true}), true);
    assert.equal(bulkPanelMounted({canOperate: true, batchId: 'b-1', batchKnown: false, running: false, shown: true}), true);
    assert.equal(bulkPanelMounted({canOperate: true, batchId: 'b-1', batchKnown: true, running: false, shown: false}), true);
    assert.equal(bulkPanelMounted({canOperate: true, batchId: 'b-1', batchKnown: false, running: false, shown: false}), false);
    assert.equal(bulkPanelMounted({canOperate: false, batchId: 'b-1', batchKnown: true, running: true, shown: true}), false);
    assert.equal(bulkPanelMounted({canOperate: true, batchId: null, batchKnown: true, running: true, shown: true}), false);
});

// Rejects: a leave that stops a run without asking, a question for a change that keeps the batch (a search), and a second question after the operator confirmed.
test('leaveDecision asks only before a run would stop', () => {
    assert.equal(leaveDecision({sameBatch: false, running: true, confirmed: false}), 'ask');
    assert.equal(leaveDecision({sameBatch: true, running: true, confirmed: false}), 'allow');
    assert.equal(leaveDecision({sameBatch: false, running: false, confirmed: false}), 'allow');
    assert.equal(leaveDecision({sameBatch: false, running: true, confirmed: true}), 'allow');
});

// Rejects: a poll during a user read (two reads of one kind), a poll of a batch that is gone, and a detail poll without a batch.
test('pollReads gates the 10 s poll', () => {
    assert.deepEqual(pollReads({listLoading: false, batchId: 'b-1', detailLoading: false, detailGone: false}), {list: true, detail: true});
    assert.deepEqual(pollReads({listLoading: true, batchId: 'b-1', detailLoading: true, detailGone: false}), {list: false, detail: false});
    assert.deepEqual(pollReads({listLoading: false, batchId: 'b-1', detailLoading: false, detailGone: true}), {list: true, detail: false});
    assert.deepEqual(pollReads({listLoading: false, batchId: null, detailLoading: false, detailGone: false}), {list: true, detail: false});
});

// Rejects: a stale banner without the zone label (a bare clock), and one with an unknown time shown as a time.
test('staleText names the time with its zone', () => {
    assert.equal(staleText('batches', CREATED), 'Stale: the last refresh failed. Showing batches from 09:00:04 UTC.');
    assert.equal(staleText('batch', CREATED), 'Stale: the last refresh failed. Showing this batch as of 09:00:04 UTC.');
    assert.equal(staleText('batch', null), 'Stale: the last refresh failed. Showing this batch as of —.');
});

// Rejects: "—:emails" for an unknown connection, an unknown failed count shown as 0, and "allows failures" for a batch that does not allow them.
// Rejects: a pager that hides the way back to newer pages when an older page is empty, a pager over the loading skeleton or the unknown state of the first page, and batch content shown without a batch, after a failed read, or with no batch chosen.
test('pagerShown and contentShown decide the pager and the batch content', () => {
    assert.equal(pagerShown('rows', 0), true);
    assert.equal(pagerShown('empty', 0), false);
    assert.equal(pagerShown('empty', 2), true);
    assert.equal(pagerShown('loading', 2), false);
    assert.equal(pagerShown('unknown', 0), false);
    assert.equal(pagerShown('unavailable', 1), true);

    const batch = {id: 'b-1'};

    assert.equal(contentShown({batchId: 'b-1', batch, failure: null}), true);
    assert.equal(contentShown({batchId: null, batch, failure: null}), false);
    assert.equal(contentShown({batchId: 'b-1', batch: null, failure: null}), false);
    assert.equal(contentShown({batchId: 'b-1', batch, failure: {gone: false}}), false);
});

test('narrowParts gives the summary line below 768 px', () => {
    const view = options => ({connection: 'database', queue: 'emails', allowFailures: 'yes', ...options});

    assert.deepEqual(narrowParts(batch(), view({})).map(part => part.text), ['23 failed', '128 pending', 'database:emails', 'allows failures']);
    assert.deepEqual(narrowParts(batch(), view({connection: '—'})).map(part => part.text), ['23 failed', '128 pending', 'emails', 'allows failures']);
    assert.deepEqual(narrowParts(batch(), view({queue: '—', allowFailures: 'no'})).map(part => part.text), ['23 failed', '128 pending']);
    assert.deepEqual(narrowParts(batch({failed_jobs: null}), null).map(part => part.text), ['— failed', '128 pending']);
});

// Rejects: a row without its id (spec 5.2 lists the id), and a finished row without its creation time.
test('rowMeta gives the short id and the creation time beside the end', () => {
    assert.deepEqual(rowMeta(batch(), NOW), {id: '9c4b…7e44', title: '9c4b2a7e-31f0-4d8a-b6e2-5f1a0c9d7e44', created: null});
    assert.deepEqual(rowMeta(batch({finished_at: CREATED + 60}), NOW).created, 'created 09:00');
    assert.deepEqual(rowMeta(batch({cancelled_at: CREATED + 60}), NOW).created, 'created 09:00');
});

// Rejects: "Yesterday" from the browser's day while orrery.display_timezone is set (the test runs in UTC; in
// Tokyo, 9 h ahead, both times fall on 8 November).
test('shortTime compares the days of the display time zone', () => {
    const before = globalThis.Orrery;

    globalThis.Orrery = {displayTimezone: 'Asia/Tokyo'};

    try {
        assert.equal(shortTime(CREATED - 35000, NOW), '08:16');
        assert.equal(shortTime(CREATED - 86400 * 3, NOW), '5 Nov 18:00');
    } finally {
        if (before === undefined) delete globalThis.Orrery;
        else globalThis.Orrery = before;
    }
});

// Rejects: a list time that follows the toolbar's "times in" zone across a daylight-saving change (Vilnius,
// 2026-10-25: 03:30 is GMT+3, the note at 14:00 says GMT+2).
test('shortTime names the zone of a row whose offset differs from now', () => {
    const now = Date.UTC(2026, 9, 25, 12, 0) / 1000;
    globalThis.Orrery = {displayTimezone: 'Europe/Vilnius'};

    try {
        assert.equal(shortTime(Date.UTC(2026, 9, 25, 0, 30) / 1000, now), '03:30 GMT+3');
        assert.equal(shortTime(Date.UTC(2026, 9, 25, 9, 0) / 1000, now), '11:00');
        assert.equal(shortTime(Date.UTC(2026, 9, 24, 9, 0) / 1000, now), 'Yesterday 12:00 GMT+3');
    } finally {
        delete globalThis.Orrery;
    }
});

// Rejects: a Cancel offered to a viewer, on a finished or a cancelled batch, or without a batch.
test('cancelVisible offers Cancel on an open batch only', () => {
    const open = {id: 'b-1', name: 'Invoices', pending_jobs: 3, failed_jobs: 0, cancelled_at: null, finished_at: null};
    assert.equal(cancelVisible(true, open), true);
    assert.equal(cancelVisible(true, {...open, pending_jobs: 1, failed_jobs: 1}), true, 'open with failures');
    assert.equal(cancelVisible(false, open), false);
    assert.equal(cancelVisible(true, {...open, finished_at: 1000}), false);
    assert.equal(cancelVisible(true, {...open, cancelled_at: 1000, finished_at: 1000}), false);
    assert.equal(cancelVisible(true, null), false);
});

// Rejects: a sheet without the spec's text (which jobs skip, which still run), a selection that is not the batch
// id of the page read, and a cancel path that does not encode the id.
test('cancelSheet freezes the batch id and says what a cancel does', () => {
    const sheet = cancelSheet({id: 'b-1/x', name: 'Invoices'});
    assert.equal(sheet.title, 'Cancel this batch?');
    assert.ok(sheet.message.includes(CANCEL_TEXT));
    assert.match(CANCEL_TEXT, /^Jobs that check the batch \(the SkipIfBatchCancelled middleware or `batch\(\)->cancelled\(\)`\) skip their work\. Other jobs still run\. Running jobs finish\. The batch is marked finished\.$/);
    assert.match(sheet.message, /^Invoices · /);
    assert.equal(sheet.tone, 'danger');
    assert.deepEqual(sheet.selection, {action: 'cancel', id: 'b-1/x'});
    assert.equal(cancelUrl('b-1/x'), '/api/batches/b-1%2Fx/cancel');
    assert.match(cancelSheet({id: 'b-2', name: null}).message, /^Unnamed batch · /);
});

// Rejects: a refusal shown as success or as an unknown outcome, an answer without its result shown as success, and
// a 403 that the screen words itself (the global alert does).
test('cancelOutcome and cancelFailure word every answer', () => {
    assert.equal(cancelOutcome({id: 'b-1', result: 'cancelled', cancelled_at: 1000}).tone, 'success');
    assert.equal(cancelOutcome({}).title, 'Outcome unknown');
    assert.equal(cancelFailure({response: {status: 409, data: {error: 'already_cancelled'}}}).title, 'Not cancelled');
    assert.match(cancelFailure({response: {status: 409, data: {error: 'already_finished'}}}).detail, /finished/);
    assert.match(cancelFailure({response: {status: 404, data: {error: 'not_found'}}}).detail, /^Not found/);
    assert.equal(cancelFailure({response: {status: 403, data: {error: 'forbidden_action'}}}), null);
    assert.equal(cancelFailure({response: {status: 503, data: {error: 'write_failed'}}}).title, 'Outcome unknown');
    assert.equal(cancelFailure({}).title, 'Outcome unknown');
});
