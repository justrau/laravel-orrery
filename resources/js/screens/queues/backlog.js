import {axisTicks, niceAxis, slotStarts} from '../../support/series.js';
import {readStored, writeStored} from '../../support/storage.js';
import {CARD_PAGE, HISTORY_CONCURRENCY, findRow, normalizeWorkload, queueRequest} from './queues.js';
import {formatCount, formatDate, formatDayMinuteZoned, formatDayRangeZoned, formatHourMinute, nowSeconds} from '../../support/time.js';

/**
 * The rules of the backlog charts of the Queues screen (BacklogChart.vue):
 * the time range presets, the y scale, the slots of a series with its
 * gaps, the nearest point of the tooltip, the x-axis labels and the card
 * titles.
 *
 * A slot without a known minute is unknown: the chart draws a gap and the
 * tooltip says "unknown", never 0.
 */

/**
 * The time range presets, applied to every card. The history endpoint
 * serves 5..1440 minutes (`GET /api/queues/history`), and the samples are
 * kept `samples.retention_hours` (24 by default): a longer preset could only
 * show gaps.
 */
export const BACKLOG_RANGES = [
    {key: '15m', minutes: 15, label: '15 min', window: 'past 15 minutes'},
    {key: '1h', minutes: 60, label: '1 h', window: 'past hour'},
    {key: '6h', minutes: 360, label: '6 h', window: 'past 6 hours'},
    {key: '24h', minutes: 1440, label: '24 h', window: 'past 24 hours'},
];

export const DEFAULT_BACKLOG_RANGE = '1h';

/**
 * The y scale of the cards: one for all of them (the default, so the cards
 * compare), or one per queue (a small queue next to a large one is
 * readable). The axis label of each card names its top either way.
 */
export const BACKLOG_SCALES = [
    {key: 'shared', label: 'Shared scale'},
    {key: 'queue', label: 'Per queue'},
];

/** The stored keys of the viewer's choice (support/storage.js scopes them by the base path). */
export const BACKLOG_RANGE_KEY = 'orreryBacklogRange';
export const BACKLOG_SCALE_KEY = 'orreryBacklogScale';

/** The most slots one card draws: a day of minutes is drawn as 120 slots of 12 minutes. */
const MAX_SLOTS = 120;

/** The x-axis labels of a small card. */
const MAX_TICKS = 3;

/** The preset of a key, or the default preset for any other value. */
export function backlogRange(key) {
    return BACKLOG_RANGES.find(range => range.key === key) || BACKLOG_RANGES.find(range => range.key === DEFAULT_BACKLOG_RANGE);
}

/** The scale mode of a value: 'queue', else 'shared'. */
export function backlogScaleMode(value) {
    return BACKLOG_SCALES.some(scale => scale.key === value) ? value : 'shared';
}

/** "past hour", "past 6 hours": the window of a preset, for the subtitles. */
export function rangeLabel(key) {
    return backlogRange(key).window;
}

/** The viewer's stored range and scale ({range, scale}); the defaults when none is stored or the storage fails. */
export function readBacklogChoice(storage = undefined, basePath = undefined) {
    return {
        range: backlogRange(readStored(BACKLOG_RANGE_KEY, storage, basePath)).key,
        scale: backlogScaleMode(readStored(BACKLOG_SCALE_KEY, storage, basePath)),
    };
}

/** Stores the viewer's range and scale; false when the storage cannot be written. */
export function writeBacklogChoice(choice, storage = undefined, basePath = undefined) {
    const range = writeStored(BACKLOG_RANGE_KEY, backlogRange(choice && choice.range).key, storage, basePath);
    const scale = writeStored(BACKLOG_SCALE_KEY, backlogScaleMode(choice && choice.scale), storage, basePath);

    return range && scale;
}

/**
 * The poll interval of the cards for a preset, in seconds: a day's history
 * is 24 times the size of an hour's per queue, so it is read less often.
 */
export function backlogPollSeconds(key) {
    const minutes = backlogRange(key).minutes;

    if (minutes <= 60) return 30;

    return minutes <= 360 ? 60 : 120;
}

/** The length of one slot for a window of `minutes`, in seconds: whole minutes, at most MAX_SLOTS slots. */
export function slotSecondsFor(minutes) {
    return Math.max(1, Math.ceil(minutes / MAX_SLOTS)) * 60;
}

/**
 * The slots of a history ({from, to, retentionFrom} and buckets {at,
 * ready}) at `slotSeconds`: `{index, at, end, value, minutes,
 * unknownMinutes}`. `value` is the largest known `ready` of the slot's
 * minutes in the window (the peak, so a spike is never averaged away), or
 * null when no minute is known. A minute is unknown without a bucket, with
 * a null `ready`, or when it ends at or before the retention horizon.
 */
export function backlogSlots(window, buckets, slotSeconds) {
    if (!window || !(slotSeconds > 0)) return [];

    const byStart = new Map((Array.isArray(buckets) ? buckets : []).filter(bucket => bucket && Number.isFinite(bucket.at)).map(bucket => [bucket.at, bucket]));
    const horizon = Number.isFinite(window.retentionFrom) ? window.retentionFrom : null;
    const firstMinute = Math.floor(window.from / 60) * 60;

    return slotStarts(window.from, window.to, slotSeconds).map((at, index) => {
        let value = null;
        let minutes = 0;
        let unknownMinutes = 0;

        for (let minute = at; minute < at + slotSeconds; minute += 60) {
            if (minute < firstMinute || minute >= window.to) continue;

            minutes++;

            const bucket = byStart.get(minute);
            const expired = horizon !== null && minute + 60 <= horizon;
            const ready = bucket && !expired && typeof bucket.ready === 'number' && Number.isFinite(bucket.ready) ? bucket.ready : null;

            if (ready === null) {
                unknownMinutes++;
            } else if (value === null || ready > value) {
                value = ready;
            }
        }

        return {index, at, end: at + slotSeconds, value, minutes, unknownMinutes};
    });
}

/** The runs of known slots: each is drawn as one line; a line never crosses an unknown slot. */
export function backlogSegments(slots) {
    const segments = [];
    let current = null;

    (slots || []).forEach(slot => {
        if (slot.value === null) {
            current = null;

            return;
        }

        if (!current) {
            current = [];
            segments.push(current);
        }

        current.push(slot);
    });

    return segments;
}

/** The runs of unknown slots: `{from, to}` (slot indexes) and `{first, end}` (their times). */
export function backlogGaps(slots) {
    const gaps = [];
    let current = null;

    (slots || []).forEach(slot => {
        if (slot.value !== null) {
            current = null;

            return;
        }

        if (current) {
            current.to = slot.index;
            current.end = slot.end;
        } else {
            current = {from: slot.index, to: slot.index, first: slot.at, end: slot.end};
            gaps.push(current);
        }
    });

    return gaps;
}

/** The known slots with unknown minutes: their value is the peak of the known ones, so "at least". */
export function partialSlots(slots) {
    return (slots || []).filter(slot => slot.value !== null && slot.unknownMinutes > 0);
}

/**
 * The accessible description of a chart: the label, the latest known
 * value (a peak "up to" in a wide slot) with its time, the gaps, the partly
 * unknown periods, and the marker titles.
 */
export function backlogDescription(label, slots, markerTitles = [], now = nowSeconds()) {
    const known = (slots || []).filter(slot => slot.value !== null);
    const latest = known.length ? known[known.length - 1] : null;
    const gaps = backlogGaps(slots).length;
    const partial = partialSlots(slots).length;
    const parts = [label];

    if (latest) {
        parts.push(`${latest.minutes > 1 ? 'up to ' : ''}${formatCount(latest.value)} at ${slotTime(latest, now)}`);
    } else {
        parts.push('no data');
    }

    if (gaps) parts.push(`${gaps} ${gaps === 1 ? 'gap' : 'gaps'} without data`);
    if (partial) parts.push(`${partial} ${partial === 1 ? 'period' : 'periods'} partly without data (values are at least)`);

    return `${[...parts, ...markerTitles].join(', ')}.`;
}

/**
 * The top of a card's y scale: a round number (series.js niceAxis()) over
 * the largest known value of every card (`shared`) or of the card `key`
 * only (`queue`). `slotsByKey` maps each card to its slots.
 */
export function backlogScale(mode, slotsByKey, key) {
    const keys = backlogScaleMode(mode) === 'queue' ? [key] : Object.keys(slotsByKey || {});
    let max = 0;

    keys.forEach(name => {
        ((slotsByKey || {})[name] || []).forEach(slot => {
            if (slot && typeof slot.value === 'number' && slot.value > max) max = slot.value;
        });
    });

    return niceAxis(max, 2).max;
}

/** The index of the slot at `fraction` (0..1) of the chart's width, or null without slots. */
export function nearestSlot(slots, fraction) {
    if (!Array.isArray(slots) || slots.length === 0) return null;

    const index = Math.floor((Number.isFinite(fraction) ? fraction : 0) * slots.length);

    return Math.min(slots.length - 1, Math.max(0, index));
}

/**
 * The slot that a key moves the tooltip to: Left and Right one slot (from
 * no point: the newest), Home the first, End the last; undefined for any
 * other key (the chart leaves it to the card) or without slots.
 */
export function stepSlot(index, key, count) {
    if (!(count > 0)) return undefined;

    const last = count - 1;
    const current = index === null || index === undefined ? null : Math.min(last, Math.max(0, index));

    switch (key) {
        case 'ArrowLeft':
            return current === null ? last : Math.max(0, current - 1);
        case 'ArrowRight':
            return current === null ? last : Math.min(last, current + 1);
        case 'Home':
            return 0;
        case 'End':
            return last;
        default:
            return undefined;
    }
}

/**
 * The time of a slot in the display zone, with its date when it is not of
 * `now`'s day (the 6 h and 24 h views reach the day before).
 */
export function slotTime(slot, now = nowSeconds()) {
    return slot.minutes > 1 ? formatDayRangeZoned(slot.at, slot.end, now) : formatDayMinuteZoned(slot.at, now);
}

/**
 * The tooltip of a slot: `{value, time, note}`. A one-minute slot gives
 * its value ("12 ready"); a wider slot its peak ("up to 12 ready"), and
 * "at least" when some of its minutes are unknown. An unknown slot says
 * "unknown", never 0.
 */
export function slotTip(slot, now = nowSeconds()) {
    const wide = slot.minutes > 1;
    const time = slotTime(slot, now);

    if (slot.value === null) {
        return {value: 'unknown', time, note: wide ? 'no data in this period' : 'no data for this minute'};
    }

    if (!wide) return {value: `${formatCount(slot.value)} ready`, time, note: null};

    if (slot.unknownMinutes > 0) {
        return {value: `up to at least ${formatCount(slot.value)} ready`, time, note: `${slot.unknownMinutes} of ${slot.minutes} minutes without data`};
    }

    return {value: `up to ${formatCount(slot.value)} ready`, time, note: null};
}

/**
 * The x-axis labels of a card: at most MAX_TICKS round times of the
 * display zone inside the window (series.js axisTicks(), thinned to every
 * nth), each `{at, fraction, label}`: `fraction` of the width from the left,
 * and the date ("11-12") instead of the time on a midnight label.
 * `offsetOf(epoch)` is the zone's offset (time.js zoneOffset()).
 */
export function backlogTicks(starts, slotSeconds, offsetOf) {
    if (!Array.isArray(starts) || starts.length === 0) return [];

    const span = starts.length * slotSeconds;
    let ticks = axisTicks(starts, slotSeconds, offsetOf).ticks;

    if (ticks.length > MAX_TICKS) {
        const every = Math.ceil(ticks.length / MAX_TICKS);

        ticks = ticks.filter((tick, index) => index % every === 0);
    }

    return ticks.map(tick => ({
        at: tick.at,
        fraction: (tick.at - starts[0]) / span,
        label: tick.date ? formatDate(tick.at).slice(5) : formatHourMinute(tick.at),
    }));
}

/**
 * The title of a card: the queue name, and its connections as the sidebar
 * groups them ("database", "database, database_b"). Every card has both,
 * so no card reads "database:default" while another reads "long".
 */
export function cardTitle(row) {
    const connections = row && Array.isArray(row.connections) && row.connections.length ? row.connections : [row.connection];

    return {name: row.name, group: connections.join(', ')};
}

/** The markers read of the Queues screen: since the start of the range, with two minutes before it. */
export function backlogMarkersRequest(now, key) {
    return {url: '/api/markers', params: {since: Math.floor(now - backlogRange(key).minutes * 60 - 120)}, timeKeys: ['since']};
}

/**
 * The queues whose history is read: the cards on screen (the first
 * `cardLimit` rows) and the selected queue.
 */
export function historyRowsOf(rows, cardLimit, selected) {
    const shown = rows.slice(0, cardLimit);

    if (selected && !shown.some(row => row.key === selected.key)) shown.push(selected);

    return shown;
}

/**
 * The Queues screen's first reads, as it asks them when it mounts with the
 * shell's workload: the markers, the retention settings, and the histories
 * of the first HISTORY_CONCURRENCY cards (the reads that start at once; the
 * rest wait for a slot). None of the histories before the workload loaded.
 */
export function queuesFirstRequests(workload, params, key, now) {
    const requests = [backlogMarkersRequest(now, key), {url: '/api/settings/retention', params: {}}];

    if (!Array.isArray(workload)) return requests;

    const rows = normalizeWorkload(workload);
    const selected = params ? findRow(rows, params.connection, params.queue) : null;

    historyRowsOf(rows, CARD_PAGE, selected).slice(0, HISTORY_CONCURRENCY).forEach(row => {
        requests.push(queueRequest('history', row.connection, row.name, {minutes: backlogRange(key).minutes}));
    });

    return requests;
}
