// Tests of resources/js/support/gauge.js.

import {test} from 'node:test';
import assert from 'node:assert/strict';

import {arcPath, arcPoint, gaugeFraction, limitMultiple, SCALE_LIMITS, tickPath, ZONES} from '../../resources/js/support/gauge.js';

// Rejects: a scale end other than 4 limits, and zones that leave a part of the arc uncovered.
test('the scale ends at four limits and the zones cover the arc', () => {
    assert.equal(SCALE_LIMITS, 4);
    assert.equal(ZONES[0][0], 0);
    assert.equal(ZONES.at(-1)[1], 1);
});

// Rejects: 0 for an unknown age or no limit, and a fraction past the scale end.
test('gaugeFraction places the age on a scale of four limits', () => {
    assert.equal(gaugeFraction(0, 60), 0);
    assert.equal(gaugeFraction(60, 60), 0.25);
    assert.equal(gaugeFraction(120, 60), 0.5);
    assert.equal(gaugeFraction(1000, 60), 1);
    assert.equal(gaugeFraction(-5, 60), 0);
    assert.equal(gaugeFraction(null, 60), null);
    assert.equal(gaugeFraction('30', 60), null);
    assert.equal(gaugeFraction(30, 0), null);
    assert.equal(gaugeFraction(30, null), null);
});

// Rejects: an arc that starts on the right, or a y axis that points up.
test('arcPoint runs from the left over the top to the right', () => {
    const left = arcPoint(50, 50, 40, 0);
    const top = arcPoint(50, 50, 40, 0.5);
    const right = arcPoint(50, 50, 40, 1);

    assert.ok(Math.abs(left.x - 10) < 1e-9 && Math.abs(left.y - 50) < 1e-9);
    assert.ok(Math.abs(top.x - 50) < 1e-9 && Math.abs(top.y - 10) < 1e-9);
    assert.ok(Math.abs(right.x - 90) < 1e-9 && Math.abs(right.y - 50) < 1e-9);
});

// Rejects: a sweep flag that draws the arc under the centre, and a tick that points inward.
test('arcPath and tickPath write SVG paths with two decimals', () => {
    assert.equal(arcPath(50, 50, 40, 0, 1), 'M10.00 50.00A40 40 0 0 1 90.00 50.00');
    assert.equal(tickPath(50, 50, 40, 0.5, 5), 'M50.00 15.00L50.00 5.00');
});

// Rejects: a multiple without the sign, a decimal past 10×, and a value without a limit.
test('limitMultiple writes the age as a multiple of the limit', () => {
    assert.equal(limitMultiple(132, 60), '2.2×');
    assert.equal(limitMultiple(120, 60), '2×');
    assert.equal(limitMultiple(0, 60), '0×');
    assert.equal(limitMultiple(625, 60), '10×');
    assert.equal(limitMultiple(30, 0), null);
    assert.equal(limitMultiple(null, 60), null);
});
