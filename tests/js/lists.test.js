// Tests of resources/js/support/lists.js: the client rules of the list
// engine. `npm test` runs them with TZ=UTC and
// LANG=en_US.UTF-8, so the grouping of counts is stable.

import {test} from 'node:test';
import assert from 'node:assert/strict';

import {
    LIST_TABS,
    PER_PAGE_CHOICES,
    cappedCount,
    filterKey,
    filtersFromQuery,
    queryProblem,
    isUuid,
    listEndpoint,
    listParams,
    newEntriesAction,
    pagerActions,
    pagerLabels,
    pageRows,
    phpTrim,
    pagerRequest,
    pagerSummary,
    qProblem,
    queryFromFilters,
    searchLimitedText,
    tabItems,
    totalText,
} from '../../resources/js/support/lists.js';

const UUID = '0b6f1c9e-3b8a-4d2f-9a51-7c2e4b1d9f00';

// Rejects: a uuid check that accepts a prefix, extra characters or another shape, and one that refuses upper case (the server accepts any case).
test('isUuid accepts exactly a full uuid in any case', () => {
    assert.ok(isUuid(UUID));
    assert.ok(isUuid(UUID.toUpperCase()));
    assert.ok(!isUuid(UUID.slice(0, 8)));
    assert.ok(!isUuid(UUID + 'a'));
    assert.ok(!isUuid(' ' + UUID));
    assert.ok(!isUuid('0b6f1c9e3b8a4d2f9a517c2e4b1d9f00'));
    assert.ok(!isUuid(null));
    assert.ok(!isUuid(42));
});

// Rejects: tabs without retrying or stuck, and another order.
test('the tabs are the eight list tabs in order', () => {
    assert.deepEqual(LIST_TABS, ['pending', 'running', 'completed', 'silenced', 'failed', 'retrying', 'unknown', 'stuck']);
});

// Rejects: reading an array query value as a filter (tag[]=x), keeping a queue without its connection (the server answers 422), a non-numeric time, an unknown tab, and a cursor taken from the URL (a reload starts at the first page).
test('filtersFromQuery reads the tab and the filters from the URL', () => {
    assert.deepEqual(filtersFromQuery({}), {
        status: 'pending', connection: null, queue: null, class: null, tag: null, batch: null, from: null, to: null, q: null, order: 'desc',
    });

    assert.deepEqual(filtersFromQuery({
        status: 'failed', connection: 'database', queue: 'emails', class: 'App\\Jobs\\Send', tag: 'user:1', batch: 'b-1',
        from: '1762600000', to: '1762603600.5', q: '  invoice  ', order: 'asc', cursor: 'abc',
    }), {
        status: 'failed', connection: 'database', queue: 'emails', class: 'App\\Jobs\\Send', tag: 'user:1', batch: 'b-1',
        from: 1762600000, to: 1762603600.5, q: 'invoice', order: 'asc',
    });

    const odd = filtersFromQuery({status: 'everything', queue: 'emails', tag: ['x'], from: 'yesterday', to: '', order: 'sideways', q: '   '});
    assert.equal(odd.status, 'pending');
    assert.equal(odd.queue, null, 'a queue needs its connection');
    assert.equal(odd.tag, null);
    assert.equal(odd.from, null);
    assert.equal(odd.to, null);
    assert.equal(odd.order, 'desc');
    assert.equal(odd.q, null);
    assert.equal(filtersFromQuery(null).status, 'pending');
    assert.equal(filtersFromQuery({status: 'stuck'}).status, 'stuck');
    assert.equal(filtersFromQuery({from: 'Infinity'}).from, null);
});

// Rejects: trimming the byte-exact filters (a queue "emails " is another queue than "emails"), and an empty text read as a filter.
test('filtersFromQuery keeps connection, queue, class and batch byte-exact', () => {
    const exact = filtersFromQuery({connection: ' db', queue: 'emails ', class: 'App\\Jobs\\Send ', batch: ' b-1 ', tag: ' user:1 '});

    assert.equal(exact.connection, ' db');
    assert.equal(exact.queue, 'emails ');
    assert.equal(exact.class, 'App\\Jobs\\Send ');
    assert.equal(exact.batch, ' b-1 ');
    assert.equal(exact.tag, 'user:1', 'a tag is normalised as stored, so it may be trimmed');
    assert.equal(filtersFromQuery({connection: 'db', queue: 'emails '}).queue, 'emails ');
    assert.equal(filtersFromQuery({connection: '', queue: 'q'}).connection, null);
    assert.equal(filtersFromQuery({connection: 'db', queue: ''}).queue, null);
    assert.equal(filtersFromQuery({batch: 'x'.repeat(256)}).batch, null, 'a batch id has at most 255 characters');
    assert.deepEqual(queryFromFilters(exact), {status: 'pending', connection: ' db', queue: 'emails ', class: 'App\\Jobs\\Send ', tag: 'user:1', batch: ' b-1 '});
});

// Rejects: a URL that carries the defaults or empty values, a URL that drops the tab, and one that carries a cursor.
test('queryFromFilters writes only the set filters, and the tab', () => {
    assert.deepEqual(queryFromFilters(filtersFromQuery({})), {status: 'pending'});
    assert.deepEqual(queryFromFilters({status: 'failed', connection: 'db', queue: 'q', class: null, tag: '', from: 1, to: 2.5, q: 'x', order: 'asc', cursor: 'c'}), {
        status: 'failed', connection: 'db', queue: 'q', from: '1', to: '2.5', q: 'x', order: 'asc',
    });

    const round = {status: 'completed', connection: 'db', queue: 'high', class: 'A', tag: 't', batch: 'b', from: 10, to: 20, q: 'abc', order: 'desc'};
    assert.deepEqual(filtersFromQuery(queryFromFilters(round)), round);
});

// Rejects: a stuck tab read from /api/jobs (it has its own endpoint), and sending status=stuck there.
test('listEndpoint and listParams build the request of a tab', () => {
    assert.equal(listEndpoint('failed'), '/api/jobs');
    assert.equal(listEndpoint('stuck'), '/api/jobs/stuck');

    assert.deepEqual(listParams({status: 'failed', connection: 'db', queue: 'q', class: null, tag: null, from: 5, to: null, q: null, order: 'desc'}), {
        status: 'failed', connection: 'db', queue: 'q', from: 5,
    });
    assert.deepEqual(listParams({status: 'running', order: 'asc'}, {cursor: 'c1', perPage: 25}), {status: 'running', order: 'asc', cursor: 'c1', per_page: 25});
    assert.deepEqual(listParams({status: 'pending'}, {newerThan: 'h1'}), {status: 'pending', newer_than: 'h1'});
    assert.deepEqual(listParams({status: 'stuck', connection: 'db'}), {connection: 'db'});
    assert.deepEqual(listParams({status: 'failed', batch: 'b-1'}), {status: 'failed', batch: 'b-1'});
});

// Rejects: a stuck request that sends filters its endpoint does not take (tag, batch, times, q, order), and one that drops the signal sub-tab.
test('listParams sends the stuck tab its signal and only connection, queue and class', () => {
    const filters = {status: 'stuck', connection: 'db', queue: 'q', class: 'A', tag: 't', batch: 'b', from: 1, to: 2, q: 'abc', order: 'asc'};

    assert.deepEqual(listParams(filters, {signal: 'releases', cursor: 'c', perPage: 10}), {signal: 'releases', connection: 'db', queue: 'q', class: 'A', cursor: 'c', per_page: 10});
    assert.deepEqual(listParams({status: 'stuck'}), {});
    assert.deepEqual(listParams({status: 'failed', connection: 'db'}, {signal: 'releases'}), {status: 'failed', connection: 'db'}, 'only the stuck tab takes a signal');
});

// Rejects: a filter key that ignores the tab, the order or a filter (a cursor of another list would be sent and answer cursor_mismatch), and one that depends on the property order.
test('filterKey changes with every filter, the tab and the order', () => {
    const base = filtersFromQuery({status: 'failed', connection: 'db', queue: 'q'});
    const key = filterKey(base);

    assert.equal(filterKey({...base}), key);
    assert.equal(filterKey(Object.fromEntries(Object.entries(base).reverse())), key);

    for (const change of [{batch: 'b-1'}, {status: 'retrying'}, {order: 'asc'}, {queue: 'r'}, {class: 'A'}, {tag: 't'}, {from: 1}, {to: 2}, {q: 'abc'}, {connection: 'other'}]) {
        assert.notEqual(filterKey({...base, ...change}), key, JSON.stringify(change));
    }
});

// Rejects: a null count shown as 0, a capped count without its "+", and a total that hides "at least".
test('cappedCount and totalText never show unknown as zero and mark a capped count', () => {
    assert.equal(cappedCount({count: 1000000, at_least: true}), '1,000,000+');
    assert.equal(cappedCount({count: 12, at_least: false}), '12');
    assert.equal(cappedCount({count: 0, at_least: false}), '0');
    assert.equal(cappedCount({count: null, at_least: false}), '—');
    assert.equal(cappedCount(null), '—');
    assert.equal(cappedCount({count: '12', at_least: false}), '—', 'a count is a number');

    assert.equal(totalText({count: 10000, at_least: true}), 'at least 10,000');
    assert.equal(totalText({count: 3, at_least: false}), '3');
    assert.equal(totalText({count: null, at_least: false}), '—');
    assert.equal(totalText(undefined), '—');
});

// Rejects: a page without the budget notice when the scan stopped, and a notice when it did not.
test('searchLimitedText says how far the search went', () => {
    assert.equal(searchLimitedText({search_limited: true}, 50000), 'Searched the newest 50,000 rows in this order. Narrow the search with a time range.');
    assert.equal(searchLimitedText({search_limited: true, order: 'desc', prev_cursor: null}, 50000), 'Searched the newest 50,000 rows in this order. Narrow the search with a time range.');
    assert.equal(searchLimitedText({search_limited: true, order: 'asc', prev_cursor: null}, 50000), 'Searched the oldest 50,000 rows in this order. Narrow the search with a time range.');
    assert.equal(searchLimitedText({search_limited: true, order: 'desc', prev_cursor: 'p'}, 50000), 'Searched 50,000 more rows in this order. Narrow the search with a time range.');
    assert.equal(searchLimitedText({search_limited: true}), 'Searched only part of the rows in this order. Narrow the search with a time range.');
    assert.equal(searchLimitedText({search_limited: false}, 50000), null);
    assert.equal(searchLimitedText(null, 50000), null);
});

// Rejects: a pager that offers Newer on the first page or Older at the end of the range.
test('pagerActions follows the cursors of the response', () => {
    assert.deepEqual(pagerActions({prev_cursor: null, next_cursor: 'n'}), {newest: false, newer: false, older: true, oldest: true});
    assert.deepEqual(pagerActions({prev_cursor: 'p', next_cursor: null}), {newest: true, newer: true, older: false, oldest: false});
    assert.deepEqual(pagerActions({prev_cursor: 'p', next_cursor: 'n'}), {newest: true, newer: true, older: true, oldest: true});
    assert.deepEqual(pagerActions(null), {newest: false, newer: false, older: false, oldest: false});

    // Oldest first: the earlier pages hold older rows, so the labels swap.
    assert.deepEqual(pagerActions({prev_cursor: null, next_cursor: 'n'}, 'asc'), {newest: true, newer: true, older: false, oldest: false});
    // An ascending read is never the newest page: Newest reads the order desc.
    assert.deepEqual(pagerActions({prev_cursor: 'p', next_cursor: null}, 'asc'), {newest: true, newer: false, older: true, oldest: true});
    assert.deepEqual(pagerActions({prev_cursor: 'p', next_cursor: null, order: 'asc'}), {newest: true, newer: false, older: true, oldest: true}, 'the order of the response');
});

// Rejects: a banner for an unknown has_newer (null), a banner while the auto-load toggle reloads instead, and a reload on a list without new rows.
test('newEntriesAction shows the banner or reloads only when has_newer is true', () => {
    assert.equal(newEntriesAction({has_newer: true}, false), 'banner');
    assert.equal(newEntriesAction({has_newer: true}, true), 'reload');
    assert.equal(newEntriesAction({has_newer: false}, false), null);
    assert.equal(newEntriesAction({has_newer: null}, true), null);
    assert.equal(newEntriesAction(null, true), null);
});

// Rejects: a search that sends a two-character text (the server answers 422), a refused uuid, and a limit off by one.
test('qProblem checks the search text as the server does', () => {
    assert.equal(qProblem(null), null);
    assert.equal(qProblem(''), null);
    assert.equal(qProblem(UUID), null);
    assert.equal(qProblem('abc'), null);
    assert.equal(qProblem('a'.repeat(100)), null);
    assert.equal(qProblem('ab'), 'Type at least 3 characters.');
    assert.equal(qProblem('a'.repeat(101)), 'Type at most 100 characters.');
    assert.equal(qProblem('  ab  '), 'Type at least 3 characters.');
    assert.equal(qProblem('😀'.repeat(100)), null, 'characters, not UTF-16 units');
    assert.equal(qProblem('😀'.repeat(101)), 'Type at most 100 characters.');
});

// Rejects: a pager that keeps the old order for Newest or Oldest, that follows the wrong cursor in an ascending (Oldest) page, that pages from the current page instead of its cursor, and that asks the server when the button has no cursor.
test('pagerRequest maps each pager button to the request of its page', () => {
    const desc = {order: 'desc', response: {prev_cursor: 'p', next_cursor: 'n'}};
    const asc = {order: 'asc', response: {prev_cursor: 'p', next_cursor: 'n'}};

    assert.deepEqual(pagerRequest('newest', asc), {order: 'desc', cursor: null});
    assert.deepEqual(pagerRequest('oldest', desc), {order: 'asc', cursor: null});
    assert.deepEqual(pagerRequest('newer', desc), {order: 'desc', cursor: 'p'});
    assert.deepEqual(pagerRequest('older', desc), {order: 'desc', cursor: 'n'});
    assert.deepEqual(pagerRequest('newer', asc), {order: 'asc', cursor: 'n'});
    assert.deepEqual(pagerRequest('older', asc), {order: 'asc', cursor: 'p'});
    assert.equal(pagerRequest('newer', {order: 'desc', response: {prev_cursor: null, next_cursor: 'n'}}), null);
    assert.equal(pagerRequest('older', {order: 'asc', response: {prev_cursor: null}}), null);
    assert.equal(pagerRequest('sideways', desc), null);
    assert.equal(pagerRequest('newer', null), null);
});

// Rejects: an Oldest page (read ascending) shown oldest first in a newest-first list, rows reversed when the orders agree, and a reversal that changes the response.
test('pageRows shows a page in the display order', () => {
    const rows = [{id: 1}, {id: 2}, {id: 3}];

    assert.deepEqual(pageRows(rows, 'asc', 'desc').map(row => row.id), [3, 2, 1]);
    assert.deepEqual(pageRows(rows, 'desc', 'desc').map(row => row.id), [1, 2, 3]);
    assert.deepEqual(pageRows(rows, 'desc', 'asc').map(row => row.id), [3, 2, 1]);
    assert.deepEqual(rows.map(row => row.id), [1, 2, 3]);
    assert.deepEqual(pageRows(null, 'asc', 'desc'), []);
});

// Rejects: a page position written as a page number (a keyset page has none), an unknown total shown as 0, a capped total without "at least", and "All shown" for a capped total.
test('pagerSummary says where the page is and how many rows it shows', () => {
    const first = {newest: false, newer: false, older: true, oldest: true};
    const middle = {newest: true, newer: true, older: true, oldest: true};
    const last = {newest: true, newer: true, older: false, oldest: false};
    const only = {newest: false, newer: false, older: false, oldest: false};

    assert.equal(pagerSummary({rows: 50, total: {count: 86, at_least: false}, actions: first}), 'First page · 50 of 86 shown');
    assert.equal(pagerSummary({rows: 50, total: {count: 86, at_least: false}, actions: first, short: true}), 'First page · 50 of 86');
    assert.equal(pagerSummary({rows: 36, total: {count: 86, at_least: false}, actions: last}), 'Last page · 36 of 86 shown');
    assert.equal(pagerSummary({rows: 50, total: {count: 10000, at_least: true}, actions: middle}), '50 of at least 10,000 shown');
    assert.equal(pagerSummary({rows: 50, total: {count: null, at_least: false}, actions: first}), 'First page · 50 shown · total unknown');
    assert.equal(pagerSummary({rows: 12, total: {count: 12, at_least: false}, actions: only}), 'All 12 shown');
    assert.equal(pagerSummary({rows: 12, total: {count: 12, at_least: true}, actions: only}), 'First page · 12 of at least 12 shown');
    // In an oldest-first list the first page is the oldest one.
    assert.equal(pagerSummary({rows: 50, total: {count: 86, at_least: false}, actions: last, displayOrder: 'asc'}), 'First page · 50 of 86 shown');
});

// Rejects: a per-page choice outside the server's 1–100.
test('PER_PAGE_CHOICES stay within the server limit', () => {
    assert.deepEqual([...PER_PAGE_CHOICES], [25, 50, 100]);
});

// Rejects: a Retrying tab hidden while its count is unknown, a hidden tab that is open, an unknown count shown as 0, and a Stuck count from /api/counts (it has none).
test('tabItems gives the tabs with their global counts', () => {
    const counts = {
        pending: {count: 10216, at_least: false}, running: {count: 14, at_least: false}, completed: {count: 1000000, at_least: true},
        silenced: {count: 0, at_least: false}, failed: {count: null, at_least: false}, retrying: {count: 0, at_least: false},
    };
    const items = tabItems(counts, 'pending');

    assert.deepEqual(items.map(item => item.key), ['pending', 'running', 'completed', 'silenced', 'failed', 'unknown', 'stuck']);
    assert.deepEqual(items.map(item => item.count), ['10,216', '14', '1,000,000+', '0', '—', '—', null]);
    assert.deepEqual(items.map(item => item.label), ['Pending', 'Running', 'Completed', 'Silenced', 'Failed', 'Unknown', 'Stuck']);
    assert.equal(items[0].active, true);

    assert.ok(tabItems({...counts, retrying: {count: 2, at_least: false}}, 'pending').some(item => item.key === 'retrying'));
    assert.ok(tabItems({...counts, retrying: {count: null, at_least: false}}, 'pending').some(item => item.key === 'retrying'));
    assert.ok(tabItems(counts, 'retrying').some(item => item.key === 'retrying' && item.active));
    assert.ok(tabItems(null, 'pending').some(item => item.key === 'retrying'));
    // The Stuck tab has no count of its own: the list total counts only the open signal sub-tab.
    assert.equal(tabItems(counts, 'stuck').find(item => item.key === 'stuck').count, null);
});

// Rejects: a trim that strips characters PHP's trim() keeps (a no-break space, an ideographic space, a byte order mark), so the client would ask for another tag or search than the server stores and reads.
test('phpTrim trims only the characters of PHP trim()', () => {
    assert.equal(phpTrim(' region\t'), 'region');
    assert.equal(phpTrim('\0\x0B\r\n region \n'), 'region');
    assert.equal(phpTrim('region\u00a0'), 'region\u00a0');
    assert.equal(phpTrim('\u3000region'), '\u3000region');
    assert.equal(phpTrim('\ufeffregion'), '\ufeffregion');
    assert.equal(phpTrim(null), null);
});

// Rejects: a tag or a search text of the URL trimmed with JavaScript's trim() instead of PHP's.
test('filtersFromQuery trims the tag and the search text as PHP does', () => {
    assert.equal(filtersFromQuery({tag: 'region\u00a0'}).tag, 'region\u00a0');
    assert.equal(filtersFromQuery({tag: ' region\t'}).tag, 'region');
    assert.equal(filtersFromQuery({q: '\u3000mail'}).q, '\u3000mail');
    assert.equal(filtersFromQuery({q: ' \t '}).q, null);
});

// Rejects: "Newer"/"Older" on a pager without Newest and Oldest (a list in one fixed order, read by a count, has no newer or older rows), and the time labels lost on a normal list.
test('pagerLabels names the buttons of the pager', () => {
    assert.deepEqual(pagerLabels(true), {newer: 'Newer', older: 'Older'});
    assert.deepEqual(pagerLabels(false), {newer: 'Previous', older: 'Next'});
});

// Rejects: a link filter that is dropped in silence (a queue without its connection, a batch id the server
// refuses, a time that is not an epoch, a repeated key) so the list and a bulk run widen to every job, and
// a problem for a valid link or for an empty value.
test('queryProblem names a URL filter that the screen cannot apply', () => {
    assert.equal(queryProblem({}), null);
    assert.equal(queryProblem(null), null);
    assert.equal(queryProblem({status: 'failed', connection: 'database', queue: 'default', from: '1762600000', to: ''}), null);
    assert.equal(queryProblem({status: 'everything', order: 'sideways', q: '   '}), null);

    assert.deepEqual(queryProblem({status: 'failed', queue: 'database:default'}),
        {code: 'queue_needs_connection', field: 'queue', values: {queue: 'database:default'}});
    assert.deepEqual(queryProblem({batch: 'b'.repeat(256)}), {code: 'filter_invalid', field: 'batch', values: {field: 'batch'}});
    assert.equal(queryProblem({batch: 'b'.repeat(255)}), null);
    assert.deepEqual(queryProblem({from: 'yesterday'}), {code: 'filter_invalid', field: 'from', values: {field: 'from'}});
    assert.deepEqual(queryProblem({to: 'Infinity'}), {code: 'filter_invalid', field: 'to', values: {field: 'to'}});
    assert.deepEqual(queryProblem({tag: ['a', 'b']}), {code: 'filter_invalid', field: 'tag', values: {field: 'tag'}});
    assert.deepEqual(queryProblem({connection: 'database', queue: ['a', 'b']}), {code: 'filter_invalid', field: 'queue', values: {field: 'queue'}});
});
