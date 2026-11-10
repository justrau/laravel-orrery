/**
 * Time formatting for the SPA. Every API time is epoch seconds (fractions
 * allowed). These helpers convert to the display time zone only for
 * display; arithmetic stays in epoch seconds.
 *
 * The display time zone is `orrery.display_timezone` when the server sets
 * it (`window.Orrery.displayTimezone`), else the
 * browser's zone. Every absolute time goes through zonedParts(), so no
 * screen reads the browser's getHours() or getDate() directly.
 */

/**
 * The offset of the server's clock from the browser's, in seconds: every
 * freshness rule compares server times with nowSeconds(), so a browser
 * clock that runs fast or slow must not make a fresh tick look late. The
 * shell sets it from the `as_of` of a polled answer (noteServerTime()).
 */
let serverOffset = 0;

/** The browser's own clock in epoch seconds. */
export function browserSeconds() {
    return Date.now() / 1000;
}

/** The current time in epoch seconds, on the server's clock once an answer gave it (noteServerTime()). */
export function nowSeconds() {
    return browserSeconds() + serverOffset;
}

/** The longest round trip whose `as_of` sets the server clock: a slower answer could be off by half of it. */
export const MAX_CLOCK_ROUND_TRIP_SECONDS = 10;

/**
 * The offset of a server's `as_of` from the browser clock: `as_of` minus
 * the middle of the request (browser seconds when it was sent and when the
 * answer came), so half the round trip is not counted as skew. Null when
 * `as_of` is not a number, the times are not in order, or the round trip
 * took longer than MAX_CLOCK_ROUND_TRIP_SECONDS.
 */
export function serverClockOffset(asOf, sentAt, receivedAt) {
    const at = toSeconds(asOf);

    if (at === null || !Number.isFinite(sentAt) || !Number.isFinite(receivedAt) || receivedAt < sentAt) return null;
    if (receivedAt - sentAt > MAX_CLOCK_ROUND_TRIP_SECONDS) return null;

    return at - (sentAt + receivedAt) / 2;
}

/**
 * Takes the server clock from an answer's `as_of` (serverClockOffset());
 * an answer without a usable `as_of` keeps the last offset. `reset` goes
 * back to the browser clock (Node tests).
 */
export function noteServerTime(asOf, sentAt, receivedAt, {reset = false} = {}) {
    if (reset) {
        serverOffset = 0;

        return;
    }

    const offset = serverClockOffset(asOf, sentAt, receivedAt);

    if (offset !== null) serverOffset = offset;
}

const NUMERIC = /^-?\d+(\.\d+)?$/;

/**
 * A time or a duration in seconds as a finite number, or null. A numeric
 * string counts: the older list endpoints return decimal columns as strings
 * on MySQL and Postgres (for example "1790586131.754180").
 */
export function toSeconds(value) {
    if (typeof value === 'number') return Number.isFinite(value) ? value : null;
    if (typeof value === 'string' && NUMERIC.test(value.trim())) return Number(value);

    return null;
}

function pad(value) {
    return String(value).padStart(2, '0');
}

/** A Date for the given epoch seconds. */
export function toDate(epoch) {
    return new Date(epoch * 1000);
}

/** The parts formatter of each zone name, or null for a zone that Intl does not know; built once per name. */
const ZONE_FORMATTERS = new Map();

/**
 * The cached Intl.DateTimeFormat that gives the calendar parts of a zone
 * (24-hour clock), or null when Intl does not know the zone (it throws a
 * RangeError). The result is kept per zone name, so the check and the
 * formatter are built once, not on every formatted time.
 */
export function zoneFormatter(zone) {
    if (ZONE_FORMATTERS.has(zone)) return ZONE_FORMATTERS.get(zone);

    let formatter = null;

    try {
        formatter = new Intl.DateTimeFormat('en-US', {
            timeZone: zone,
            hourCycle: 'h23',
            year: 'numeric',
            month: 'numeric',
            day: 'numeric',
            hour: 'numeric',
            minute: 'numeric',
            second: 'numeric',
        });
    } catch (error) {
        formatter = null;
    }

    ZONE_FORMATTERS.set(zone, formatter);

    return formatter;
}

/** The short zone-name formatter of each zone (null: the browser's zone); built once per name. */
const LABEL_FORMATTERS = new Map();

function labelFormatter(zone) {
    if (!LABEL_FORMATTERS.has(zone)) {
        const options = {timeZoneName: 'short'};

        if (zone !== null) options.timeZone = zone;

        LABEL_FORMATTERS.set(zone, new Intl.DateTimeFormat(undefined, options));
    }

    return LABEL_FORMATTERS.get(zone);
}

/**
 * The display time zone: `window.Orrery.displayTimezone` when it is a zone
 * that Intl knows, else null (the browser's zone). It is read on every call,
 * so the page variables decide it whenever they arrive.
 */
export function displayTimeZone() {
    const orrery = typeof globalThis !== 'undefined' ? globalThis.Orrery : undefined;
    const zone = orrery && typeof orrery === 'object' ? orrery.displayTimezone : null;

    return typeof zone === 'string' && zone !== '' && zoneFormatter(zone) !== null ? zone : null;
}

/**
 * The calendar parts of an epoch in the display zone: { year, month (1–12),
 * day, hour (0–23), minute, second }, or null for a value that is not a
 * number.
 */
export function zonedParts(epoch) {
    epoch = toSeconds(epoch);
    if (epoch === null) return null;

    const date = toDate(epoch);
    const zone = displayTimeZone();

    if (zone === null) {
        return {
            year: date.getFullYear(),
            month: date.getMonth() + 1,
            day: date.getDate(),
            hour: date.getHours(),
            minute: date.getMinutes(),
            second: date.getSeconds(),
        };
    }

    const parts = {};

    zoneFormatter(zone).formatToParts(date).forEach(part => {
        if (part.type !== 'literal') parts[part.type] = Number(part.value);
    });

    return {year: parts.year, month: parts.month, day: parts.day, hour: parts.hour % 24, minute: parts.minute, second: parts.second};
}

/**
 * The epoch seconds of a wall-clock time in the display zone (month 1–12).
 * A time that a daylight-saving change repeats gives its first instant; a
 * time that it skips gives the instant one offset later.
 */
export function zonedEpoch(year, month, day, hour = 0, minute = 0, second = 0) {
    const zone = displayTimeZone();

    if (zone === null) return new Date(year, month - 1, day, hour, minute, second).getTime() / 1000;

    const wall = Date.UTC(year, month - 1, day, hour, minute, second) / 1000;
    const offsetAt = epoch => {
        const parts = zonedParts(epoch);

        return Date.UTC(parts.year, parts.month - 1, parts.day, parts.hour, parts.minute, parts.second) / 1000 - Math.floor(epoch);
    };
    // Two candidates: the offsets a day before and a day after (an offset can pass 12 h); the earlier match wins.
    const before = offsetAt(wall - 86400);
    const candidates = [before, offsetAt(wall + 86400)]
        .map(offset => wall - offset)
        .filter(epoch => {
            const parts = zonedParts(epoch);

            return parts.year === year && parts.month === month && parts.day === day && parts.hour === hour && parts.minute === minute;
        })
        .sort((a, b) => a - b);

    // A skipped time: read with the offset before the change, so it lands one offset later.
    return candidates.length ? candidates[0] : wall - before;
}

/** The offset of the display zone at an epoch, in seconds east of UTC (+10800 for UTC+3). */
export function zoneOffset(epoch) {
    const at = toSeconds(epoch);
    if (at === null) return 0;

    const parts = zonedParts(Math.floor(at));

    return Date.UTC(parts.year, parts.month - 1, parts.day, parts.hour, parts.minute, parts.second) / 1000 - Math.floor(at);
}

/**
 * The short name of the display zone at the given epoch, for example
 * "GMT+3" or "EEST", else the offset as "UTC+03:00".
 */
export function timeZoneLabel(epoch) {
    const date = toDate(epoch);
    const zone = displayTimeZone();

    try {
        const part = labelFormatter(zone)
            .formatToParts(date)
            .find(entry => entry.type === 'timeZoneName');

        if (part && part.value) {
            return part.value;
        }
    } catch (error) {
        //
    }

    const offset = Math.round(zoneOffset(epoch) / 60);
    const sign = offset >= 0 ? '+' : '-';

    return `UTC${sign}${pad(Math.floor(Math.abs(offset) / 60))}:${pad(Math.abs(offset) % 60)}`;
}

/** "2026-09-28": the date in the display zone. */
export function formatDate(epoch) {
    const parts = zonedParts(epoch);
    if (parts === null) return '—';

    return `${parts.year}-${pad(parts.month)}-${pad(parts.day)}`;
}

/** "09:17": the hour and minute in the display zone. */
export function formatHourMinute(epoch) {
    const parts = zonedParts(epoch);
    if (parts === null) return '—';

    return `${pad(parts.hour)}:${pad(parts.minute)}`;
}

/** "09:32:07": the time with seconds in the display zone. */
export function formatClock(epoch) {
    const parts = zonedParts(epoch);
    if (parts === null) return '—';

    return `${pad(parts.hour)}:${pad(parts.minute)}:${pad(parts.second)}`;
}

/** "2026-09-28 09:32:07 GMT+3": the date and time in the display zone with its label. */
export function formatLocal(epoch) {
    epoch = toSeconds(epoch);
    if (epoch === null) return '—';

    return `${formatDate(epoch)} ${formatClock(epoch)} ${timeZoneLabel(epoch)}`;
}

/** Whether two epochs fall on one calendar day of the display zone (false when either is unknown). */
export function isSameDisplayDay(a, b) {
    a = toSeconds(a);
    b = toSeconds(b);
    if (a === null || b === null) return false;

    return formatDate(a) === formatDate(b);
}

/**
 * "09:17" for a time of `now`'s day in the display zone, else
 * "2026-09-27 09:17", so a time of an earlier day never reads as today's;
 * with its own zone label when it differs from now's (zoneSuffix()).
 */
export function formatDayMinute(epoch, now = nowSeconds()) {
    epoch = toSeconds(epoch);
    if (epoch === null) return '—';

    const time = `${formatHourMinute(epoch)}${zoneSuffix(epoch, now)}`;

    return isSameDisplayDay(epoch, now) ? time : `${formatDate(epoch)} ${time}`;
}

/**
 * "09:32:07" for a time of `now`'s day in the display zone, else
 * "2026-09-27 09:32:07" (formatDayMinute() with seconds).
 */
export function formatDayClock(epoch, now = nowSeconds()) {
    epoch = toSeconds(epoch);
    if (epoch === null) return '—';

    const time = `${formatClock(epoch)}${zoneSuffix(epoch, now)}`;

    return isSameDisplayDay(epoch, now) ? time : `${formatDate(epoch)} ${time}`;
}

/** "09:17 GMT+3": the hour and minute in the display zone with its label. */
export function formatHourMinuteZoned(epoch) {
    epoch = toSeconds(epoch);
    if (epoch === null) return '—';

    return `${formatHourMinute(epoch)} ${timeZoneLabel(epoch)}`;
}

/**
 * "09:17 GMT+3" for a time of `now`'s day in the display zone, else
 * "2026-09-27 09:17 GMT+3": formatHourMinuteZoned() that never reads a time
 * of an earlier day as today's ("over the limit since 16:40" on Monday for a
 * start on Friday).
 */
export function formatDayMinuteZoned(epoch, now = nowSeconds()) {
    epoch = toSeconds(epoch);
    if (epoch === null) return '—';

    return isSameDisplayDay(epoch, now) ? formatHourMinuteZoned(epoch) : formatDateTimeZoned(epoch);
}

/** "09:32:07 GMT+3": the time with seconds in the display zone with its label. */
export function formatClockZoned(epoch) {
    epoch = toSeconds(epoch);
    if (epoch === null) return '—';

    return `${formatClock(epoch)} ${timeZoneLabel(epoch)}`;
}

/** "2026-09-28 09:32 GMT+3": the date and minute in the display zone with its label. */
export function formatDateTimeZoned(epoch) {
    epoch = toSeconds(epoch);
    if (epoch === null) return '—';

    return `${formatDate(epoch)} ${formatHourMinuteZoned(epoch)}`;
}

/**
 * "09:14–09:19 GMT+3": two minutes of the display zone with one label; a
 * range across a daylight-saving change labels each end ("01:30 EDT–01:30
 * EST"). Either end unknown: "—".
 */
export function formatTimeRangeZoned(from, to) {
    from = toSeconds(from);
    to = toSeconds(to);
    if (from === null || to === null) return '—';

    const start = timeZoneLabel(from);
    const end = timeZoneLabel(to);

    return start === end
        ? `${formatHourMinute(from)}–${formatHourMinute(to)} ${end}`
        : `${formatHourMinute(from)} ${start}–${formatHourMinute(to)} ${end}`;
}

/**
 * formatTimeRangeZoned() with dates when the range is not all on `now`'s
 * day: "2026-09-27 09:14–10:14 GMT+3" within one earlier day, else
 * "2026-09-26 22:00 GMT+3–2026-09-27 01:00 GMT+3". The end is exclusive, so
 * a range that ends at midnight stays on its first day.
 */
export function formatDayRangeZoned(from, to, now = nowSeconds()) {
    from = toSeconds(from);
    to = toSeconds(to);
    if (from === null || to === null) return '—';

    const last = to > from ? to - 1 : to;

    if (isSameDisplayDay(from, now) && isSameDisplayDay(last, now)) return formatTimeRangeZoned(from, to);
    if (isSameDisplayDay(from, last)) return `${formatDate(from)} ${formatTimeRangeZoned(from, to)}`;

    return `${formatDateTimeZoned(from)}–${formatDateTimeZoned(to)}`;
}

/**
 * " GMT+3" when the zone label at `epoch` differs from the label at `now`,
 * else "": a row under a "times in <zone>" note (read at now) carries its
 * own label when a daylight-saving change lies between them, so it never
 * reads an hour off. "" for an unknown time.
 */
export function zoneSuffix(epoch, now = nowSeconds()) {
    epoch = toSeconds(epoch);
    if (epoch === null) return '';

    const label = timeZoneLabel(epoch);

    return label === timeZoneLabel(now) ? '' : ` ${label}`;
}

/** "times in GMT+3": the note of a section whose times show no label of their own. */
export function timesInNote(epoch = nowSeconds()) {
    return `times in ${timeZoneLabel(epoch)}`;
}

/**
 * How long ago the epoch was, relative to `now`: "just now", "18 s ago",
 * "2 min ago", "3 h ago", "2 d ago". A time in the future (clock skew) reads
 * "just now".
 */
export function relativeTime(epoch, now = nowSeconds()) {
    epoch = toSeconds(epoch);
    if (epoch === null) return '—';

    const seconds = Math.floor(now - epoch);

    if (seconds < 1) return 'just now';
    if (seconds < 60) return `${seconds} s ago`;
    if (seconds < 3600) return `${Math.floor(seconds / 60)} min ago`;
    if (seconds < 86400) return `${Math.floor(seconds / 3600)} h ago`;

    return `${Math.floor(seconds / 86400)} d ago`;
}

/**
 * A duration in words: "12 s", "2 min 14 s", "1 h 5 min", "3 d 4 h". Null
 * or a negative value is unknown: "—".
 */
export function formatDuration(seconds) {
    seconds = toSeconds(seconds);
    if (seconds === null || seconds < 0) return '—';

    const total = Math.floor(seconds);

    if (total < 60) return `${total} s`;

    if (total < 3600) {
        const rest = total % 60;

        return rest ? `${Math.floor(total / 60)} min ${rest} s` : `${Math.floor(total / 60)} min`;
    }

    if (total < 86400) {
        const rest = Math.floor((total % 3600) / 60);

        return rest ? `${Math.floor(total / 3600)} h ${rest} min` : `${Math.floor(total / 3600)} h`;
    }

    const rest = Math.floor((total % 86400) / 3600);

    return rest ? `${Math.floor(total / 86400)} d ${rest} h` : `${Math.floor(total / 86400)} d`;
}

/** A compact duration for a badge: "45s", "2m 14s", "1h 5m", "3d 4h". */
export function formatDurationShort(seconds) {
    seconds = toSeconds(seconds);
    if (seconds === null || seconds < 0) return '—';

    return formatDuration(seconds)
        .replace(/ min/g, 'm')
        .replace(/ s/g, 's')
        .replace(/ h/g, 'h')
        .replace(/ d/g, 'd');
}

/** A duration as a clock: "0:12", "2:14", "1:02:03". */
export function formatClockDuration(seconds) {
    seconds = toSeconds(seconds);
    if (seconds === null || seconds < 0) return '—';

    const total = Math.floor(seconds);
    const hours = Math.floor(total / 3600);
    const minutes = Math.floor((total % 3600) / 60);
    const rest = total % 60;

    return hours > 0 ? `${hours}:${pad(minutes)}:${pad(rest)}` : `${minutes}:${pad(rest)}`;
}

/**
 * A limit or a scale end in its largest whole unit: "60 s", "4 min",
 * "2 h". A value that is not a whole unit keeps the smaller unit: "90 s".
 */
export function formatLimit(seconds) {
    seconds = toSeconds(seconds);
    if (seconds === null || seconds < 0) return '—';

    if (seconds >= 3600 && seconds % 3600 === 0) return `${seconds / 3600} h`;
    if (seconds >= 120 && seconds % 60 === 0) return `${seconds / 60} min`;

    return `${Math.round(seconds)} s`;
}

/** A count with the browser's grouping: 10,216. */
export function formatCount(value) {
    if (typeof value !== 'number' || !Number.isFinite(value)) return '—';

    return value.toLocaleString();
}

/** A compact count for the sidebar: 999, 10,216, 74.9k, 1.2M; `down` rounds a lower bound down. */
export function formatCompactCount(value, {down = false} = {}) {
    if (typeof value !== 'number' || !Number.isFinite(value)) return '—';

    // A lower bound ("at least 74,960", shown with "+") rounds down, never up: "74.9k+", not "75k+".
    if (down) {
        if (value >= 1e6) return `${Math.floor(value / 1e5) / 10}M`;
        if (value >= 1e5) return `${Math.floor(value / 1e3)}k`;
        if (value >= 5e4) return `${Math.floor(value / 100) / 10}k`;

        return value.toLocaleString();
    }

    // 999,500 and more rounds to "1M", never "1000k".
    if (value >= 999500) return `${(value / 1e6).toFixed(1).replace(/\.0$/, '')}M`;
    if (value >= 1e5) return `${(value / 1e3).toFixed(0)}k`;
    if (value >= 5e4) return `${(value / 1e3).toFixed(1).replace(/\.0$/, '')}k`;

    return value.toLocaleString();
}
