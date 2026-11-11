// Tests of resources/js/support/sidebar-width.js.

import {test} from 'node:test';
import assert from 'node:assert/strict';

import {
    SIDEBAR_DEFAULT_WIDTH,
    SIDEBAR_MAX_WIDTH,
    SIDEBAR_MIN_WIDTH,
    clampSidebarWidth,
    readSidebarWidth,
    sidebarMaxWidth,
    sidebarWidthForKey,
    sidebarWidthText,
    writeSidebarWidth,
} from '../../resources/js/support/sidebar-width.js';

const throwing = {
    getItem() { throw new Error('SecurityError'); },
    setItem() { throw new Error('QuotaExceededError'); },
    removeItem() { throw new Error('SecurityError'); },
};

function memory() {
    const values = new Map();

    return {
        values,
        getItem: key => (values.has(key) ? values.get(key) : null),
        setItem: (key, value) => values.set(key, String(value)),
        removeItem: key => values.delete(key),
    };
}

// Rejects: a maximum above 480 px or above half the viewport, and a maximum below the minimum on a
// small window. It would still pass a minimum of any value below the default.
test('sidebarMaxWidth is at most 480 px and at most half the viewport, never below the minimum', () => {
    assert.equal(SIDEBAR_DEFAULT_WIDTH, 236);
    assert.ok(SIDEBAR_MIN_WIDTH < SIDEBAR_DEFAULT_WIDTH);
    assert.equal(SIDEBAR_MAX_WIDTH, 480);
    assert.equal(sidebarMaxWidth(2560), 480);
    assert.equal(sidebarMaxWidth(1200), 480);
    assert.equal(sidebarMaxWidth(1100), 480);
    assert.equal(sidebarMaxWidth(900), 450);
    assert.equal(sidebarMaxWidth(300), SIDEBAR_MIN_WIDTH);
    assert.equal(sidebarMaxWidth(undefined), SIDEBAR_MAX_WIDTH);
});

// Rejects: an unclamped width, a fractional width, and a non-number taken as a width.
test('clampSidebarWidth keeps the width within the bounds, whole, else the default', () => {
    assert.equal(clampSidebarWidth(100, 1600), SIDEBAR_MIN_WIDTH);
    assert.equal(clampSidebarWidth(900, 1600), 480);
    assert.equal(clampSidebarWidth(420, 800), 400);
    assert.equal(clampSidebarWidth(300.6, 1600), 301);
    assert.equal(clampSidebarWidth(Number.NaN, 1600), SIDEBAR_DEFAULT_WIDTH);
    assert.equal(clampSidebarWidth('300', 1600), SIDEBAR_DEFAULT_WIDTH);
});

// Rejects: arrows in the wrong direction, Shift without a bigger step, Home and End ignored, other keys
// handled (which would swallow Tab), and a step past the bounds.
test('sidebarWidthForKey moves the width with the arrows, Shift for bigger steps', () => {
    assert.equal(sidebarWidthForKey(300, 'ArrowRight', false, 1600), 308);
    assert.equal(sidebarWidthForKey(300, 'ArrowLeft', false, 1600), 292);
    assert.equal(sidebarWidthForKey(300, 'ArrowRight', true, 1600), 340);
    assert.equal(sidebarWidthForKey(300, 'ArrowLeft', true, 1600), 260);
    assert.equal(sidebarWidthForKey(478, 'ArrowRight', true, 1600), 480);
    assert.equal(sidebarWidthForKey(300, 'Home', false, 1600), SIDEBAR_MIN_WIDTH);
    assert.equal(sidebarWidthForKey(300, 'End', false, 1600), 480);
    assert.equal(sidebarWidthForKey(300, 'Tab', false, 1600), null);
    assert.equal(sidebarWidthForKey(300, 'Enter', false, 1600), SIDEBAR_DEFAULT_WIDTH);
});

// Rejects: a stored width read without bounds, a broken value taken as a width, a storage that throws
// into the page, and a default width kept in the storage.
test('readSidebarWidth and writeSidebarWidth persist the width and survive a throwing storage', () => {
    const storage = memory();

    assert.equal(readSidebarWidth(1600, storage, ''), SIDEBAR_DEFAULT_WIDTH);
    assert.equal(writeSidebarWidth(320, storage, ''), true);
    assert.equal(readSidebarWidth(1600, storage, ''), 320);
    assert.equal(readSidebarWidth(500, storage, ''), sidebarMaxWidth(500));

    storage.setItem('orrerySidebarWidth', 'wide');
    assert.equal(readSidebarWidth(1600, storage, ''), SIDEBAR_DEFAULT_WIDTH);

    writeSidebarWidth(320, storage, '');
    assert.equal(writeSidebarWidth(SIDEBAR_DEFAULT_WIDTH, storage, ''), true);
    assert.equal(storage.values.size, 0, 'the default width removes the stored value');

    assert.equal(readSidebarWidth(1600, throwing, ''), SIDEBAR_DEFAULT_WIDTH);
    assert.equal(writeSidebarWidth(320, throwing, ''), false);
});

// Rejects: a width change on a browser or system shortcut (Cmd, Ctrl or Alt
// with an arrow, Home or End). It would still pass a rule that also ignores Shift.
test('sidebarWidthForKey ignores the keys with Cmd, Ctrl or Alt', () => {
    for (const modifier of [{metaKey: true}, {ctrlKey: true}, {altKey: true}]) {
        for (const key of ['ArrowLeft', 'ArrowRight', 'Home', 'End', 'Enter']) {
            assert.equal(sidebarWidthForKey(300, key, false, 1600, modifier), null, `${key} ${JSON.stringify(modifier)}`);
        }
    }

    assert.equal(sidebarWidthForKey(300, 'ArrowRight', false, 1600, {}), 308);
});

// Rejects: a value text without the unit.
test('sidebarWidthText names the width in pixels', () => {
    assert.equal(sidebarWidthText(236), '236 pixels');
});
