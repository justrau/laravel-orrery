// Tests of resources/js/screens/tags/tags.js: the rules of the Tags screen
// (with the Stop
// monitoring sheet). `pnpm test` runs them with TZ=UTC and LANG=en_US.UTF-8.

import {test} from 'node:test';
import assert from 'node:assert/strict';

import {
    STOP_SHEET_MESSAGE,
    afterMonitor,
    jobsLink,
    monitorChip,
    monitorRequest,
    monitoredRows,
    monitoredNotes,
    readFailure,
    routeTag,
    stopSheet,
    tabFromQuery,
    tabItems,
    tagInputProblem,
    tagListRequest,
    tagRow,
    tagRowsEmpty,
    tagsSubtitle,
    unmonitorRequest,
    viewLookup,
    writeFailure,
    writeResult,
} from '../../resources/js/screens/tags/tags.js';

const NBSP = ' ';
const NOW = Date.UTC(2026, 10, 9, 12, 0, 0) / 1000;




// Rejects: a JS trim that removes a no-break space (the server keeps it), a
// tag read from a non-string query value (`tag[]=x`), a blank tag treated as
// a tag, and a NUL accepted (the server refuses it).
test('routeTag() reads the tag of the path or the query, trimmed only as PHP trims', () => {
    assert.equal(routeTag({}, {}), null);
    assert.equal(routeTag({}, {tag: ['x']}), null);
    assert.deepEqual(routeTag({tag: ' a' + NBSP + ' '}, {}), {tag: 'a' + NBSP, valid: true});
    assert.deepEqual(routeTag({}, {tag: '..'}), {tag: '..', valid: true});
    assert.deepEqual(routeTag({tag: 'p'}, {tag: 'q'}), {tag: 'p', valid: true});
    assert.deepEqual(routeTag({}, {tag: ' \t'}), {tag: '', valid: false});
    assert.deepEqual(routeTag({tag: 'a\0b'}, {}), {tag: 'a\0b', valid: false});
    assert.equal(routeTag({}, {tag: 'a\0'}).valid, false);
});

// Rejects: an unknown tab value read as the failed tab.
test('tabFromQuery() reads the failed tab only from tab=failed', () => {
    assert.equal(tabFromQuery({tab: 'failed'}), 'failed');
    assert.equal(tabFromQuery({tab: 'FAILED'}), 'all');
    assert.equal(tabFromQuery({}), 'all');
    assert.equal(tabFromQuery(null), 'all');
});

// Rejects: a failed count shown as 0 when it could not be read, a capped
// count without "+", and a failed count in red when it is 0.
test('monitoredRows() shows capped counts, "—" for unknown, red only above 0', () => {
    const rows = monitoredRows({tags: [
        {tag: 'tenant:acme-eu', count: {count: 1000, at_least: true}, failed: {count: 3, at_least: false}},
        {tag: 'list:newsletter', count: {count: 418, at_least: false}, failed: {count: null, at_least: false}},
        {tag: 'quiet', count: {count: 0, at_least: false}, failed: {count: 0, at_least: false}},
        {tag: 'lost', count: null, failed: null},
        {count: {count: 1, at_least: false}},
    ], truncated: false});

    assert.deepEqual(rows.map(row => [row.tag, row.jobs, row.failed, row.failedTone]), [
        ['tenant:acme-eu', '1,000+', '3', 'danger'],
        ['list:newsletter', '418', '—', 'muted'],
        ['quiet', '0', '0', 'muted'],
        ['lost', '—', '—', 'muted'],
    ]);
    assert.deepEqual(monitoredRows(null), []);
});

// Rejects: a truncated list that does not say so, and an empty list that
// tells a viewer to monitor a tag.
test('monitoredNotes() names the truncation and the empty state per ability', () => {
    assert.equal(monitoredNotes({tags: [], truncated: true}, true).truncated, 'Showing the first 100 monitored tags.');
    assert.equal(monitoredNotes({tags: [{tag: 'a'}], truncated: false}, true).truncated, null);
    assert.equal(monitoredNotes({tags: [], truncated: false}, true).empty, 'No tags are monitored. Type a tag above and choose Monitor to keep its jobs longer.');
    assert.equal(monitoredNotes({tags: [], truncated: false}, false).empty, 'No tags are monitored.');
    assert.equal(monitoredNotes({tags: [{tag: 'a'}], truncated: false}, false).empty, null);
});

// Rejects: an unknown monitor state shown as "Not monitored" (unknown is
// never a definite answer), and a write button offered for an unknown state
// or to a viewer.
test('monitorChip() says monitored, not monitored or unknown, and which write applies', () => {
    assert.deepEqual(monitorChip({tag: 't', monitored: true}, true), {state: 'monitored', label: 'Monitored', action: 'stop'});
    assert.deepEqual(monitorChip({tag: 't', monitored: false}, true), {state: 'not_monitored', label: 'Not monitored', action: 'monitor'});
    assert.deepEqual(monitorChip({tag: 't', monitored: null}, true), {state: 'unknown', label: 'Monitor state unknown', action: null});
    assert.deepEqual(monitorChip(null, true), {state: 'unknown', label: 'Monitor state unknown', action: null});
    assert.equal(monitorChip({tag: 't', monitored: true}, false).action, null);
});

// Rejects: a client that sends a blank tag or a NUL, and one that refuses a
// tag that only has a no-break space at its end.
test('tagInputProblem() refuses only what the server refuses before a write', () => {
    assert.equal(tagInputProblem(''), 'Type a tag.');
    assert.equal(tagInputProblem(' \n'), 'Type a tag.');
    assert.equal(tagInputProblem(null), 'Type a tag.');
    assert.equal(tagInputProblem('a\0'), 'A tag cannot contain a NUL character.');
    assert.equal(tagInputProblem('x' + NBSP), null);
    assert.equal(tagInputProblem('..'), null);
});

// Rejects: an unmonitor through the path form (it cannot carry '.', '..' or
// '/'), and a body that trims the no-break space.
test('the write requests send the tag as stored: monitor in the body, unmonitor in the query', () => {
    assert.deepEqual(monitorRequest(' x' + NBSP + ' '), {method: 'post', url: '/api/tags/monitored', data: {tag: 'x' + NBSP}});
    assert.deepEqual(unmonitorRequest('..'), {method: 'delete', url: '/api/tags/monitored', params: {tag: '..'}});
    assert.deepEqual(unmonitorRequest('a/b' + NBSP), {method: 'delete', url: '/api/tags/monitored', params: {tag: 'a/b' + NBSP}});
});

// Rejects: a success text that ignores `changed`, a 503 that claims nothing
// was saved, and an error shown as a raw code.
test('writeResult() words each answer, and never claims a failed write did nothing', () => {
    assert.deepEqual(writeResult('monitor', {tag: 'a', monitored: true, changed: true}), {tone: 'success', text: 'Now monitoring “a”.'});
    assert.deepEqual(writeResult('monitor', {tag: 'a', monitored: true, changed: false}), {tone: 'success', text: '“a” was already monitored.'});
    assert.deepEqual(writeResult('stop', {tag: 'a', monitored: false, changed: true}), {tone: 'success', text: 'Stopped monitoring “a”.'});
    assert.deepEqual(writeResult('stop', {tag: 'a', monitored: false, changed: false}), {tone: 'success', text: '“a” was not monitored.'});

    const failed = writeResult('stop', null, {response: {status: 503, data: {error: 'write_failed'}}});

    assert.equal(failed.tone, 'error');
    assert.equal(failed.text, 'The change may not have been saved. Reload to see the current state; trying again is safe.');
    assert.equal(writeResult('monitor', null, {response: {status: 422, data: {error: 'invalid'}}}).text, 'The request was not valid: nothing changed. Reload the page and try again.');
    assert.equal(writeResult('monitor', null, {}).text, 'The server did not answer. The change may not have been saved; reload to see the current state.');
    assert.equal(writeResult('monitor', null, {response: {status: 500, data: {}}}).text, 'The server answered with an error (HTTP 500). The change may not have been saved; reload to see the current state.');
});

// Rejects: a write notice beside the global alert for a status the write does
// not handle (404, 405, 413, 429, an aborted request: errors.js shows them, so
// the operator would read the failure twice, and a request the screen
// cancelled, which is aborted and not "no answer") or for the session states (401,
// 403, 419), and a handled failure (a server error, a timeout, no answer)
// without its own "may not have been saved" notice.
test('writeFailure() words only the failures that the write handles itself', () => {
    for (const status of [422, 500, 502, 503, 504]) {
        assert.equal(writeFailure('stop', {response: {status, data: {}}}).tone, 'error', String(status));
    }

    assert.equal(writeFailure('monitor', {code: 'ECONNABORTED', message: 'timeout of 30000ms exceeded'}).text, 'The server did not answer. The change may not have been saved; reload to see the current state.');
    assert.equal(writeFailure('monitor', {code: 'ERR_NETWORK', message: 'Network Error'}).tone, 'error');
    assert.equal(writeFailure('monitor', null).tone, 'error');

    for (const status of [401, 403, 404, 405, 413, 419, 429]) {
        assert.equal(writeFailure('stop', {response: {status, data: {}}}), null, String(status));
    }

    assert.equal(writeFailure('monitor', {code: 'ECONNABORTED', message: 'Request aborted'}), null);
    // A request that the screen cancelled (a CanceledError of support/http.js) is aborted, never "no answer".
    assert.equal(writeFailure('monitor', {code: 'ERR_CANCELED', message: 'canceled', __CANCEL__: true}), null);
});

// Rejects: a sheet that computes a retention duration (decided 2026-11-09:
// a plain sentence and no extra read), and one that freezes another tag.
test('stopSheet() is the plain sentence of the Stop monitoring sheet', () => {
    const sheet = stopSheet('tenant:acme-eu');

    assert.equal(sheet.title, 'Stop monitoring “tenant:acme-eu”?');
    assert.equal(sheet.message, STOP_SHEET_MESSAGE);
    assert.match(STOP_SHEET_MESSAGE, /^Its jobs then follow the retention of their state instead of the monitored retention\./);
    assert.doesNotMatch(STOP_SHEET_MESSAGE, /\d/);
    assert.equal(sheet.confirmLabel, 'Stop monitoring');
    assert.deepEqual(sheet.selection, {tag: 'tenant:acme-eu'});
});

// Rejects: a tag view that reads a status tab (the All tab is status all),
// a trimmed tag, and a cursor or order of another page.
test('tagListRequest() reads the list engine with status all or failed and the exact tag', () => {
    assert.deepEqual(tagListRequest('x' + NBSP, 'all', {order: 'desc', cursor: null}, 50), {endpoint: '/api/jobs', params: {status: 'all', tag: 'x' + NBSP, per_page: 50}});
    assert.deepEqual(tagListRequest('..', 'failed', {order: 'asc', cursor: 'c1'}, 25), {endpoint: '/api/jobs', params: {status: 'failed', tag: '..', order: 'asc', cursor: 'c1', per_page: 25}});
    assert.deepEqual(tagListRequest('t', 'all', null, 1), {endpoint: '/api/jobs', params: {status: 'all', tag: 't', per_page: 1}});
});

// Rejects: tab counts shown as 0 while unknown, and a capped total without "+".
test('tabItems() carries the list totals of both tabs', () => {
    const items = tabItems('failed', {count: 10000, at_least: true}, {count: 3, at_least: false});

    assert.deepEqual(items, [
        {key: 'all', label: 'All', count: '10,000+', active: false, tone: null},
        {key: 'failed', label: 'Failed', count: '3', active: true, tone: 'danger'},
    ]);
    assert.deepEqual(tabItems('all', null, {count: 0, at_least: false}).map(item => [item.count, item.tone]), [['—', null], ['0', null]]);
});

// Rejects: a row of the All tab timed by its failure, a silenced job shown
// as Completed, and a row link to a job id that is not a uuid.
test('tagRow() shows the status chip, the tab time, the name and the queue', () => {
    const row = {id: '0B6F1C9E-3B8A-4D2F-9A51-7C2E4B1D9F00', name: 'App\\Jobs\\Sync', connection: 'database', queue: 'default', status: 'completed', silenced: true, pushed_at: NOW - 60, failed_at: null};

    const all = tagRow(row, 'all', NOW);

    assert.deepEqual(all.chip, {label: 'Silenced', tone: 'neutral'});
    assert.equal(all.time.primary, '11:59:00');
    assert.equal(all.queueTitle, 'database:default');
    assert.equal(all.link, '/jobs/0B6F1C9E-3B8A-4D2F-9A51-7C2E4B1D9F00');

    const failed = tagRow({...row, status: 'failed', silenced: false, failed_at: NOW - 3600}, 'failed', NOW);

    assert.deepEqual(failed.chip, {label: 'Failed', tone: 'danger'});
    assert.equal(failed.time.primary, '11:00:00');
    assert.deepEqual(tagRow({...row, status: 'reserved', silenced: false}, 'all', NOW).chip, {label: 'Running', tone: 'accent'});
    assert.equal(tagRow({...row, id: 'nope'}, 'all', NOW).link, null);
    assert.equal(tagRow({...row, pushed_at: null}, 'all', NOW).time.primary, '—');
});

// Rejects: an empty tab that blames filters (the tag view has none).
test('tagRowsEmpty() words the empty tab', () => {
    assert.equal(tagRowsEmpty('all'), 'No jobs with this tag are kept.');
    assert.equal(tagRowsEmpty('failed'), 'No failed jobs with this tag are kept.');
});

// Rejects: a Jobs link that trims the tag, or that opens the failed tab from All.
test('jobsLink() opens the Jobs list with the exact tag', () => {
    assert.deepEqual(jobsLink('x' + NBSP, 'all'), {path: '/jobs', query: {tag: 'x' + NBSP}});
    assert.deepEqual(jobsLink('..', 'failed'), {path: '/jobs', query: {status: 'failed', tag: '..'}});
});

// Rejects: a read failure shown as a raw code, and a timeout that reads as "no tags".
test('readFailure() words a failed read through the operator wording', () => {
    assert.equal(readFailure({response: {status: 503, data: {error: 'read_timeout'}}}), 'A read took too long. Narrow the selection and try again.');
    assert.equal(readFailure({response: {status: 503, data: {error: 'read_failed'}}}), "Orrery's storage could not be read. Try again.");
    assert.equal(readFailure({}), 'The server did not answer. Try again.');
    assert.equal(readFailure({response: {status: 502, data: '<html>'}}), 'The server answered with an error (HTTP 502). Try again.');
});

// Rejects: a chip that shows the previous tag's state (and its write
// button) while the new tag's lookup loads or after it fails, and one that
// compares with lookup.tag (a long tag comes back cut to its stored form).
test('viewLookup() keeps a lookup only for the tag it was asked for', () => {
    assert.deepEqual(monitorChip(viewLookup({lookup: {tag: 'A', monitored: true}}, 'A', 'B'), true), {state: 'unknown', label: 'Monitor state unknown', action: null});
    assert.deepEqual(viewLookup({lookup: {tag: 'A', monitored: true}}, 'A', 'A'), {tag: 'A', monitored: true});
    assert.deepEqual(viewLookup({lookup: {tag: 'cut…a1b2', monitored: false}}, 'long', 'long'), {tag: 'cut…a1b2', monitored: false});
    assert.equal(viewLookup({lookup: null}, 'A', 'A'), null);
    assert.equal(viewLookup(null, 'A', 'A'), null);
    assert.equal(viewLookup({lookup: {tag: 'A', monitored: true}}, null, null), null);
});

// Rejects: a monitor answer that navigates after the user left the screen
// or moved to another route while it was pending.
test('afterMonitor() opens the monitored tag only while the screen and its route stay', () => {
    assert.deepEqual(afterMonitor({tag: '..', monitored: true, changed: true}, {unmounted: false, routeChanged: false}), {path: '/tags', query: {tag: '..'}});
    assert.equal(afterMonitor({tag: 'a', monitored: true, changed: true}, {unmounted: true, routeChanged: false}), null);
    assert.equal(afterMonitor({tag: 'a', monitored: true, changed: true}, {unmounted: false, routeChanged: true}), null);
    assert.equal(afterMonitor(null, {unmounted: false, routeChanged: false}), null);
});

// Rejects: a toolbar without the zone of the tag view's row times (the rows show a bare clock).
test('tagsSubtitle names the zone of the row times', () => {
    assert.equal(tagsSubtitle(NOW), 'Monitored tags keep their jobs longer · any tag can be looked up · times in UTC');
});
