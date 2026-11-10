// Tests of resources/js/support/series.js.

import {test} from 'node:test';
import assert from 'node:assert/strict';

import {axisTicks, zoneLabelSpace, bucketsByStart, findDip, findPeak, niceAxis, slotStarts, slotValue} from '../../resources/js/support/series.js';

// Rejects: slots from `from` instead of the aligned start, and a closed window that adds the slot at `to`.
test('slotStarts aligns the slots of a half-open window', () => {
    assert.deepEqual(slotStarts(120, 300, 60), [120, 180, 240]);
    assert.deepEqual(slotStarts(130, 300, 60), [120, 180, 240]);
    assert.deepEqual(slotStarts(130, 301, 60), [120, 180, 240, 300]);
    assert.deepEqual(slotStarts(300, 300, 60), []);
    assert.deepEqual(slotStarts(300, 200, 60), []);
    assert.deepEqual(slotStarts(0, 100, 0), []);
    assert.deepEqual(slotStarts(NaN, 100, 60), []);
    assert.equal(slotStarts(0, 1e9, 1).length, 10000, 'the slot count is bounded');
});

// Rejects: a bucket without a numeric start that is kept, and an array instead of a map.
test('bucketsByStart keys the buckets by their start', () => {
    const map = bucketsByStart([{at: 60, a: 1}, {at: '120', a: 2}, null, {at: 180, a: 3}]);

    assert.deepEqual([...map.keys()], [60, 180]);
    assert.equal(map.get(180).a, 3);
    assert.equal(bucketsByStart(null).size, 0);
});

// Rejects: 0 for a missing bucket, a null value, or a slot before the retention horizon.
test('slotValue is null for every unknown slot, never zero', () => {
    assert.equal(slotValue(undefined, 'completed', 120, null, 60), null);
    assert.equal(slotValue({completed: null}, 'completed', 120, null, 60), null);
    assert.equal(slotValue({completed: '5'}, 'completed', 120, null, 60), null);
    assert.equal(slotValue({completed: NaN}, 'completed', 120, null, 60), null);
    assert.equal(slotValue({}, 'completed', 120, null, 60), null);
});

// Rejects: a known zero turned into null.
test('slotValue returns a known value, also zero', () => {
    assert.equal(slotValue({completed: 0}, 'completed', 120, null, 60), 0);
    assert.equal(slotValue({completed: 7}, 'completed', 120, 100, 60), 7);
});

// Rejects: a horizon test on the slot start (a slot that straddles the horizon is partly known),
// and a test with `<` that keeps a slot that ends exactly at the horizon.
test('slotValue drops a slot that ends at or before the retention horizon', () => {
    assert.equal(slotValue({completed: 7}, 'completed', 60, 120, 60), null, 'ends at the horizon');
    assert.equal(slotValue({completed: 7}, 'completed', 0, 120, 60), null, 'ends before the horizon');
    assert.equal(slotValue({completed: 7}, 'completed', 90, 120, 60), 7, 'straddles the horizon');
    assert.equal(slotValue({completed: 7}, 'completed', 121, 120, 0), 7, 'no bucket width: the start decides');
    assert.equal(slotValue({completed: 7}, 'completed', 120, 120, 0), null, 'no bucket width, at the horizon');
});

// Rejects: a partial bucket that wins, and a non-numeric value that wins.
test('findPeak picks the largest complete bucket', () => {
    const buckets = [
        {at: 0, n: 3},
        {at: 60, n: 9},
        {at: 120, n: 50, partial: true},
        {at: 180, n: '99'},
        {at: 240, n: 9},
    ];

    assert.deepEqual(findPeak(buckets, 'n'), {at: 60, value: 9});
    assert.equal(findPeak([], 'n'), null);
    assert.equal(findPeak(null, 'n'), null);
});

// Rejects: a dip across a missing bucket, and a dip that ignores the ratio.
test('findDip finds the longest consecutive run below the median ratio', () => {
    const buckets = [
        {at: 0, n: 10},
        {at: 60, n: 2},
        {at: 120, n: 1},
        {at: 180, n: 10},
        {at: 240, n: 3},
        // 300 is missing, so the run at 240 ends.
        {at: 360, n: 3},
        {at: 420, n: 10},
        {at: 480, n: 10},
    ];

    assert.deepEqual(findDip(buckets, 'n', 60), {from: 60, to: 120, value: 1});
    assert.equal(findDip(buckets.slice(0, 2), 'n', 60), null, 'fewer than three buckets');
    assert.equal(findDip([{at: 0, n: 5}, {at: 60, n: 5}, {at: 120, n: 5}], 'n', 60), null, 'no bucket below the limit');
});

// Rejects: a step that does not reach max, and a 3-tick axis without the 2.5 step.
test('niceAxis rounds the step up to 1, 2, 2.5 or 5 × 10^n', () => {
    assert.deepEqual(niceAxis(0), {max: 3, step: 1});
    assert.deepEqual(niceAxis(null), {max: 3, step: 1});
    assert.deepEqual(niceAxis(3), {max: 3, step: 1});
    assert.deepEqual(niceAxis(7), {max: 7.5, step: 2.5});
    assert.deepEqual(niceAxis(100), {max: 150, step: 50});
    assert.deepEqual(niceAxis(4, 4), {max: 4, step: 1});
});

// Rejects: ticks only on slot starts that are round local times (hourly slots start at :30 local in a
// UTC+5:30 zone, so a 24 h chart had no x label at all), a tick outside its slot, and a tick of a later slot
// for the round time of an earlier one.
test('axisTicks finds the round local times inside slots that do not start on one', () => {
    const day = Date.UTC(2026, 8, 28) / 1000;
    const starts = Array.from({length: 24}, (_, index) => day + index * 3600);
    const india = axisTicks(starts, 3600, () => 19800);

    assert.equal(india.interval, 21600);
    assert.ok(india.ticks.length >= 3);
    india.ticks.forEach(tick => {
        assert.equal(((tick.at + 19800) % 21600 + 21600) % 21600, 0);
        assert.ok(tick.at >= tick.slot && tick.at < tick.slot + 3600);
        assert.equal(tick.onStart, false);
    });

    const utc = axisTicks(starts, 3600, () => 0);

    assert.deepEqual(utc.ticks.map(tick => tick.at), [day, day + 21600, day + 43200, day + 64800]);
    assert.ok(utc.ticks.every(tick => tick.onStart));
});

// Rejects: a bare "00:00" for the ticks of a multi-day chart (seven "00:00" labels on the 7 d chart), a date
// on every tick of a one-hour chart, and the seven daily ticks of a week that do not fit.
test('axisTicks marks the ticks that need a date', () => {
    const day = Date.UTC(2026, 8, 21) / 1000;
    const week = Array.from({length: 7 * 24}, (_, index) => day + index * 3600);
    const ticks = axisTicks(week, 3600, () => 0);

    assert.ok(ticks.ticks.length <= 5);
    assert.ok(ticks.ticks.every(tick => tick.date));

    const daySlots = Array.from({length: 24 * 4}, (_, index) => day + index * 900);
    const full = axisTicks(daySlots, 900, () => 0);

    assert.deepEqual(full.ticks.map(tick => tick.date), [true, false, false, false]);

    const hour = Array.from({length: 60}, (_, index) => day + index * 60);

    assert.ok(axisTicks(hour, 60, () => 0).ticks.every(tick => !tick.date));
    assert.deepEqual(axisTicks([], 60, () => 0).ticks, []);
});

// Rejects: a zone label anchored at its end left of the value axis (a "GMT+2" or "GMT+5:30" runs past the
// chart's left edge and is cut), and a first time label that overlaps the zone label.
test('zoneLabelSpace keeps the zone label inside the chart and the first time label clear of it', () => {
    const short = zoneLabelSpace('UTC', 44);

    assert.deepEqual(short, {x: 0, anchor: 'start', minLabelX: 58});

    const long = zoneLabelSpace('GMT+5:30', 44);

    assert.equal(long.x, 0);
    assert.equal(long.anchor, 'start');
    assert.ok(long.minLabelX >= 8 * 7 + 20, 'the first time label starts after the zone label');
});
