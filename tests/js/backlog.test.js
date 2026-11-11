// Tests of resources/js/screens/queues/backlog.js: the rules of the backlog
// charts of the Queues screen. `pnpm test` runs in UTC.

import {test} from 'node:test';
import assert from 'node:assert/strict';

import {
    BACKLOG_RANGES,
    BACKLOG_RANGE_KEY,
    BACKLOG_SCALE_KEY,
    BACKLOG_SCALES,
    DEFAULT_BACKLOG_RANGE,
    backlogMarkersRequest,
    backlogDescription,
    backlogGaps,
    backlogPollSeconds,
    backlogRange,
    backlogScale,
    backlogScaleMode,
    backlogSegments,
    backlogSlots,
    backlogTicks,
    cardTitle,
    historyRowsOf,
    nearestSlot,
    partialSlots,
    queuesFirstRequests,
    rangeLabel,
    readBacklogChoice,
    slotSecondsFor,
    slotTime,
    slotTip,
    stepSlot,
    writeBacklogChoice,
} from '../../resources/js/screens/queues/backlog.js';

const T = 1_800_000_000; // a minute boundary (divisible by 60, 180 and 720)

/** A fake Storage. */
function memory(entries = {}) {
    const map = new Map(Object.entries(entries));

    return {
        getItem: key => (map.has(key) ? map.get(key) : null),
        setItem: (key, value) => map.set(key, String(value)),
        removeItem: key => map.delete(key),
        map,
    };
}

const throwing = {
    getItem() {
        throw new Error('SecurityError');
    },
    setItem() {
        throw new Error('QuotaExceededError');
    },
};

// Rejects: a preset beyond what the history endpoint serves (1440 minutes, the default samples.retention_hours),
// presets out of order, or a default that is not a preset.
// Still passes: presets with other labels.
test('the range presets fit the history endpoint and the default is one of them', () => {
    assert.deepEqual(BACKLOG_RANGES.map(range => range.minutes), [15, 60, 360, 1440]);
    assert.ok(BACKLOG_RANGES.every(range => range.minutes >= 5 && range.minutes <= 1440));
    assert.equal(backlogRange(DEFAULT_BACKLOG_RANGE).key, '1h');
    assert.deepEqual(BACKLOG_SCALES.map(scale => scale.key), ['shared', 'queue']);
});

// Rejects: a stored value that is not a preset used as one (an old or edited value would send minutes=undefined),
// or a scale mode other than the two.
// Still passes: a rule that accepts the key in another case (keys are lower case).
test('backlogRange and backlogScaleMode fall back to the defaults', () => {
    assert.equal(backlogRange('6h').minutes, 360);
    assert.equal(backlogRange('7d').key, '1h');
    assert.equal(backlogRange(null).key, '1h');
    assert.equal(backlogScaleMode('queue'), 'queue');
    assert.equal(backlogScaleMode('log'), 'shared');
    assert.equal(backlogScaleMode(undefined), 'shared');
});

// Rejects: a read or write that throws when the storage throws (a private window), or a key that is not
// scoped by the base path (support/storage.js).
// Still passes: a write that stores a value that the read then refuses (only presets are written).
test('readBacklogChoice and writeBacklogChoice keep the choice per browser, without throwing', () => {
    const storage = memory();

    assert.deepEqual(readBacklogChoice(storage, '/orrery'), {range: '1h', scale: 'shared'});
    assert.equal(writeBacklogChoice({range: '24h', scale: 'queue'}, storage, '/orrery'), true);
    assert.equal(storage.map.get(`${BACKLOG_RANGE_KEY}@/orrery`), '24h');
    assert.equal(storage.map.get(`${BACKLOG_SCALE_KEY}@/orrery`), 'queue');
    assert.deepEqual(readBacklogChoice(storage, '/orrery'), {range: '24h', scale: 'queue'});
    assert.deepEqual(readBacklogChoice(storage, '/other'), {range: '1h', scale: 'shared'});

    assert.deepEqual(readBacklogChoice(throwing, '/orrery'), {range: '1h', scale: 'shared'});
    assert.equal(writeBacklogChoice({range: '6h', scale: 'shared'}, throwing, '/orrery'), false);
});

// Rejects: one-minute slots on a day (1,440 points in a small card), or slots that do not divide a day.
// Still passes: other slot sizes with the same bound.
test('slotSecondsFor keeps at most 120 slots, each a whole number of minutes', () => {
    assert.equal(slotSecondsFor(15), 60);
    assert.equal(slotSecondsFor(60), 60);
    assert.equal(slotSecondsFor(360), 180);
    assert.equal(slotSecondsFor(1440), 720);

    for (const range of BACKLOG_RANGES) {
        const slot = slotSecondsFor(range.minutes);

        assert.ok((range.minutes * 60) / slot <= 120);
        assert.equal(slot % 60, 0);
        assert.equal(86400 % slot, 0);
    }
});

// Rejects: a poll as fast on a day as on an hour (a day's history is 24 times as large per queue).
// Still passes: other intervals that grow with the range.
test('backlogPollSeconds polls longer ranges less often', () => {
    assert.equal(backlogPollSeconds('15m'), 30);
    assert.equal(backlogPollSeconds('1h'), 30);
    assert.ok(backlogPollSeconds('6h') > 30);
    assert.ok(backlogPollSeconds('24h') >= backlogPollSeconds('6h'));
});

// Rejects: a gap drawn as 0 (a missing minute, a null ready, a minute behind the retention horizon), a slot of
// several minutes that takes their sum or their last value instead of the largest known one, or a slot that
// forgets how many of its minutes were unknown.
// Still passes: a slot that keeps the partial flag of its current minute (not read by the chart).
test('backlogSlots: the largest known value per slot, and null for a slot without a known minute', () => {
    const window = {from: T, to: T + 360, retentionFrom: T + 60};
    const buckets = [
        {at: T, ready: 9}, // behind the horizon: unknown
        {at: T + 60, ready: 4},
        {at: T + 120, ready: null},
        {at: T + 180, ready: 0},
        {at: T + 240, ready: 7},
    ];

    const minutes = backlogSlots(window, buckets, 60);

    assert.deepEqual(minutes.map(slot => slot.value), [null, 4, null, 0, 7, null]);
    assert.equal(minutes[3].value, 0, 'a known zero stays zero');
    assert.deepEqual(minutes.map(slot => slot.at), [T, T + 60, T + 120, T + 180, T + 240, T + 300]);

    const wide = backlogSlots(window, buckets, 180);

    assert.deepEqual(wide.map(slot => slot.value), [4, 7]);
    assert.deepEqual(wide.map(slot => [slot.minutes, slot.unknownMinutes]), [[3, 2], [3, 1]]);
    assert.deepEqual(wide.map(slot => [slot.at, slot.end]), [[T, T + 180], [T + 180, T + 360]]);

    // The peak of a wide slot, not its last or first value.
    const peak = backlogSlots({from: T, to: T + 180, retentionFrom: null}, [{at: T, ready: 2}, {at: T + 60, ready: 9}, {at: T + 120, ready: 5}], 180);

    assert.deepEqual(peak.map(slot => slot.value), [9]);

    assert.deepEqual(backlogSlots(null, buckets, 60), []);
});

// Rejects: a shared scale that ignores other cards, a per-queue scale that uses them (a small queue stays flat),
// a scale of 0 (a division by zero), or a top that is not a round number for the label.
// Still passes: other round steps.
test('backlogScale: one round top for all cards, or one per queue', () => {
    const slots = {
        big: [{value: 940}, {value: null}],
        small: [{value: 3}, {value: 1}],
        empty: [{value: null}],
    };

    assert.equal(backlogScale('shared', slots, 'small'), 1000);
    assert.equal(backlogScale('shared', slots, 'big'), 1000);
    assert.equal(backlogScale('queue', slots, 'small'), 4);
    assert.equal(backlogScale('queue', slots, 'big'), 1000);
    assert.ok(backlogScale('queue', slots, 'empty') > 0);
    assert.ok(backlogScale('shared', {}, 'none') > 0);
});

// Rejects: a pointer position that picks a slot off by one at the edges, or an index outside the slots.
// Still passes: a rule that rounds at the exact slot border either way.
test('nearestSlot maps a position in the chart to its slot', () => {
    const slots = new Array(10).fill(null).map((_, index) => ({index}));

    assert.equal(nearestSlot(slots, 0), 0);
    assert.equal(nearestSlot(slots, 0.05), 0);
    assert.equal(nearestSlot(slots, 0.15), 1);
    assert.equal(nearestSlot(slots, 0.999), 9);
    assert.equal(nearestSlot(slots, 1.4), 9);
    assert.equal(nearestSlot(slots, -1), 0);
    assert.equal(nearestSlot([], 0.5), null);
});

// Rejects: Left and Right that leave the slots, keys that the chart should leave alone (Tab, Enter: the card's
// own select), or no start on the newest slot (the value the card shows).
// Still passes: Home and End that also scroll (the chart does not scroll).
test('stepSlot moves the point with Left, Right, Home and End', () => {
    assert.equal(stepSlot(null, 'ArrowLeft', 5), 4);
    assert.equal(stepSlot(null, 'ArrowRight', 5), 4);
    assert.equal(stepSlot(2, 'ArrowLeft', 5), 1);
    assert.equal(stepSlot(0, 'ArrowLeft', 5), 0);
    assert.equal(stepSlot(4, 'ArrowRight', 5), 4);
    assert.equal(stepSlot(2, 'Home', 5), 0);
    assert.equal(stepSlot(2, 'End', 5), 4);
    assert.equal(stepSlot(2, 'Enter', 5), undefined);
    assert.equal(stepSlot(2, 'Tab', 5), undefined);
    assert.equal(stepSlot(null, 'ArrowLeft', 0), undefined);
});

// Rejects: an unknown slot shown as "0 ready", a wide slot that hides that it is a maximum, a slot with
// unknown minutes that does not say so, or a slot of an earlier day without its date (6 h and 24 h views).
// Still passes: other wording of the same facts.
test('slotTip says the value, unknown, or the largest known value of a wide slot, with the date of an earlier day', () => {
    const today = T + 3600;

    assert.deepEqual(slotTip({at: T, end: T + 60, value: 12, minutes: 1, unknownMinutes: 0}, today), {value: '12 ready', time: '08:00 UTC', note: null});
    assert.deepEqual(slotTip({at: T, end: T + 60, value: null, minutes: 1, unknownMinutes: 1}, today), {value: 'unknown', time: '08:00 UTC', note: 'no data for this minute'});
    assert.deepEqual(slotTip({at: T, end: T + 720, value: 1234, minutes: 12, unknownMinutes: 0}, today), {value: 'up to 1,234 ready', time: '08:00–08:12 UTC', note: null});
    assert.deepEqual(slotTip({at: T, end: T + 720, value: 3, minutes: 12, unknownMinutes: 5}, today), {value: 'up to at least 3 ready', time: '08:00–08:12 UTC', note: '5 of 12 minutes without data'});
    assert.deepEqual(slotTip({at: T, end: T + 720, value: null, minutes: 12, unknownMinutes: 12}, today), {value: 'unknown', time: '08:00–08:12 UTC', note: 'no data in this period'});

    // The previous day: the date is part of the time.
    const tomorrow = T + 86400;

    assert.equal(slotTip({at: T, end: T + 720, value: 3, minutes: 12, unknownMinutes: 0}, tomorrow).time, '2027-01-15 08:00–08:12 UTC');
    assert.equal(slotTip({at: T, end: T + 60, value: 3, minutes: 1, unknownMinutes: 0}, tomorrow).time, '2027-01-15 08:00 UTC');
    assert.equal(slotTime({at: T, end: T + 720, minutes: 12}, today), '08:00–08:12 UTC');
});

const slotsOf = values => values.map((value, index) => ({index, at: T + index * 60, end: T + (index + 1) * 60, value, minutes: 1, unknownMinutes: value === null ? 1 : 0}));

// Rejects: a line joined across an unknown slot, or an unknown slot drawn as a point at 0 (the baseline).
// Still passes: segments that carry more fields per slot.
test('backlogSegments: one segment per run of known slots, never through a gap', () => {
    const segments = backlogSegments(slotsOf([4, null, 4]));

    assert.equal(segments.length, 2);
    assert.deepEqual(segments.map(segment => segment.map(slot => slot.index)), [[0], [2]]);
    assert.ok(segments.flat().every(slot => slot.value !== null));
    assert.deepEqual(backlogSegments(slotsOf([0, 1])).map(segment => segment.length), [2], 'a known 0 is drawn');
    assert.deepEqual(backlogSegments(slotsOf([null, null])), []);
});

// Rejects: gaps that split one run of unknown slots, or that miss the time of their last slot.
test('backlogGaps: one gap per run of unknown slots, with its first and last slot', () => {
    assert.deepEqual(backlogGaps(slotsOf([null, null, 1, null])), [
        {from: 0, to: 1, first: T, end: T + 120},
        {from: 3, to: 3, first: T + 180, end: T + 240},
    ]);
    assert.deepEqual(backlogGaps(slotsOf([1, 2])), []);
});

// Rejects: a partly unknown wide slot that looks fully known (not listed), or an unknown slot listed as partial.
test('partialSlots: the known slots with unknown minutes', () => {
    const slots = [
        {index: 0, value: 3, minutes: 12, unknownMinutes: 5},
        {index: 1, value: 3, minutes: 12, unknownMinutes: 0},
        {index: 2, value: null, minutes: 12, unknownMinutes: 12},
    ];

    assert.deepEqual(partialSlots(slots).map(slot => slot.index), [0]);
});

// Rejects: a description that reads a peak as an exact value, that hides partly unknown periods or gaps, or that
// gives a time of an earlier day without its date.
// Still passes: other wording of the same facts.
test('backlogDescription names the latest value, the gaps, the partly unknown periods and the markers', () => {
    const wide = [
        {index: 0, at: T, end: T + 720, value: 3, minutes: 12, unknownMinutes: 5},
        {index: 1, at: T + 720, end: T + 1440, value: null, minutes: 12, unknownMinutes: 12},
        {index: 2, at: T + 1440, end: T + 2160, value: 9, minutes: 12, unknownMinutes: 0},
    ];

    assert.equal(
        backlogDescription('default: ready jobs, past 24 hours', wide, ['Deploy v2 08:30 UTC'], T + 86400),
        'default: ready jobs, past 24 hours, up to 9 at 2027-01-15 08:24–08:36 UTC, 1 gap without data, 1 period partly without data (values are at least), Deploy v2 08:30 UTC.',
    );
    assert.equal(backlogDescription('x', slotsOf([null]), [], T), 'x, no data, 1 gap without data.');
    assert.equal(
        backlogDescription('x', [{index: 0, at: T, end: T + 720, value: 4, minutes: 12, unknownMinutes: 2}], [], T + 3600),
        'x, up to at least 4 at 08:00–08:12 UTC, 1 period partly without data (values are at least).',
        'a partly unknown latest slot is a lower bound',
    );
    assert.equal(backlogDescription('x', slotsOf([5]), [], T + 60), 'x, 5 at 08:00 UTC.');
});
// Rejects: more labels than a small card holds, labels off the round times of the display zone, or labels outside
// the window.
// Still passes: a thinner set of labels on a wide card (the count is fixed).
test('backlogTicks gives at most three round times inside the window', () => {
    const starts = [];

    for (let at = T; at < T + 3600; at += 60) starts.push(at);

    const ticks = backlogTicks(starts, 60, () => 0);

    assert.ok(ticks.length >= 2 && ticks.length <= 3, `got ${ticks.length}`);
    assert.ok(ticks.every(tick => tick.at >= T && tick.at < T + 3600));
    assert.ok(ticks.every(tick => tick.at % 900 === 0));
    assert.ok(ticks.length < 4, 'four labels crowd a 170 px card');
    assert.ok(ticks.every(tick => tick.fraction >= 0 && tick.fraction < 1));
    assert.equal(ticks[0].label, '08:00');

    // In a zone with a 30 minute offset the labels fall on its round times.
    const offset = backlogTicks(starts, 60, () => 1800);

    assert.ok(offset.every(tick => (tick.at + 1800) % 900 === 0));

    const day = [];

    for (let at = T; at < T + 86400; at += 720) day.push(at);

    assert.ok(backlogTicks(day, 720, () => 0).length <= 3);
    assert.deepEqual(backlogTicks([], 60, () => 0), []);
});

// Rejects: a title that is only the queue on one card and connection:queue on another (the old cardName()), or
// a group that is not the connections of the sidebar group ("database, database_b").
// Still passes: another separator in the group.
test('cardTitle: the queue, and its connections as the sidebar groups them', () => {
    assert.deepEqual(cardTitle({name: 'long', connection: 'database', connections: ['database']}), {name: 'long', group: 'database'});
    assert.deepEqual(cardTitle({name: 'default', connection: 'database', connections: ['database', 'database_b']}), {name: 'default', group: 'database, database_b'});
    assert.deepEqual(cardTitle({name: 'default', connection: 'database'}), {name: 'default', group: 'database'});
});

// Rejects: a subtitle that says "past hour" for another range.
// Still passes: other wording per preset.
test('rangeLabel names the window of a preset', () => {
    assert.equal(rangeLabel('15m'), 'past 15 minutes');
    assert.equal(rangeLabel('1h'), 'past hour');
    assert.equal(rangeLabel('6h'), 'past 6 hours');
    assert.equal(rangeLabel('24h'), 'past 24 hours');
    assert.equal(rangeLabel('x'), 'past hour');
});

// Rejects: a markers window that ignores the range (a 24 h chart would miss its markers), or one without its
// tolerance key.
test('backlogMarkersRequest: the markers since the start of the range, two minutes before it', () => {
    assert.deepEqual(backlogMarkersRequest(T + 0.5, '6h'), {url: '/api/markers', params: {since: T - 360 * 60 - 120}, timeKeys: ['since']});
});

// Rejects: a history list without the selected queue, or with it twice.
test('historyRowsOf: the cards on screen, and the selected queue after them', () => {
    const rows = ['a', 'b', 'c'].map(key => ({key}));

    assert.deepEqual(historyRowsOf(rows, 2, rows[2]).map(row => row.key), ['a', 'b', 'c']);
    assert.deepEqual(historyRowsOf(rows, 2, rows[0]).map(row => row.key), ['a', 'b']);
    assert.deepEqual(historyRowsOf(rows, 2, null).map(row => row.key), ['a', 'b']);
});

// Rejects: histories of more cards than the screen starts at once (prefetches it would not take in time), of
// another range, or none for the cards when the workload is known.
// Still passes: the history reads in another order.
test('queuesFirstRequests: the markers, the retention, and the first cards\' histories', () => {
    const workload = ['a', 'b', 'c', 'd', 'e'].map(name => ({name, connection: 'database', length: 1}));
    const requests = queuesFirstRequests(workload, {}, '24h', T);

    assert.deepEqual(requests.slice(0, 2), [
        {url: '/api/markers', params: {since: T - 1440 * 60 - 120}, timeKeys: ['since']},
        {url: '/api/settings/retention', params: {}},
    ]);
    assert.deepEqual(requests.slice(2).map(request => [request.url, request.params.queue, request.params.minutes]), [
        ['/api/queues/history', 'a', 1440], ['/api/queues/history', 'b', 1440], ['/api/queues/history', 'c', 1440], ['/api/queues/history', 'd', 1440],
    ]);
    assert.equal(queuesFirstRequests(null, {}, '1h', T).length, 2);
});
