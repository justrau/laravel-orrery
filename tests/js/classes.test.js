// Tests of resources/js/screens/classes/classes.js (the Job classes view). `pnpm test` runs in UTC.

import {test} from 'node:test';
import assert from 'node:assert/strict';

import {
    chartSeries,
    classFailure,
    classesTitle,
    compactParts,
    countText,
    countTitle,
    emptyText,
    findClass,
    fullMinutes,
    inText,
    jobsLink,
    lastFullMinuteText,
    normalizeClasses,
    normalizeSeries,
    notListedText,
    peakText,
    queryFor,
    runtimeText,
    selectedFromQuery,
    seriesParams,
    spanMinutes,
    unexpectedAnswer,
    unknownNotice,
    unknownAnnouncement,
    windowFromQuery,
    windowLine,
    DEFAULT_SPAN,
    classesFirstRequests,
    classesRead,
    seriesRead,
} from '../../resources/js/screens/classes/classes.js';

const NOW = 1790000000; // 2026-09-21 14:13:20 UTC
const MINUTE = Math.floor(NOW / 60) * 60;

function classesResponse(overrides = {}) {
    return {
        as_of: NOW + 0.25,
        from: MINUTE - 59 * 60,
        to: NOW + 0.25,
        retention_from: {completed: MINUTE - 3600.0, failed: MINUTE - 3600.0, pushed: MINUTE - 3600.0},
        classes: [
            {name: 'App\\Mail\\OrderShipped', completed: 18204, failed: 27, pushed: 18512, runtime_avg_ms: 1234.5, complete: true},
            {name: 'App\\Jobs\\RebuildSearchIndex', completed: 0, failed: 0, pushed: 60, runtime_avg_ms: null, complete: true},
        ],
        truncated: false,
        unknown_minutes: null,
        ...overrides,
    };
}

// Rejects: a window read from the URL that the API refuses (any minutes other than 15 or 60), or a default other than 60.
test('windowFromQuery accepts only the two windows of the screen', () => {
    assert.equal(windowFromQuery({}), 60);
    assert.equal(windowFromQuery({minutes: '15'}), 15);
    assert.equal(windowFromQuery({minutes: '60'}), 60);

    for (const value of ['5', '30', '1440', ' 15', 'abc', ['15'], null]) {
        assert.equal(windowFromQuery({minutes: value}), 60, String(value));
    }

    assert.equal(windowFromQuery(null), 60);
});

// Rejects: a selected class that is trimmed or case-folded (the name is byte-exact), and an array or empty value taken as a class.
test('selectedFromQuery keeps the class byte for byte', () => {
    assert.equal(selectedFromQuery({class: ' App\\Jobs\\Send '}), ' App\\Jobs\\Send ');
    assert.equal(selectedFromQuery({class: 'app\\jobs\\SEND'}), 'app\\jobs\\SEND');
    assert.equal(selectedFromQuery({class: ''}), null);
    assert.equal(selectedFromQuery({class: ['a']}), null);
    assert.equal(selectedFromQuery({}), null);
    assert.equal(selectedFromQuery(undefined), null);
});

// Rejects: a URL that repeats the default window, drops or trims the class, or orders its keys by insertion (a route comparison would see a change).
test('queryFor gives the route query of the window and the selection', () => {
    assert.deepEqual(queryFor(60, null), {});
    assert.deepEqual(queryFor(15, null), {minutes: '15'});
    assert.deepEqual(queryFor(60, ' A\\B '), {class: ' A\\B '});
    assert.deepEqual(Object.keys(queryFor(15, 'X')), ['minutes', 'class']);
});

// Rejects: a span key that sends an unsupported minutes value to the series endpoint.
test('spanMinutes and seriesParams name the series window', () => {
    assert.equal(spanMinutes('1h'), 60);
    assert.equal(spanMinutes('24h'), 1440);
    assert.equal(spanMinutes('7d'), 60);
    assert.deepEqual(seriesParams('A\\B ', '24h'), {name: 'A\\B ', minutes: 1440});
});

// Rejects: a normalisation that turns a null count into 0 (unknown is never zero), keeps a decimal string, or keeps a row without a name.
test('normalizeClasses keeps unknown values unknown', () => {
    const data = normalizeClasses(classesResponse({
        classes: [
            {name: 'A', completed: '12', failed: null, pushed: null, runtime_avg_ms: 'x', complete: false},
            {name: null, completed: 1, failed: 1, pushed: 1, runtime_avg_ms: 1, complete: true},
            {name: 'B', completed: 3, failed: 0, pushed: 5, runtime_avg_ms: 10, complete: true},
        ],
        truncated: true,
        unknown_minutes: {count: 3, first: MINUTE - 900, last: MINUTE - 780},
    }));

    assert.equal(data.classes.length, 2);
    assert.deepEqual(data.classes[0], {name: 'A', completed: 12, failed: null, pushed: null, runtimeAvgMs: null, complete: false});
    assert.deepEqual(data.classes[1], {name: 'B', completed: 3, failed: 0, pushed: 5, runtimeAvgMs: 10, complete: true});
    assert.equal(data.truncated, true);
    assert.equal(data.capped, false);
    assert.equal(normalizeClasses(classesResponse({capped: true})).capped, true);
    assert.deepEqual(data.unknownMinutes, {count: 3, first: MINUTE - 900, last: MINUTE - 780});
    assert.equal(data.from, MINUTE - 59 * 60);
    assert.equal(data.retentionFrom.pushed, MINUTE - 3600);

    assert.equal(normalizeClasses(null), null);
    assert.equal(normalizeClasses({classes: 'x'}), null);
    assert.equal(normalizeClasses(classesResponse({unknown_minutes: {count: 0}})).unknownMinutes, null);
});

// Rejects: an incomplete sum shown as an exact count, an unknown count shown as 0, and a complete one shown with "≥".
test('countText says "≥" for a lower bound and "—" for an unknown count', () => {
    assert.equal(countText(18204, false), '≥ 18,204');
    assert.equal(countText(18204, true), '18,204');
    assert.equal(countText(0, true), '0');
    assert.equal(countText(0, false), '≥ 0');
    assert.equal(countText(null, true), '—');
    assert.equal(countText(null, false), '—');

    assert.equal(countTitle(27, false, 'failed'), 'at least 27 failed');
    assert.equal(countTitle(27, true, 'failed'), '27 failed');
    assert.equal(countTitle(null, true, 'failed'), 'failed unknown');
});

// Rejects: arrivals that are unknown (a minute counted before the upgrade) shown as 0, and a known sum marked as a lower bound (the API sends null whenever a minute's arrivals are unknown).
test('inText shows the arrivals exactly, or "—" when unknown', () => {
    assert.equal(inText(18512), '18,512');
    assert.equal(inText(0), '0');
    assert.equal(inText(null), '—');
});

// Rejects: an average without runs shown as "0 ms", a long runtime shown in milliseconds, and a runtime just below one minute that reads otherwise than one minute itself ("1.0 min" beside "1 min").
test('runtimeText formats the average runtime', () => {
    assert.equal(runtimeText(null), '—');
    assert.equal(runtimeText(340), '340 ms');
    assert.equal(runtimeText(1234.5), '1.2 s');
    assert.equal(runtimeText(252000), '4 min 12 s');
    assert.equal(runtimeText(-1), '—');
    assert.equal(runtimeText(999.4), '999 ms');
    assert.equal(runtimeText(999.6), '1.0 s');
    assert.equal(runtimeText(59949), '59.9 s');
    assert.equal(runtimeText(59999), '1 min');
    assert.equal(runtimeText(60000), '1 min');
    assert.equal(runtimeText(59999), runtimeText(60000));
});

// Rejects: a notice without the minutes it names, "1 minutes", a time without its zone, and a notice when every minute was counted.
test('unknownNotice names the uncounted minutes once', () => {
    assert.equal(unknownNotice(null), null);
    assert.equal(
        unknownNotice({count: 3, first: MINUTE - 900, last: MINUTE - 780}),
        '3 minutes in this window could not be counted (13:58 to 14:00 UTC). Sums that include them are lower bounds and read “at least”.',
    );
    assert.equal(
        unknownNotice({count: 1, first: MINUTE - 900, last: MINUTE - 900}),
        '1 minute in this window could not be counted (13:58 UTC). Sums that include them are lower bounds and read “at least”.',
    );
});

// Rejects: a capped read whose notice does not say why the minutes were not counted, a reason on a read
// that was not capped, and a reason without the uncounted minutes.
test('unknownNotice says why when the read was capped', () => {
    assert.equal(
        unknownNotice({count: 2, first: MINUTE - 900, last: MINUTE - 840}, true),
        '2 minutes in this window could not be counted (13:58 to 13:59 UTC): the window has more job class rows than the read takes (dashboard.class_rows), for example job names that change per job. Sums that include them are lower bounds and read “at least”, and the list shows the busiest classes of the counted minutes.',
    );
    assert.equal(unknownNotice(null, true), null);
    assert.equal(
        unknownNotice({count: 1, first: MINUTE - 900, last: MINUTE - 900}, false),
        '1 minute in this window could not be counted (13:58 UTC). Sums that include them are lower bounds and read “at least”.',
    );
});

// Rejects: a title that counts the shown rows as all classes when the list is truncated, and "1 classes".
test('classesTitle counts the classes and says when the list is cut', () => {
    assert.equal(classesTitle(6, false), '6 classes');
    assert.equal(classesTitle(1, false), '1 class');
    assert.equal(classesTitle(0, false), '0 classes');
    assert.equal(classesTitle(500, true), 'Showing the 500 busiest classes');
});

// Rejects: a window line without its zone, or with a time of the request instead of the window's start.
test('windowLine names the window with its zone', () => {
    const data = normalizeClasses(classesResponse());

    assert.equal(windowLine(data), '13:14–14:13 UTC · sorted by completed + failed');
    assert.equal(windowLine(null), null);
});

// Rejects: "nothing ran" when minutes could not be counted (unknown is not zero).
test('emptyText tells an empty window from an uncounted one', () => {
    assert.equal(emptyText(normalizeClasses(classesResponse({classes: []})), 60), 'No job class ran in the last 60 minutes.');
    assert.equal(
        emptyText(normalizeClasses(classesResponse({classes: [], unknown_minutes: {count: 60, first: MINUTE - 3540, last: MINUTE}})), 15),
        'No job class was counted in the last 15 minutes, and some minutes could not be counted.',
    );
});

// Rejects: a lookup that trims or folds the case of the name.
test('findClass matches the name byte for byte', () => {
    const classes = [{name: 'A\\B'}, {name: 'A\\B '}];

    assert.equal(findClass(classes, 'A\\B '), classes[1]);
    assert.equal(findClass(classes, 'a\\b'), null);
    assert.equal(findClass(null, 'A\\B'), null);
});

// Rejects: a link that trims the class, leaves the window out, or opens a tab other than Completed.
test('jobsLink opens the Jobs list on the class and the window', () => {
    assert.deepEqual(jobsLink(' App\\Jobs\\Send ', {from: 1790000000, to: 1790003600.4}), {
        path: '/jobs',
        query: {status: 'completed', class: ' App\\Jobs\\Send ', from: '1790000000', to: '1790003601'},
    });
    assert.deepEqual(jobsLink('A', null), {path: '/jobs', query: {status: 'completed', class: 'A'}});
    assert.deepEqual(jobsLink('A', {from: null, to: 1790003600}), {path: '/jobs', query: {status: 'completed', class: 'A', to: '1790003600'}});
});

function seriesResponse(buckets) {
    return {
        name: 'A',
        as_of: NOW + 0.25,
        from: MINUTE - 59 * 60,
        to: NOW + 0.25,
        bucket_seconds: 60,
        retention_from: {completed: MINUTE - 7200.0, failed: MINUTE - 3000.0, pushed: MINUTE - 7200.0},
        buckets,
    };
}

// Rejects: a null bucket value turned into 0, a bucket without a time kept, and a default bucket size other than the response's.
test('normalizeSeries keeps unknown values null and drops broken buckets', () => {
    const data = normalizeSeries(seriesResponse([
        {at: MINUTE - 120, completed: 4, failed: null, pushed: '2', runtime_avg_ms: null, partial: false},
        {at: 'x', completed: 1},
        {at: MINUTE, completed: 1, failed: 0, pushed: 1, runtime_avg_ms: 10.5, partial: true},
    ]));

    assert.equal(data.bucketSeconds, 60);
    assert.deepEqual(data.buckets, [
        {at: MINUTE - 120, completed: 4, failed: null, pushed: 2, runtime_avg_ms: null, partial: false},
        {at: MINUTE, completed: 1, failed: 0, pushed: 1, runtime_avg_ms: 10.5, partial: true},
    ]);
    assert.equal(data.retentionFrom.failed, MINUTE - 3000);
    assert.equal(normalizeSeries(null), null);
    assert.equal(normalizeSeries({buckets: null}), null);
    assert.equal(normalizeSeries({...seriesResponse([]), bucket_seconds: 300}).bucketSeconds, 300);
    assert.equal(normalizeSeries({...seriesResponse([]), bucket_seconds: 0}).bucketSeconds, 60);
    assert.equal(normalizeSeries({...seriesResponse([]), bucket_seconds: null}).bucketSeconds, 60);
});

// Rejects: a chart whose failed band uses the completions' retention horizon (each kind has its own), or a hex colour instead of a token.
test('chartSeries gives each kind its own retention horizon', () => {
    const chart = chartSeries({completed: 10, failed: 20, pushed: 30});

    assert.deepEqual(chart.series, [{key: 'completed', label: 'completed', color: 'var(--color-accent)', retentionFrom: 10}]);
    assert.deepEqual(chart.band, {key: 'failed', label: 'failed', color: 'var(--color-danger)', retentionFrom: 20});
    assert.equal(chartSeries(null).series[0].retentionFrom, null);
});

// Rejects: a peak taken from the partial (current) minute, or from a minute whose failures are unknown; and an exact "peak" when a whole minute is absent or unknown (the true peak may be there).
test('peakText takes the busiest complete minute and says "≥" when a minute is unknown', () => {
    const series = (buckets, from = MINUTE - 180) => normalizeSeries({...seriesResponse(buckets), from});

    assert.equal(peakText(series([
        {at: MINUTE - 180, completed: 1, failed: 0, partial: false},
        {at: MINUTE - 120, completed: 400, failed: 12, partial: false},
        {at: MINUTE - 60, completed: 3, failed: 0, partial: false},
        {at: MINUTE, completed: 900, failed: 0, partial: true},
    ])), 'peak 412');

    // A whole minute absent (MINUTE - 60), or with an unknown count: a lower bound.
    assert.equal(peakText(series([
        {at: MINUTE - 180, completed: 1, failed: 0, partial: false},
        {at: MINUTE - 120, completed: 400, failed: 12, partial: false},
        {at: MINUTE, completed: 900, failed: 0, partial: true},
    ])), 'peak ≥ 412');
    assert.equal(peakText(series([
        {at: MINUTE - 180, completed: 1, failed: 0, partial: false},
        {at: MINUTE - 120, completed: 400, failed: 12, partial: false},
        {at: MINUTE - 60, completed: 500, failed: null, partial: false},
    ])), 'peak ≥ 412');

    assert.equal(peakText(series([])), null);
    assert.equal(peakText(null), null);
});

// Rejects: a sparkline window that ends after the current minute, so the partial minute is drawn as a complete one.
test('fullMinutes ends at the current minute and leaves the partial bucket out', () => {
    const minutes = fullMinutes(normalizeSeries(seriesResponse([
        {at: MINUTE - 60, completed: 1, failed: 0, pushed: 302, runtime_avg_ms: 10, partial: false},
        {at: MINUTE, completed: 1, failed: 0, pushed: 9, runtime_avg_ms: 10, partial: true},
    ])));

    assert.equal(minutes.to, MINUTE);
    assert.equal(minutes.from, MINUTE - 59 * 60);
    assert.deepEqual(minutes.buckets.map(bucket => bucket.at), [MINUTE - 60]);
    assert.equal(minutes.buckets[minutes.buckets.length - 1].at, MINUTE - 60);
    assert.equal(fullMinutes(null), null);
});

// Rejects: "no counted minute" for a class that a truncated list only cut off.
test('notListedText tells a cut list from a class without counted minutes', () => {
    assert.equal(notListedText({truncated: true, classes: new Array(500)}, 60), 'This class is not among the 500 busiest classes of the last 60 minutes.');
    assert.equal(notListedText({truncated: false, classes: []}, 15), 'This class has no counted minute in the last 15 minutes.');
});

// Rejects: the partial minute read as the last full minute, and an absent last minute shown as 0.
test('lastFullMinuteText reads the minute before the current one', () => {
    const series = normalizeSeries(seriesResponse([
        {at: MINUTE - 60, completed: 1, failed: 0, pushed: 302, runtime_avg_ms: 1200, partial: false},
        {at: MINUTE, completed: 1, failed: 0, pushed: 9, runtime_avg_ms: 10, partial: true},
    ]));

    assert.equal(lastFullMinuteText(series, 'pushed', inText), 'last full minute 302');
    assert.equal(lastFullMinuteText(series, 'runtime_avg_ms', runtimeText), 'last full minute 1.2 s');

    const gap = normalizeSeries(seriesResponse([{at: MINUTE, completed: 1, failed: 0, pushed: 9, runtime_avg_ms: 10, partial: true}]));

    assert.equal(lastFullMinuteText(gap, 'pushed', inText), 'last full minute —');

    // An older full minute is not the last one.
    const older = normalizeSeries(seriesResponse([
        {at: MINUTE - 120, completed: 1, failed: 0, pushed: 7, runtime_avg_ms: 10, partial: false},
        {at: MINUTE, completed: 1, failed: 0, pushed: 9, runtime_avg_ms: 10, partial: true},
    ]));

    assert.equal(lastFullMinuteText(older, 'pushed', inText), 'last full minute —');
    assert.equal(lastFullMinuteText(null, 'pushed', inText), null);
});

// Rejects: a compact row that drops "≥" on a lower bound, shows unknown arrivals as 0, or colours a zero failure count.
test('compactParts gives the two-line row of a narrow screen', () => {
    const parts = compactParts({name: 'A', completed: 18204, failed: 27, pushed: null, runtimeAvgMs: 1234.5, complete: false});

    assert.deepEqual(parts, [
        {key: 'completed', text: '≥ 18,204 done', tone: null},
        {key: 'failed', text: '≥ 27 failed', tone: 'danger'},
        {key: 'pushed', text: '— in', tone: null},
        {key: 'runtime', text: '1.2 s avg', tone: null},
    ]);

    const quiet = compactParts({name: 'B', completed: 0, failed: 0, pushed: 60, runtimeAvgMs: null, complete: true});

    assert.equal(quiet[1].tone, null);

    // Known arrivals are an exact sum: no "≥" even when the row is incomplete.
    assert.equal(compactParts({name: 'C', completed: 1, failed: 0, pushed: 60, runtimeAvgMs: null, complete: false})[2].text, '60 in');
    assert.equal(quiet[3].text, 'runtime —');
});

// Rejects: a raw status or server message shown instead of the operator wording of the error code.
test('classFailure words the read errors', () => {
    assert.equal(classFailure({response: {status: 503, data: {error: 'read_timeout'}}}), 'A read took too long. Narrow the selection and try again.');
    assert.equal(classFailure({response: {status: 503, data: {error: 'read_failed'}}}), "Orrery's storage could not be read. Try again.");
    assert.equal(classFailure({response: {status: 500, data: '<html>'}}), 'The server answered with an error (HTTP 500). Try again.');
    assert.equal(classFailure(new Error('x')), 'The server did not answer. Try again.');
    assert.equal(classFailure(unexpectedAnswer()), 'The server sent an unexpected answer. Try again.');
});

// Rejects: a live announcement that changes on every poll (the window's times move each minute), or one for a complete window.
test('unknownAnnouncement depends only on the count', () => {
    assert.equal(unknownAnnouncement(null), '');
    assert.equal(unknownAnnouncement({count: 3, first: MINUTE - 900, last: MINUTE - 780}), '3 minutes in this window could not be counted.');
    assert.equal(unknownAnnouncement({count: 3, first: MINUTE - 840, last: MINUTE - 720}), '3 minutes in this window could not be counted.');
    assert.equal(unknownAnnouncement({count: 1, first: MINUTE, last: MINUTE}), '1 minute in this window could not be counted.');
});

// Rejects: a window other than the route's, or a series of another span than the screen's first one.
test('classesFirstRequests: the classes of the window, and the selected class\'s series', () => {
    assert.equal(DEFAULT_SPAN, '1h');
    assert.deepEqual(classesFirstRequests({minutes: '15', class: 'App\\Jobs\\X'}), [
        {url: '/api/classes', params: {minutes: 15}},
        {url: '/api/classes/series', params: {name: 'App\\Jobs\\X', minutes: 60}},
    ]);
    assert.deepEqual(classesFirstRequests({}), [{url: '/api/classes', params: {minutes: 60}}]);
});

// Rejects: a screen and a route load that build the class reads apart (drift: a read twice).
test('classesRead and seriesRead: one builder for the screen and the first load', () => {
    assert.deepEqual(classesFirstRequests({minutes: '15', class: 'X'}), [classesRead(15), seriesRead('X', DEFAULT_SPAN)]);
    assert.deepEqual(seriesRead('X', '24h'), {url: '/api/classes/series', params: {name: 'X', minutes: 1440}});
});
