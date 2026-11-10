// Tests of resources/js/screens/failed/failures.js. `pnpm test` runs in UTC.

import {test} from 'node:test';
import assert from 'node:assert/strict';

import {
    failedView,
    failedViewQuery,
    atLeastText,
    attemptsText,
    chartBuckets,
    countText,
    failedJobsLink,
    groupsSource,
    restartSource,
    latestParams,
    LATEST_PATH,
    MAX_GROUP_KEYS,
    firstLine,
    groupCountAtLeast,
    groupGoneTitle,
    groupQueuesText,
    groupSinceText,
    groupStartUncertain,
    liveWindow,
    MARKER_LEAD_SECONDS,
    markerBefore,
    markerName,
    operationLabel,
    peakOf,
    latestSubtitle,
    peakText,
    perUnit,
    seenTimeText,
    queueName,
    queueOptions,
    rangeOf,
    retentionDays,
    rowInGroup,
    shortName,
    stillFailedText,
    subtitleText,
    TRACE_SEARCH_CHARS,
    traceLocation,
    traceText,
    timesText,
} from '../../resources/js/screens/failed/failures.js';

// Rejects: an unknown range key that gives undefined, and a lookup by label instead of key.
test('rangeOf falls back to the 1 h range', () => {
    assert.equal(rangeOf('24h').seconds, 86400);
    assert.equal(rangeOf('7d').seconds, 604800);
    assert.equal(rangeOf('nope').key, '1h');
    assert.equal(rangeOf(undefined).key, '1h');
});

// Rejects: a fractional `to`, and a window that ends before now.
test('liveWindow ends at the whole second of now', () => {
    assert.deepEqual(liveWindow(3600, 1790000000.9), {from: 1789996400, to: 1790000000});
});

// Rejects: a split on the first separator only, and an empty or null name shown as an empty string.
test('shortName and queueName cut the namespace and the connection', () => {
    assert.equal(shortName('App\\Jobs\\Sync'), 'Sync');
    assert.equal(shortName('Sync'), 'Sync');
    assert.equal(shortName('App\\'), 'App\\');
    assert.equal(shortName(''), '—');
    assert.equal(shortName(null), '—');
    assert.equal(queueName('database:default'), 'default');
    assert.equal(queueName('database:emails:high'), 'emails:high');
    assert.equal(queueName('default'), 'default');
    assert.equal(queueName(null), '—');
});

// Rejects: a split on LF only, which keeps "\r" or a CR-only second line in the group's line.
test('firstLine splits on CRLF, LF and CR', () => {
    assert.equal(firstLine('a\r\nb'), 'a');
    assert.equal(firstLine('a\nb'), 'a');
    assert.equal(firstLine('a\rb'), 'a');
    assert.equal(firstLine('only'), 'only');
    assert.equal(firstLine(''), '');
    assert.equal(firstLine(null), null);
});

// Rejects: one option per merged row (a failed row keeps its own alias), and a label without the connection for a duplicate name.
test('queueOptions gives each alias its own option', () => {
    const options = queueOptions([
        {name: 'default', connection: 'database', connections: ['database', 'database-alt']},
        {name: 'emails', connection: 'database'},
        {name: 'default', connection: 'database'},
        {name: 7},
        null,
    ]);

    assert.deepEqual(options, [
        {value: 'database:default', label: 'default (database)'},
        {value: 'database-alt:default', label: 'default (database-alt)'},
        {value: 'database:emails', label: 'emails'},
    ]);
    assert.deepEqual(queueOptions(null), []);
});

// Rejects: "0" for an unknown count, and a lower bound without its mark.
test('countText and atLeastText mark a lower bound', () => {
    assert.equal(countText(27, false), '27');
    assert.equal(countText(1234, true), '≥ 1,234');
    assert.equal(countText(null, true), '—');
    assert.equal(atLeastText(41, false), '41');
    assert.equal(atLeastText(41, true), 'at least 41');
    assert.equal(atLeastText(undefined, false), '—');
});

// Rejects: a missing part written as 0, and a truncated hour without "at least".
test('subtitleText joins the known parts', () => {
    const hour = {scanned: 41, truncated: false, as_of: 1790000000, retention_from: 1790000000 - 7 * 86400};

    assert.equal(subtitleText(hour, {count: 312, at_least: false}), '41 in the past hour · 312 still failed · last 7 days');
    assert.equal(subtitleText({...hour, truncated: true}, undefined), 'at least 41 in the past hour · last 7 days');
    assert.equal(subtitleText(null, {count: 3, at_least: false}), '3 still failed');
    assert.equal(subtitleText(null, undefined), null);
});

// Rejects: an unknown failed count written as 0 or left out, and a capped count without "at least".
test('stillFailedText shows an unknown count as a dash and a capped count as at least', () => {
    assert.equal(stillFailedText({count: 312, at_least: false}), '312 still failed');
    assert.equal(stillFailedText({count: '1000000', at_least: true}), 'at least 1,000,000 still failed');
    assert.equal(stillFailedText({count: null, at_least: false}), '— still failed');
    assert.equal(stillFailedText(null), '— still failed');
    assert.equal(subtitleText(null, null), '— still failed');
});

// Rejects: the list's `truncated` as the count qualifier (it now means only that groups may be missing).
test('groupCountAtLeast follows the capped count and the inexact group', () => {
    assert.equal(groupCountAtLeast({count: 5000, count_capped: true, exact: false}), true);
    assert.equal(groupCountAtLeast({count: 12, count_capped: false, exact: false}), true);
    assert.equal(groupCountAtLeast({count: 12, count_capped: false, exact: true}), false);
    assert.equal(groupCountAtLeast(null), false);
});

// Rejects: "since 09:14" for the first failure of the read range (the group may have failed before it),
// "or earlier" on every inexact group (a capped keyed count has an exact start), and no qualifier for fallback rows.
test('groupSinceText names the first failure of the range and qualifies only a truncated fallback scan', () => {
    const first = 1790586000 + 14 * 60;

    assert.equal(groupStartUncertain({exact: false, count_capped: true}), false);
    assert.equal(groupStartUncertain({exact: false, count_capped: false}), true);
    assert.equal(groupSinceText({first_failed_at: first, exact: true, count_capped: false}), 'first in this range at 09:14 UTC');
    assert.equal(groupSinceText({first_failed_at: String(first), exact: false, count_capped: true}, 'in the last hour'), 'first in the last hour at 09:14 UTC');
    assert.equal(groupSinceText({first_failed_at: first, exact: false, count_capped: false}), 'first in this range at 09:14 UTC or earlier');
    assert.equal(groupSinceText({first_failed_at: null}, 'in the last hour'), 'first in the last hour at an unknown time');
});

// Rejects: a queue list that hides the queues outside the scanned members, and a repeated name.
test('groupQueuesText says "and more" for an incomplete queue list', () => {
    const group = {queues: ['database:emails', 'other:emails', 'database:default'], queues_complete: true};

    assert.equal(groupQueuesText(group, queueName), 'emails, default');
    assert.equal(groupQueuesText({...group, queues_complete: false}, queueName), 'emails, default and more');
    assert.equal(groupQueuesText({...group, queues_complete: false}), 'database:emails, other:emails, database:default and more');
    assert.equal(groupQueuesText({queues: [], queues_complete: false}), 'unknown queues');
    assert.equal(groupQueuesText(null), '—');
});

// Rejects: "No failed jobs of this group" after a read that did not reach every failure.
test('groupGoneTitle claims the group is gone only after a complete read', () => {
    assert.equal(groupGoneTitle({group: null, complete: true}), 'No failed jobs of this group in this range');
    assert.equal(groupGoneTitle({group: null, complete: false}), 'Not found among the failures read');
    assert.equal(groupGoneTitle({group: null}), 'Not found among the failures read');
});

// Rejects: "last 0 days" for a horizon under one day, and a string time read as a number.
test('retentionDays rounds to whole days and is null below one day', () => {
    assert.equal(retentionDays({as_of: 864000, retention_from: 0}), 10);
    assert.equal(retentionDays({as_of: 86400, retention_from: 0}), 1);
    assert.equal(retentionDays({as_of: 3600, retention_from: 0}), null);
    assert.equal(retentionDays({as_of: '86400', retention_from: 0}), null);
    assert.equal(retentionDays(null), null);
});

// Rejects: "1 attempts", and a range shown as one count.
test('attemptsText writes one count or a range', () => {
    assert.equal(attemptsText({min: 3, max: 3}), '3 attempts each');
    assert.equal(attemptsText({min: 1, max: 1}, 'only'), '1 attempt only');
    assert.equal(attemptsText({min: 2, max: 3}), '2–3 attempts');
    assert.equal(attemptsText({min: null, max: 3}), '—');
    assert.equal(attemptsText(null), '—');
});

// Rejects: attempts of a capped group shown as the attempts of every member.
test('attemptsText of a capped group names the newest rows it covers', () => {
    assert.equal(attemptsText({min: 3, max: 3, of_newest: 5000}), '3 attempts each, of the newest 5,000');
    assert.equal(attemptsText({min: 2, max: 3, of_newest: 5000}, 'on every job'), '2–3 attempts, of the newest 5,000');
    assert.equal(attemptsText({min: 2, max: 3, of_newest: null}), '2–3 attempts');
});

// Rejects: a selected group that also counts as `other`, a sum that drops a group's bucket, and a slot that a listed
// group lacks (a capped group before its oldest counted row) drawn as a known count instead of a gap.
test('chartBuckets splits the selected group from the others', () => {
    const response = {
        bucket_seconds: 60,
        groups: [
            {key: 'a', buckets: [{at: 0, count: 0}, {at: 60, count: 2}, {at: 120, count: 1, partial: true}]},
            {key: 'b', buckets: [{at: 60, count: 5}, {at: 0, count: 1}, null, {at: 'x', count: 9}, {at: 120, count: 0}]},
        ],
    };

    assert.deepEqual(chartBuckets(response, 'a', {from: 0, to: 150}), [
        {at: 0, selected: 0, other: 1, all: 1, partial: false},
        {at: 60, selected: 2, other: 5, all: 7, partial: false},
        {at: 120, selected: 1, other: 0, all: 1, partial: true},
    ]);

    const unselected = chartBuckets(response, 'missing', {from: 0, to: 150});
    assert.deepEqual(unselected.map(bucket => [bucket.selected, bucket.other]), [[0, 1], [0, 7], [0, 1]]);
});

// Rejects: the small group's counts shown as the slot's total while a capped group's count of that slot is unknown,
// and the selected group's known count lost because another group is capped.
test('chartBuckets makes a slot unknown where a listed group lacks it', () => {
    const response = {
        bucket_seconds: 60,
        groups: [
            {key: 'capped', count_capped: true, buckets: [{at: 120, count: 40, partial: true}]},
            {key: 'small', buckets: [{at: 0, count: 1}, {at: 60, count: 2}, {at: 120, count: 3}]},
        ],
    };

    assert.deepEqual(chartBuckets(response, null, {from: 0, to: 180}), [
        {at: 0, selected: 0, other: null, all: null, partial: false},
        {at: 60, selected: 0, other: null, all: null, partial: false},
        {at: 120, selected: 0, other: 43, all: 43, partial: true},
    ]);
    assert.deepEqual(chartBuckets(response, 'small', {from: 0, to: 180}).map(bucket => [bucket.selected, bucket.other, bucket.all]),
        [[1, null, null], [2, null, null], [3, 40, 43]]);
    assert.deepEqual(chartBuckets(response, 'capped', {from: 0, to: 180}).map(bucket => [bucket.selected, bucket.other, bucket.all]),
        [[null, 1, null], [null, 2, null], [40, 3, 43]]);
});

// Rejects: a slot that no group has, filled with zero (a slot absent in the groups is unknown, never zero).
test('chartBuckets keeps a slot absent when no group has it', () => {
    const response = {
        bucket_seconds: 60,
        groups: [
            {key: 'a', buckets: [{at: 0, count: 2}, {at: 120, count: 1}]},
            {key: 'b', buckets: [{at: 0, count: 0}, {at: 120, count: 3}]},
        ],
    };

    const buckets = chartBuckets(response, 'a', {from: 0, to: 180});

    assert.deepEqual(buckets.map(bucket => bucket.at), [0, 120]);
    assert.equal(buckets.find(bucket => bucket.at === 60), undefined);
});

// Rejects: zero buckets for a truncated empty scan (unknown is never zero), and zero buckets before the retention horizon.
test('chartBuckets of a response without groups is zero only for a complete scan', () => {
    const window = {from: 0, to: 150};

    assert.deepEqual(chartBuckets({groups: [], bucket_seconds: 60, retention_from: 61}, null, window), [
        {at: 60, selected: 0, other: 0, all: 0, partial: false},
        {at: 120, selected: 0, other: 0, all: 0, partial: true},
    ]);
    assert.deepEqual(chartBuckets({groups: [], truncated: true}, null, window), []);
    assert.deepEqual(chartBuckets(null, null, window), []);
    assert.deepEqual(chartBuckets({groups: []}, null, null), []);
});

// Rejects: a peak of zero, and a peak that skips the partial bucket.
test('peakOf counts partial buckets and needs a failure', () => {
    assert.deepEqual(peakOf([{at: 0, all: 2}, {at: 60, all: 5, partial: true}, {at: 120, all: 5}], 'all'), {at: 60, value: 5});
    assert.equal(peakOf([{at: 0, all: 0}], 'all'), null);
    assert.equal(peakOf(null, 'all'), null);
});

// Rejects: "/min" for every bucket size.
test('perUnit names the bucket size', () => {
    assert.equal(perUnit(60), '/min');
    assert.equal(perUnit(3600), '/h');
    assert.equal(perUnit(300), ' per 300 s');
});

// Rejects: a marker after the first failure, a marker further back than the lead, and the oldest candidate.
test('markerBefore picks the nearest marker within the lead', () => {
    const first = 1000;
    const markers = [
        {at: first - MARKER_LEAD_SECONDS - 1, kind: 'deploy'},
        {at: first - 100, kind: 'restart'},
        {at: first - 10, kind: 'deploy'},
        {at: first + 1, kind: 'deploy'},
    ];

    assert.equal(markerBefore(markers, first).at, first - 10);
    assert.equal(markerBefore([{at: first - MARKER_LEAD_SECONDS}], first).at, first - MARKER_LEAD_SECONDS);
    assert.equal(markerBefore([markers[0], markers[3]], first), null);
    assert.equal(markerBefore(markers, null), null);
});

// Rejects: "Deploy null" for a deploy without a label.
test('markerName names a deploy with its label and every other marker as a restart', () => {
    assert.equal(markerName({kind: 'deploy', label: 'v1.2'}), 'Deploy v1.2');
    assert.equal(markerName({kind: 'deploy', label: null}), 'Deploy');
    assert.equal(markerName({kind: 'restart'}), 'Restart requested');
    assert.equal(markerName(null), '');
});

// Rejects: an omitted trace shown as "[object Object]" or as raw JSON, and an unknown shape shown as a trace.
test('traceText gives the trace or a sentence for an omitted one', () => {
    assert.equal(traceText('RuntimeException: boom\n#0 a.php(1)'), 'RuntimeException: boom\n#0 a.php(1)');
    assert.equal(traceText({omitted: 'too_large', bytes: 70024}), 'The stack trace is too large to show (70,024 bytes).');
    assert.equal(traceText({omitted: 'too_large'}), 'The stack trace is too large to show.');
    assert.equal(traceText({omitted: 'other', bytes: 3}), null);
    assert.equal(traceText(null), null);
    assert.equal(traceText(undefined), null);
    assert.equal(traceText(42), null);
});

// Rejects: a search past the first characters, and a path with its directories.
test('traceLocation finds the first frame near the start', () => {
    assert.equal(traceLocation('#0 /app/vendor/SocketStream.php(154): read()'), 'SocketStream.php:154');
    assert.equal(traceLocation('at /app/Job.php:12'), 'Job.php:12');
    assert.equal(traceLocation('no frame'), null);
    assert.equal(traceLocation('x'.repeat(TRACE_SEARCH_CHARS) + ' Job.php:12'), null);
    assert.equal(traceLocation(null), null);
});

// Rejects: the run-together "×≥ 5,000", a lower bound without its mark, and "×null".
test('timesText marks a lower bound apart from the times sign', () => {
    assert.equal(timesText(27, false), '×27');
    assert.equal(timesText(5000, true), '× ≥5,000');
    assert.equal(timesText(null, true), '');
});

// A bulk run takes every member, so the old "up to 200" cap is gone. Rejects: "Retry up to 200" for
// a larger group, a lower bound shown as an exact number, and "Retry 0" or "Retry NaN" for an unknown count.
test('operationLabel names the whole group, with ≥ for a lower bound', () => {
    assert.equal(operationLabel('Retry', 37, false), 'Retry 37');
    assert.equal(operationLabel('Retry', 201, false), 'Retry 201');
    assert.equal(operationLabel('Retry', 5000, true), 'Retry ≥ 5,000');
    assert.equal(operationLabel('Forget', 1, false), 'Forget 1');
    assert.equal(operationLabel('Retry', null, false), 'Retry');
    assert.equal(operationLabel('Retry', 0, false), 'Retry');
    assert.equal(operationLabel('Retry', Number.NaN, false), 'Retry');
});

// Rejects: a source with a repeated key, more than 200 keys (the server answers 422), an empty
// queue sent as "" (the server scope wants null), a window that is not two numbers, and a source without keys.
test('groupsSource builds the frozen groups source of a bulk run', () => {
    assert.deepEqual(groupsSource('database:emails', {from: 100, to: 200}, ['a', 'b', 'a', '', null]),
        {kind: 'groups', queue: 'database:emails', from: 100, to: 200, keys: ['a', 'b']});
    assert.deepEqual(groupsSource('', {from: '100', to: 200}, ['a']), {kind: 'groups', queue: null, from: 100, to: 200, keys: ['a']});
    assert.equal(groupsSource(null, {from: 100, to: 200}, []), null);
    assert.equal(groupsSource(null, null, ['a']), null);
    assert.equal(groupsSource(null, {from: 'x', to: 200}, ['a']), null);
    assert.equal(MAX_GROUP_KEYS, 200);
    assert.equal(groupsSource(null, {from: 1, to: 2}, Array.from({length: 201}, (_, index) => `k${index}`)), null);
    assert.equal(groupsSource(null, {from: 1, to: 2}, Array.from({length: 200}, (_, index) => `k${index}`)).keys.length, 200);
});

// Rejects: a link to the legacy /failed-legacy list, a queue filter sent as one "connection:queue"
// value (the Jobs list has two fields), and a class filter from a missing job name.
test('failedJobsLink opens the Failed tab of the Jobs list with the class and the queue', () => {
    assert.deepEqual(failedJobsLink({job: 'App\\Jobs\\Sync'}, 'database:emails:eu'), {path: '/jobs', query: {status: 'failed', class: 'App\\Jobs\\Sync', connection: 'database', queue: 'emails:eu'}});
    assert.deepEqual(failedJobsLink({job: ''}, null), {path: '/jobs', query: {status: 'failed'}});
    assert.deepEqual(failedJobsLink(null, 'emails'), {path: '/jobs', query: {status: 'failed'}});
});

// Rejects: the legacy /api/jobs/failed read with starting_at, and a page larger than the rows shown.
test('latestParams reads the newest rows of the Failed tab', () => {
    assert.equal(LATEST_PATH, '/api/jobs');
    assert.deepEqual(latestParams(5), {status: 'failed', per_page: 5});
});

// Rejects: a match on the class alone (another exception message of the class is another group), a match
// without the job name, a row of a group without an exception class, and a full-message compare that a second
// line breaks (the group key uses the first line).
test('rowInGroup compares the class, the job and the first line', () => {
    const group = {key: 'k', exception_class: 'E', job: 'App\\Jobs\\Sync', message: 'Login failed password=[redacted]'};

    assert.equal(rowInGroup({exception_class: 'E', name: 'App\\Jobs\\Sync', exception_message: 'Login failed password=[redacted]\nat line 2'}, group), true);
    assert.equal(rowInGroup({exception_class: 'E', name: 'App\\Jobs\\Sync', exception_message: 'Other text'}, group), false);
    assert.equal(rowInGroup({exception_class: 'E', name: 'App\\Jobs\\Other', exception_message: 'Login failed password=[redacted]'}, group), false);
    assert.equal(rowInGroup({exception_class: 'F', name: 'App\\Jobs\\Sync', exception_message: 'Login failed password=[redacted]'}, group), false);
    assert.equal(rowInGroup({exception_class: null, name: 'x', exception_message: null}, {exception_class: null, job: 'x', message: null}), false);
    assert.equal(rowInGroup({exception_class: 'E', name: 'App\\Jobs\\Sync', exception_message: null}, {...group, message: null}), true);
    assert.equal(rowInGroup(null, group), false);
    assert.equal(rowInGroup({exception_class: 'E'}, null), false);
});

// Review fix 1. Rejects: a restart that opens the auto-selected group instead of the recovered run's groups, a
// restart that shifts the confirmed window to now, a copy that shares the keys array with the record, and a
// restart from a source of another kind or without keys.
test('restartSource copies the recovered groups source exactly', () => {
    const source = {kind: 'groups', queue: 'database:emails', from: 100, to: 3700, keys: ['a', 'b']};
    const copy = restartSource(source);

    assert.deepEqual(copy, {kind: 'groups', queue: 'database:emails', from: 100, to: 3700, keys: ['a', 'b']});
    assert.notEqual(copy.keys, source.keys);
    assert.deepEqual(restartSource({kind: 'groups', queue: null, from: 1.5, to: 2, keys: ['k']}), {kind: 'groups', queue: null, from: 1.5, to: 2, keys: ['k']});
    assert.equal(restartSource(null), null);
    assert.equal(restartSource({kind: 'batch', batch_id: 'b'}), null);
    assert.equal(restartSource({kind: 'list', queue: null, from: 1, to: 2, keys: ['k']}), null, 'another kind with keys');
    assert.equal(restartSource({kind: 'groups', queue: null, from: 1, to: 2, keys: []}), null);
    assert.equal(restartSource({kind: 'groups', queue: null, from: null, to: 2, keys: ['k']}), null);
});

// Review fix 1. Rejects: a sheet that cannot name the group (the names of the selected rows are dropped), names
// in the order of the list instead of the keys, and names sent to the server (freezeBody keeps only the source).
test('groupsSource carries the names of its groups for the sheet', () => {
    const groups = [
        {key: 'b', exception_class: 'App\\Exceptions\\B', message: 'Second\nmore'},
        {key: 'a', exception_class: 'App\\Exceptions\\TransportException', message: 'Connection timed out'},
    ];
    const source = groupsSource(null, {from: 1, to: 2}, ['a', 'b'], groups);

    assert.deepEqual(source.names, [
        {exception_class: 'App\\Exceptions\\TransportException', message: 'Connection timed out'},
        {exception_class: 'App\\Exceptions\\B', message: 'Second\nmore'},
    ]);
    assert.equal(groupsSource(null, {from: 1, to: 2}, ['a']).names, undefined);
});

// Rejects: a bare "09:14" without the zone, a date for a time of the last 24 h, the clock alone
// for an older time (it would read as today's), and the browser's zone when a display zone is set.
test('seenTimeText gives the zoned minute, with the date when older than a day', () => {
    const now = 1790586000;

    assert.equal(seenTimeText(now - 60, now), '08:59 UTC');
    assert.equal(seenTimeText(String(now - 3600), now), '08:00 UTC');
    assert.equal(seenTimeText(now - 2 * 86400, now), '2026-09-26 09:00 UTC');
    // 23 h ago is the previous day: a clock alone would read as today's.
    assert.equal(seenTimeText(now - 23 * 3600, now), '2026-09-27 10:00 UTC');
    assert.equal(seenTimeText(null, now), '—');

    globalThis.Orrery = {displayTimezone: 'Asia/Tokyo'};

    try {
        assert.equal(seenTimeText(now - 60, now), '17:59 GMT+9');
    } finally {
        delete globalThis.Orrery;
    }
});

// Rejects: a peak time without the zone, a lost "at least", and a peak text for no peak.
test('peakText names the peak, its unit and its zoned time', () => {
    const at = 1790586000 + 14 * 60;

    assert.equal(peakText({at, value: 1234}, 60, false, at + 600), 'peak 1,234/min at 09:14 UTC');
    assert.equal(peakText({at, value: 5}, 3600, true, at + 600), 'peak at least 5/h at 09:14 UTC');
    // The peak of a 7-day chart on an earlier day carries its date.
    assert.equal(peakText({at, value: 5}, 3600, false, at + 3 * 86400), 'peak 5/h at 2026-09-28 09:14 UTC');
    assert.equal(peakText(null, 60, false), null);
});

// Rejects: the latest-failures card without the zone of its row times.
test('latestSubtitle names the zone of the row times', () => {
    assert.equal(latestSubtitle(1790586000), 'newest first by failure time · all queues · times in UTC');
});

// Rejects: a deploy chip on a group whose first failure in the range lies within the marker lead of the
// range's start (the group may have failed before the range, so the marker did not start it), and a range
// rule that drops the chip of a group with a quiet lead inside the range.
test('markerBefore gives no marker when the range does not show the quiet lead before the first failure', () => {
    const from = 10000;
    const markers = [{at: from + 20, kind: 'deploy'}, {at: from + 500, kind: 'deploy'}];

    assert.equal(markerBefore(markers, from + 60, from), null);
    assert.equal(markerBefore(markers, from + 60), markers[0]);
    assert.equal(markerBefore(markers, from + 560, from), markers[1]);
    assert.equal(markerBefore(markers, from + MARKER_LEAD_SECONDS + 20, from), markers[0]);
});

// Rejects: a Failure groups view (range, queue, page, selected group) that a reload or a shared link loses; a
// URL value that is not valid taken as it is (an unknown range, a half page, a group key of another form); and a
// query that repeats the defaults.
// Still passes: a queue of the URL that no longer exists (the screen lists it as an option and finds no groups).
test('failedView and failedViewQuery keep the view in the URL', () => {
    const key = 'a'.repeat(64);

    assert.deepEqual(failedView({range: '24h', queue: 'database:emails', offset: '100', group: key}),
        {range: '24h', queue: 'database:emails', offset: 100, group: key});
    assert.deepEqual(failedView({}), {range: '1h', queue: '', offset: 0, group: null});
    assert.deepEqual(failedView({range: '2d', offset: '75', group: 'x'}), {range: '1h', queue: '', offset: 0, group: null});
    assert.deepEqual(failedView({offset: '-50', queue: ['a'], group: key.toUpperCase()}), {range: '1h', queue: '', offset: 0, group: null});
    assert.deepEqual(failedView(null), {range: '1h', queue: '', offset: 0, group: null});

    assert.deepEqual(failedViewQuery({range: '7d', queue: 'database:emails', offset: 50, group: key}),
        {range: '7d', queue: 'database:emails', offset: '50', group: key});
    assert.deepEqual(failedViewQuery({range: '1h', queue: '', offset: 0, group: null}), {});
    assert.deepEqual(failedView(failedViewQuery({range: '24h', queue: 'q', offset: 150, group: key})), {range: '24h', queue: 'q', offset: 150, group: key});
});
