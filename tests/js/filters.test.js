// Tests of resources/js/support/filters.js: the rules of the shared filter
// bar (components/ui/FilterBar.vue). `pnpm test` runs them
// with TZ=UTC, so the local times are UTC.

import {test} from 'node:test';
import assert from 'node:assert/strict';

import {
    TIME_PRESETS,
    activeFilterCount,
    activePreset,
    clearedFilters,
    committedText,
    epochFromInput,
    filterSummary,
    inputFromEpoch,
    rangeZoneNote,
    mergeQuery,
    presetRange,
    queueOptions,
    rangeProblem,
    withFilters,
} from '../../resources/js/support/filters.js';

const NOW = 1762678800; // 2025-11-09 09:00:00 UTC

function filters(overrides = {}) {
    return {status: 'failed', connection: null, queue: null, class: null, tag: null, batch: null, from: null, to: null, q: null, order: 'desc', ...overrides};
}

// Rejects: a preset that sets `to` (the list would stop growing at the click), a preset counted from another time than now, and an "All" that keeps an old range.
test('presetRange sets from = now − preset and no end', () => {
    assert.deepEqual(TIME_PRESETS.map(preset => preset.key), ['1h', '24h', '7d']);
    assert.deepEqual(presetRange('1h', NOW), {from: NOW - 3600, to: null});
    assert.deepEqual(presetRange('24h', NOW), {from: NOW - 86400, to: null});
    assert.deepEqual(presetRange('7d', NOW), {from: NOW - 604800, to: null});
    assert.deepEqual(presetRange('all', NOW), {from: null, to: null});
    assert.throws(() => presetRange('1y', NOW), /preset/);
});

// Rejects: a preset shown as active after the URL range changed (a reload or a custom range), and a range without bounds that is not "all".
test('activePreset names the chosen preset only while its range is in the filters', () => {
    const chosen = {key: '24h', from: NOW - 86400};

    assert.equal(activePreset(filters(), null), 'all');
    assert.equal(activePreset(filters({from: NOW - 86400}), chosen), '24h');
    assert.equal(activePreset(filters({from: NOW - 86400}), null), 'custom');
    assert.equal(activePreset(filters({from: NOW - 3600}), chosen), 'custom');
    assert.equal(activePreset(filters({from: NOW - 86400, to: NOW}), chosen), 'custom');
    assert.equal(activePreset(filters({to: NOW}), null), 'custom');
    assert.equal(activePreset(filters({from: NOW - 86400}), {key: 'bogus', from: NOW - 86400}), 'custom');
});

// Rejects: a count that counts the search text (it sits outside the "Filters N" button), the tab or the order, or counts a queue and its connection twice, or a time range's two ends twice.
test('activeFilterCount counts the filters behind the Filters button', () => {
    assert.equal(activeFilterCount(filters({q: 'mail', order: 'asc'})), 0);
    assert.equal(activeFilterCount(filters({connection: 'database', queue: 'emails', from: 1, to: 2})), 2);
    assert.equal(activeFilterCount(filters({connection: 'database', class: 'App\\Jobs\\A', tag: 't', batch: 'b'})), 4);
    assert.equal(activeFilterCount(null), 0);
});

// Rejects: a summary that hides an active filter, one that writes a time without its zone, and one that shows a preset name for a custom range.
test('filterSummary names each active filter in words', () => {
    assert.deepEqual(filterSummary(filters({connection: 'database', queue: 'emails', from: NOW - 86400}), {key: '24h', from: NOW - 86400}), ['database:emails', 'the last 24 h']);
    assert.deepEqual(filterSummary(filters({connection: 'database'}), null), ['database, every queue']);
    assert.deepEqual(filterSummary(filters({class: 'App\\Jobs\\A', tag: 'order:1', batch: 'b-1'}), null), ['class App\\Jobs\\A', 'tag order:1', 'batch b-1']);
    assert.deepEqual(filterSummary(filters({from: NOW - 86400, to: NOW}), null), ['from 2025-11-08 09:00:00 UTC to 2025-11-09 09:00:00 UTC']);
    assert.deepEqual(filterSummary(filters({to: NOW}), null), ['until 2025-11-09 09:00:00 UTC']);
    assert.deepEqual(filterSummary(filters({from: NOW}), null), ['since 2025-11-09 09:00:00 UTC']);
    assert.deepEqual(filterSummary(filters(), null), []);
});

// Rejects: a queue list that drops the queue of the URL when the workload does not list it (the select would show "Any" while the list is filtered), that merges two connections' queues of one name, or that reads rows without a name.
test('queueOptions lists the workload queues and keeps the filtered one', () => {
    const workload = [{connection: 'database', name: 'emails'}, {connection: 'reports', name: 'emails'}, {connection: 'database', name: null}];

    assert.deepEqual(queueOptions(workload, filters()).map(option => option.key), ['database:emails', 'reports:emails']);
    assert.deepEqual(queueOptions(workload, filters({connection: 'database', queue: 'slow '})).map(option => [option.connection, option.queue]), [
        ['database', 'emails'], ['reports', 'emails'], ['database', 'slow '],
    ]);
    assert.deepEqual(queueOptions(null, filters({connection: 'database', queue: 'a:b'}))[0], {key: 'database:a:b', connection: 'database', queue: 'a:b', label: 'database:a:b'});
});

// Rejects: a change that keeps a queue without its connection (the server refuses it), that trims a byte-exact value, or that changes the tab.
test('withFilters applies changes as given', () => {
    assert.deepEqual(withFilters(filters({connection: 'database', queue: 'emails'}), {connection: null}), filters());
    assert.deepEqual(withFilters(filters(), {connection: 'db', queue: 'high '}), filters({connection: 'db', queue: 'high '}));
    assert.deepEqual(withFilters(filters(), {class: ''}), filters());
    assert.equal(withFilters(filters(), {status: 'pending'}).status, 'failed');
});

// Rejects: a "Clear filters" that also clears the tab or the order, or keeps a filter.
test('clearedFilters keeps the tab and the order only', () => {
    assert.deepEqual(clearedFilters(filters({connection: 'd', queue: 'q', class: 'c', tag: 't', batch: 'b', from: 1, to: 2, q: 'abc', order: 'asc'})), filters({order: 'asc'}));
});

// Rejects: a local time read as UTC in another zone (the tests run in UTC; the rule is the browser's own local time), an invalid input read as 0, and a round trip that changes the minute.
test('epochFromInput and inputFromEpoch convert the local time of a datetime input', () => {
    assert.equal(epochFromInput('2025-11-09T09:00'), NOW);
    assert.equal(epochFromInput('2025-11-09T09:00:30'), NOW + 30);
    assert.equal(epochFromInput(''), null);
    assert.equal(epochFromInput('yesterday'), null);
    assert.equal(epochFromInput(null), null);
    assert.equal(inputFromEpoch(NOW + 59), '2025-11-09T09:00');
    assert.equal(inputFromEpoch(null), '');
    assert.equal(epochFromInput(inputFromEpoch(NOW)), NOW);
});

// Rejects: a custom range whose start is not before its end (the server answers 422).
test('rangeProblem refuses an empty or reversed range', () => {
    assert.equal(rangeProblem(NOW, NOW + 1), null);
    assert.equal(rangeProblem(null, NOW), null);
    assert.equal(rangeProblem(NOW, null), null);
    assert.match(rangeProblem(NOW, NOW), /before/);
    assert.match(rangeProblem(NOW + 1, NOW), /before/);
});

// Rejects: a URL update that drops a query key of the screen (a tab of the Tags screen), keeps a cleared filter, or writes the cursor.
test('mergeQuery replaces only the list keys of the query', () => {
    assert.deepEqual(mergeQuery({tab: 'failed', class: 'Old', cursor: 'x'}, filters({status: null, tag: 'order:1'})), {tab: 'failed', cursor: 'x', tag: 'order:1'});
    assert.deepEqual(mergeQuery({status: 'pending', q: 'abc'}, filters({connection: 'db', queue: 'q'})), {status: 'failed', connection: 'db', queue: 'q'});
    assert.deepEqual(mergeQuery(null, filters({order: 'asc'})), {status: 'failed', order: 'asc'});
});

// Rejects: a class that is trimmed (it is byte-exact), a tag or search trimmed with JavaScript's trim() (PHP keeps a no-break space), and an empty value that is not null.
test('committedText gives the value a typed filter sends', () => {
    assert.equal(committedText('class', ' App\\Jobs\\A '), ' App\\Jobs\\A ');
    assert.equal(committedText('tag', ' region\t'), 'region');
    assert.equal(committedText('tag', 'region\u00a0'), 'region\u00a0');
    assert.equal(committedText('q', ' mail '), 'mail');
    assert.equal(committedText('tag', ' \t'), null);
    assert.equal(committedText('class', ''), null);
});

// Rejects: a datetime input read and written in the browser's zone while orrery.display_timezone is set (the
// test runs in UTC; Tokyo is 9 h ahead), so the custom range would start 9 h off.
test('the datetime input uses the display time zone', () => {
    const before = globalThis.Orrery;

    globalThis.Orrery = {displayTimezone: 'Asia/Tokyo'};

    try {
        assert.equal(epochFromInput('2025-11-09T18:00'), NOW);
        assert.equal(inputFromEpoch(NOW), '2025-11-09T18:00');
        assert.equal(epochFromInput('2025-13-01T00:00'), null);
    } finally {
        if (before === undefined) delete globalThis.Orrery;
        else globalThis.Orrery = before;
    }
});

// Review fix 4. Rejects: custom-range inputs without the zone they are read in, and the browser's zone named
// while orrery.display_timezone is set.
test('rangeZoneNote names the zone of the custom-range inputs', () => {
    assert.equal(rangeZoneNote(NOW), 'Times in UTC');

    const before = globalThis.Orrery;

    globalThis.Orrery = {displayTimezone: 'Asia/Tokyo'};

    try {
        assert.equal(rangeZoneNote(NOW), 'Times in GMT+9');
    } finally {
        if (before === undefined) delete globalThis.Orrery;
        else globalThis.Orrery = before;
    }
});
