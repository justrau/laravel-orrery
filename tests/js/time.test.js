// Tests of resources/js/support/time.js. `npm test` runs them with TZ=UTC
// and LANG=en_US.UTF-8, so local times and grouping are stable.

import {test} from 'node:test';
import assert from 'node:assert/strict';

import {
    formatClock,
    formatClockDuration,
    formatClockZoned,
    formatDateTimeZoned,
    displayTimeZone,
    formatCompactCount,
    formatCount,
    formatDate,
    formatDuration,
    formatDurationShort,
    formatHourMinute,
    formatHourMinuteZoned,
    formatLimit,
    formatLocal,
    nowSeconds,
    relativeTime,
    formatTimeRangeZoned,
    formatDayMinute,
    formatDayClock,
    formatDayMinuteZoned,
    formatDayRangeZoned,
    browserSeconds,
    noteServerTime,
    serverClockOffset,
    zoneSuffix,
    isSameDisplayDay,
    timesInNote,
    timeZoneLabel,
    toDate,
    toSeconds,
    zoneFormatter,
    zoneOffset,
    zonedEpoch,
    zonedParts,
} from '../../resources/js/support/time.js';

// 2026-09-28 09:32:07.25 UTC
const EPOCH = 1790587927.25;

// Rejects: a test run in the local zone, which makes the time assertions depend on the machine.
test('the test environment runs in UTC', () => {
    assert.equal(new Date(0).getTimezoneOffset(), 0);
});

// Rejects: milliseconds instead of seconds, and a whole-second value.
test('nowSeconds is epoch seconds with a fraction', () => {
    const before = Date.now() / 1000;
    const now = nowSeconds();
    const after = Date.now() / 1000;

    assert.ok(now >= before && now <= after);
});

// Rejects: Number() on any value (null and '' become 0), and parseFloat (which accepts "12abc").
test('toSeconds accepts a finite number and keeps fractions', () => {
    assert.equal(toSeconds(0), 0);
    assert.equal(toSeconds(1790586131.75418), 1790586131.75418);
    assert.equal(toSeconds(-12.5), -12.5);
});

// Rejects: a parser that accepts numbers only, which loses the string columns of MySQL and Postgres.
test('toSeconds accepts a numeric string from a decimal column', () => {
    assert.equal(toSeconds('1790586131.754180'), 1790586131.75418);
    assert.equal(toSeconds('42'), 42);
    assert.equal(toSeconds(' 42 '), 42);
    assert.equal(toSeconds('-3.5'), -3.5);
});

// Rejects: Number(), which turns null, '' and false into 0.
test('toSeconds gives null for every value that is not a number, never 0', () => {
    for (const value of [null, undefined, '', ' ', 'abc', '12abc', '1e3', '.5', '5.', NaN, Infinity, -Infinity, true, false, {}, [], [1]]) {
        assert.equal(toSeconds(value), null, `toSeconds(${JSON.stringify(value)})`);
    }
});

// Rejects: a Date built from seconds as milliseconds.
test('toDate reads epoch seconds', () => {
    assert.equal(toDate(EPOCH).toISOString(), '2026-09-28T09:32:07.250Z');
});

// Rejects: an empty label, and a label without the zone.
test('timeZoneLabel names the zone', () => {
    assert.match(timeZoneLabel(EPOCH), /^(UTC|GMT)/);
});

// Rejects: unpadded hours or minutes, and 0 for an unknown time.
test('formatHourMinute and formatClock pad and show unknown as a dash', () => {
    assert.equal(formatHourMinute(EPOCH), '09:32');
    assert.equal(formatHourMinute(String(EPOCH)), '09:32');
    assert.equal(formatHourMinute(null), '—');
    assert.equal(formatClock(EPOCH), '09:32:07');
    assert.equal(formatClock(1790553600), '00:00:00');
    assert.equal(formatClock('x'), '—');
});

// Rejects: a month counted from 0, and a missing zone label.
test('formatLocal writes the date, the time and the zone', () => {
    assert.match(formatLocal(EPOCH), /^2026-09-28 09:32:07 (UTC|GMT)/);
    assert.equal(formatLocal(undefined), '—');
});

// Rejects: rounding instead of flooring, a negative age for clock skew, and "0 s ago" for unknown.
test('relativeTime picks the largest unit and floors', () => {
    const now = 1790000000;

    assert.equal(relativeTime(now + 30, now), 'just now');
    assert.equal(relativeTime(now - 0.5, now), 'just now');
    assert.equal(relativeTime(now - 18.9, now), '18 s ago');
    assert.equal(relativeTime(now - 59, now), '59 s ago');
    assert.equal(relativeTime(now - 60, now), '1 min ago');
    assert.equal(relativeTime(now - 3599, now), '59 min ago');
    assert.equal(relativeTime(now - 3600, now), '1 h ago');
    assert.equal(relativeTime(now - 86400 * 2 - 5, now), '2 d ago');
    assert.equal(relativeTime(String(now - 120), now), '2 min ago');
    assert.equal(relativeTime(null, now), '—');
});

// Rejects: "0 s" for an unknown or negative duration, and a zero rest part.
test('formatDuration uses two units at most', () => {
    assert.equal(formatDuration(0), '0 s');
    assert.equal(formatDuration(12.9), '12 s');
    assert.equal(formatDuration(120), '2 min');
    assert.equal(formatDuration(134), '2 min 14 s');
    assert.equal(formatDuration(3600), '1 h');
    assert.equal(formatDuration(3900), '1 h 5 min');
    assert.equal(formatDuration(86400 * 3), '3 d');
    assert.equal(formatDuration(86400 * 3 + 4 * 3600 + 59), '3 d 4 h');
    assert.equal(formatDuration('90'), '1 min 30 s');
    assert.equal(formatDuration(null), '—');
    assert.equal(formatDuration(-1), '—');
});

// Rejects: a long unit left in the badge, and "0s" for an unknown duration.
test('formatDurationShort compacts the units', () => {
    assert.equal(formatDurationShort(45), '45s');
    assert.equal(formatDurationShort(134), '2m 14s');
    assert.equal(formatDurationShort(3900), '1h 5m');
    assert.equal(formatDurationShort(86400 * 3 + 4 * 3600), '3d 4h');
    assert.equal(formatDurationShort(null), '—');
    assert.equal(formatDurationShort(-5), '—');
});

// Rejects: unpadded seconds, and "0:00" for an unknown duration.
test('formatClockDuration writes a clock', () => {
    assert.equal(formatClockDuration(12), '0:12');
    assert.equal(formatClockDuration(134), '2:14');
    assert.equal(formatClockDuration(3723), '1:02:03');
    assert.equal(formatClockDuration(null), '—');
    assert.equal(formatClockDuration(-1), '—');
});

// Rejects: "1 min" for 60 s (the rule keeps seconds below 2 minutes) and "1.5 min" for 90 s.
test('formatLimit keeps the largest whole unit', () => {
    assert.equal(formatLimit(60), '60 s');
    assert.equal(formatLimit(90), '90 s');
    assert.equal(formatLimit(120), '2 min');
    assert.equal(formatLimit(240), '4 min');
    assert.equal(formatLimit(3600), '1 h');
    assert.equal(formatLimit(7200), '2 h');
    assert.equal(formatLimit(5400), '90 min');
    assert.equal(formatLimit(59.6), '60 s');
    assert.equal(formatLimit(null), '—');
    assert.equal(formatLimit(-1), '—');
});

// Rejects: 0 for an unknown count, and a numeric string that is shown as a count.
test('formatCount groups digits and shows unknown as a dash', () => {
    assert.equal(formatCount(10216), '10,216');
    assert.equal(formatCount(0), '0');
    assert.equal(formatCount(null), '—');
    assert.equal(formatCount('5'), '—');
    assert.equal(formatCount(NaN), '—');
});

// Rejects: "1000k" just below a million, and a compact form below 50,000.
test('formatCompactCount compacts large counts', () => {
    assert.equal(formatCompactCount(999), '999');
    assert.equal(formatCompactCount(10216), '10,216');
    assert.equal(formatCompactCount(50000), '50k');
    assert.equal(formatCompactCount(74900), '74.9k');
    assert.equal(formatCompactCount(123456), '123k');
    assert.equal(formatCompactCount(999499), '999k');
    assert.equal(formatCompactCount(999500), '1M');
    assert.equal(formatCompactCount(1200000), '1.2M');
    // A lower bound rounds down (nit): "at least 74,960" is never "75k+".
    assert.equal(formatCompactCount(74960, {down: true}), '74.9k');
    assert.equal(formatCompactCount(999500, {down: true}), '999k');
    assert.equal(formatCompactCount(1000000, {down: true}), '1M');
    assert.equal(formatCompactCount(1290000, {down: true}), '1.2M');
    assert.equal(formatCompactCount(123999, {down: true}), '123k');
    assert.equal(formatCompactCount(4999, {down: true}), '4,999');
    assert.equal(formatCompactCount(undefined), '—');
});

// The display time zone: `window.Orrery.displayTimezone` when the server set it.
function withZone(zone, run) {
    const before = globalThis.Orrery;

    globalThis.Orrery = {displayTimezone: zone};

    try {
        run();
    } finally {
        if (before === undefined) delete globalThis.Orrery;
        else globalThis.Orrery = before;
    }
}

// Rejects: a zone read once at import (a page whose variables arrive later keeps the browser's zone), and an
// invalid zone passed to Intl (it throws a RangeError on every formatted time).
test('displayTimeZone reads the configured zone, else null for the browser zone', () => {
    assert.equal(displayTimeZone(), null);
    withZone('Asia/Tokyo', () => assert.equal(displayTimeZone(), 'Asia/Tokyo'));
    withZone('Not/AZone', () => assert.equal(displayTimeZone(), null));
    withZone('', () => assert.equal(displayTimeZone(), null));
    withZone(42, () => assert.equal(displayTimeZone(), null));
});

// Rejects: the browser's getHours() and getDate() when a display zone is set (the test runs in UTC, the zone is +9 h).
test('the time formats use the display zone when one is set', () => {
    withZone('Asia/Tokyo', () => {
        assert.equal(formatHourMinute(EPOCH), '18:32');
        assert.equal(formatClock(EPOCH), '18:32:07');
        assert.equal(formatLocal(EPOCH), '2026-09-28 18:32:07 GMT+9');
        assert.equal(timeZoneLabel(EPOCH), 'GMT+9');
        // 2026-09-28 20:00 UTC is already the 29th in Tokyo.
        assert.deepEqual(zonedParts(1790625600), {year: 2026, month: 9, day: 29, hour: 5, minute: 0, second: 0});
    });
});

// Rejects: one fixed offset for a zone with daylight saving time (New York is UTC−4 in September, UTC−5 in December).
test('the display zone follows daylight saving time', () => {
    withZone('America/New_York', () => {
        assert.equal(formatLocal(EPOCH), '2026-09-28 05:32:07 EDT');
        // 2026-12-01 12:00 UTC
        assert.equal(formatLocal(1796126400), '2026-12-01 07:00:00 EST');
    });
});

// Rejects: zonedParts() that ignores the zone (the browser zone is UTC here) and a 24:00 hour from Intl's h24 cycle.
test('zonedParts gives the calendar parts in the browser zone without a display zone', () => {
    assert.deepEqual(zonedParts(EPOCH), {year: 2026, month: 9, day: 28, hour: 9, minute: 32, second: 7});
    // Midnight is hour 0, never 24.
    withZone('Asia/Tokyo', () => assert.equal(zonedParts(1790607600).hour, 0));
    assert.equal(zonedParts(null), null);
});

// Rejects: a parse in the browser's zone (an input of 18:32 in Tokyo would be read as 18:32 UTC), and an
// offset taken at the wrong instant around a daylight-saving change.
test('zonedEpoch reads wall-clock parts in the display zone', () => {
    assert.equal(zonedEpoch(2026, 9, 28, 9, 32, 7), 1790587927);

    withZone('Asia/Tokyo', () => assert.equal(zonedEpoch(2026, 9, 28, 18, 32, 7), 1790587927));

    withZone('America/New_York', () => {
        assert.equal(zonedEpoch(2026, 9, 28, 5, 32, 7), 1790587927);
        assert.equal(zonedEpoch(2026, 12, 1, 7, 0, 0), 1796126400);
        // 2026-11-01 01:30 happens twice in New York; the first (EDT) is taken: 05:30 UTC.
        assert.equal(zonedEpoch(2026, 11, 1, 1, 30, 0), 1793511000);
        // 03:30 on the day the clocks go forward is EDT (07:30 UTC); the offset of 03:30 UTC (EST) would give 08:30.
        assert.equal(zonedEpoch(2026, 3, 8, 3, 30, 0), 1772955000);
    });
});

// Rejects: the browser's date when a display zone is set, and a value that is not a number formatted as a date.
test('formatDate writes the date in the display zone', () => {
    assert.equal(formatDate(EPOCH), '2026-09-28');
    withZone('Asia/Tokyo', () => assert.equal(formatDate(1790625600), '2026-09-29'));
    assert.equal(formatDate(null), '—');
});

// Rejects: the browser's getTimezoneOffset() (0 here) while a display zone is set, and its sign (minutes west).
test('zoneOffset gives the display zone offset in seconds east of UTC', () => {
    assert.equal(zoneOffset(EPOCH), 0);
    withZone('Asia/Tokyo', () => assert.equal(zoneOffset(EPOCH), 32400));
    withZone('America/New_York', () => {
        assert.equal(zoneOffset(EPOCH), -14400);
        assert.equal(zoneOffset(1796126400), -18000);
    });
});

// Review fix 2. Rejects: candidates sampled at wall ± 12 h, which miss an offset over 12 h (Chatham is UTC+13:45
// in April), and a skipped time east of UTC resolved with the offset after the change (Vilnius skips 03:00–04:00
// on 2026-03-29: 03:30 is taken one offset later, 04:30 EEST = 01:30 UTC).
test('zonedEpoch handles offsets over 12 h and skipped times east of UTC', () => {
    withZone('Pacific/Chatham', () => {
        assert.equal(zonedEpoch(2026, 4, 5, 2, 0, 0), 1775304900);
        assert.equal(zonedEpoch(2026, 9, 27, 2, 0, 0), 1790428500);
    });
    withZone('Europe/Vilnius', () => assert.equal(zonedEpoch(2026, 3, 29, 3, 30, 0), 1774747800));
});

// Review fix 6. Rejects: a zone check that builds a new Intl.DateTimeFormat on every call (the result must be
// the same object for one zone name) and a cache that keeps an invalid zone as valid.
test('the formatter of a zone is cached per zone name', () => {
    withZone('Asia/Tokyo', () => assert.equal(zoneFormatter('Asia/Tokyo'), zoneFormatter('Asia/Tokyo')));
    assert.notEqual(zoneFormatter('Asia/Tokyo'), zoneFormatter('Europe/Vilnius'));
    assert.equal(zoneFormatter('Not/AZone'), null);
    assert.equal(zoneFormatter('Not/AZone'), null);
});

// Rejects: a bare "09:32" without the zone (spec 5.4: absolute times show the zone abbreviation), the
// browser's zone when a display zone is set, the zone of the range's start instead of its end, and a
// label for an unknown time.
test('the zoned formats add the display zone abbreviation', () => {
    assert.equal(formatHourMinuteZoned(EPOCH), '09:32 UTC');
    assert.equal(formatClockZoned(EPOCH), '09:32:07 UTC');
    assert.equal(formatDateTimeZoned(EPOCH), '2026-09-28 09:32 UTC');
    assert.equal(formatTimeRangeZoned(EPOCH, EPOCH + 300), '09:32–09:37 UTC');
    assert.equal(formatHourMinuteZoned(null), '—');
    assert.equal(formatClockZoned('x'), '—');
    assert.equal(formatDateTimeZoned(undefined), '—');
    assert.equal(formatTimeRangeZoned(null, EPOCH), '—');
    assert.equal(formatTimeRangeZoned(EPOCH, null), '—');

    withZone('Asia/Tokyo', () => {
        assert.equal(formatHourMinuteZoned(EPOCH), '18:32 GMT+9');
        assert.equal(formatClockZoned(String(EPOCH)), '18:32:07 GMT+9');
        assert.equal(formatDateTimeZoned(EPOCH), '2026-09-28 18:32 GMT+9');
    });

    // New York leaves daylight saving time on 2026-11-01 at 06:00 UTC: each end carries its own label
    // (one label for both would put the start an hour off).
    withZone('America/New_York', () => {
        assert.equal(formatTimeRangeZoned(1793512800 - 1800, 1793512800 + 1800), '01:30 EDT–01:30 EST');
        assert.equal(formatTimeRangeZoned(1793512800 + 60, 1793512800 + 1800), '01:01–01:30 EST');
    });
});

// Rejects: a section note without the zone, and the browser's zone when a display zone is set.
test('timesInNote names the display zone of a section', () => {
    assert.equal(timesInNote(EPOCH), 'times in UTC');
    withZone('Asia/Tokyo', () => assert.equal(timesInNote(EPOCH), 'times in GMT+9'));
    assert.match(timesInNote(), /^times in (UTC|GMT)/);
});

// Rejects: a day compared in UTC or in the browser's zone instead of the display zone (23:50 and 00:30 in
// Tokyo are one UTC day but two Tokyo days), a clock alone for a time of an earlier day (it reads as
// today's), and a date on a time of today.
test('formatDayMinute shows the date unless the time is today in the display zone', () => {
    const before = Date.UTC(2026, 8, 28, 14, 50) / 1000; // 23:50 on the 28th in Tokyo
    const after = Date.UTC(2026, 8, 28, 15, 30) / 1000;  // 00:30 on the 29th in Tokyo

    assert.equal(isSameDisplayDay(before, after), true);
    assert.equal(formatDayMinute(before, after), '14:50');
    assert.equal(formatDayMinute(before - 86400, after), '2026-09-27 14:50');
    assert.equal(formatDayMinute(null, after), '—');
    assert.equal(isSameDisplayDay(null, after), false);

    withZone('Asia/Tokyo', () => {
        assert.equal(isSameDisplayDay(before, after), false);
        assert.equal(formatDayMinute(before, after), '2026-09-28 23:50');
        assert.equal(formatDayMinute(after - 60, after), '00:29');
    });
});

// Rejects: a row time without its own label when its offset differs from the section note's (the note is
// read at `now`; Vilnius leaves summer time on 2026-10-25 at 01:00 UTC, so 03:30 that morning is GMT+3
// while the note says GMT+2), and a label on a row whose offset matches the note.
test('a row time carries its own zone label when it differs from now', () => {
    const now = Date.UTC(2026, 9, 25, 12, 0) / 1000;    // 14:00 GMT+2
    const summer = Date.UTC(2026, 9, 25, 0, 30) / 1000; // 03:30 GMT+3, the same day
    const winter = Date.UTC(2026, 9, 25, 9, 0) / 1000;  // 11:00 GMT+2

    withZone('Europe/Vilnius', () => {
        assert.equal(zoneSuffix(summer, now), ' GMT+3');
        assert.equal(zoneSuffix(winter, now), '');
        assert.equal(formatDayMinute(summer, now), '03:30 GMT+3');
        assert.equal(formatDayMinute(winter, now), '11:00');
        assert.equal(formatDayMinute(summer - 86400, now), '2026-10-24 03:30 GMT+3');
    });
    assert.equal(zoneSuffix(null, now), '');
});

// Rejects: a nowSeconds() that keeps the browser clock after the server said its time (a browser three
// minutes fast then shows "Tick is late" forever), an offset taken from the send time only (it counts
// the whole round trip as skew), and an answer without a usable as_of that moves the clock.
test('nowSeconds follows the server clock from as_of', () => {
    assert.equal(serverClockOffset(1000, 1180, 1182), -181);
    assert.equal(serverClockOffset(null, 1180, 1182), null);
    assert.equal(serverClockOffset('1000.5', 1000, 1001), 0);
    assert.equal(serverClockOffset(1000, 1182, 1180), null);
    // A slow answer could be off by half its round trip: it sets no clock.
    assert.equal(serverClockOffset(1000, 1180, 1190), -185);
    assert.equal(serverClockOffset(1000, 1180, 1190.5), null);

    try {
        const browser = Date.now() / 1000;

        noteServerTime(browser - 180, browser, browser);
        assert.ok(Math.abs(nowSeconds() - (Date.now() / 1000 - 180)) < 1);

        noteServerTime(undefined, browser, browser);
        assert.ok(Math.abs(nowSeconds() - (Date.now() / 1000 - 180)) < 1, 'an answer without as_of keeps the offset');
    } finally {
        noteServerTime(null, 0, 0, {reset: true});
    }

    assert.ok(Math.abs(nowSeconds() - Date.now() / 1000) < 1);
    assert.ok(Math.abs(browserSeconds() - Date.now() / 1000) < 1);
});

// Rejects: a bare time of day for a time of an earlier day ("silent since 14:02:11" two days later), and a
// date on a time of today.
test('formatDayClock adds the date for a time of another day', () => {
    const now = Date.UTC(2026, 8, 28, 12, 0) / 1000;

    assert.equal(formatDayClock(now - 3600 + 7, now), '11:00:07');
    assert.equal(formatDayClock(now - 2 * 86400, now), '2026-09-26 12:00:00');
    assert.equal(formatDayClock(null, now), '—');
});

// Rejects: a zoned time of an earlier day without its date ("since 16:40" on Monday for a start on Friday), a
// date on a time of today, and a range that names no date when it lies on an earlier day or spans two days.
// Still passes: a range end of exactly midnight read as the next day's (the end is exclusive on purpose).
test('formatDayMinuteZoned and formatDayRangeZoned add the date for another day', () => {
    const now = Date.UTC(2026, 8, 28, 12, 0) / 1000;

    assert.equal(formatDayMinuteZoned(now - 3600, now), '11:00 UTC');
    assert.equal(formatDayMinuteZoned(now - 3 * 86400 + 4 * 3600 + 40 * 60, now), '2026-09-25 16:40 UTC');
    assert.equal(formatDayMinuteZoned('x', now), '—');

    assert.equal(formatDayRangeZoned(now - 3600, now, now), '11:00–12:00 UTC');
    assert.equal(formatDayRangeZoned(now - 2 * 86400, now - 2 * 86400 + 3600, now), '2026-09-26 12:00–13:00 UTC');
    assert.equal(formatDayRangeZoned(now - 14 * 3600, now - 10 * 3600, now), '2026-09-27 22:00 UTC–2026-09-28 02:00 UTC');
    // An hour bucket that ends at midnight stays on its own day.
    assert.equal(formatDayRangeZoned(now - 13 * 3600, now - 12 * 3600, now), '2026-09-27 23:00–00:00 UTC');
    assert.equal(formatDayRangeZoned(null, now, now), '—');
});
