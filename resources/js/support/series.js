/**
 * Series helpers for the charts. A series response has `as_of`,
 * `bucket_seconds`, `retention_from` per series and `buckets` ({ at, …values }).
 * A bucket before a series' `retention_from`, a missing bucket and a null
 * value are unknown: the charts show a gap, never a zero.
 */

/**
 * The bucket slots of the half-open window [from, to): each slot's start,
 * aligned to floor(epoch / bucketSeconds) * bucketSeconds.
 */
export function slotStarts(from, to, bucketSeconds) {
    if (!(bucketSeconds > 0) || !Number.isFinite(from) || !Number.isFinite(to) || to <= from) {
        return [];
    }

    const first = Math.floor(from / bucketSeconds) * bucketSeconds;
    const starts = [];

    for (let at = first; at < to && starts.length < 10000; at += bucketSeconds) {
        starts.push(at);
    }

    return starts;
}

/** The buckets keyed by their start. */
export function bucketsByStart(buckets) {
    const map = new Map();

    (Array.isArray(buckets) ? buckets : []).forEach(bucket => {
        if (bucket && Number.isFinite(bucket.at)) {
            map.set(bucket.at, bucket);
        }
    });

    return map;
}

/**
 * The value of `key` in the slot, or null when it is unknown: no bucket, a
 * slot that ends at or before the retention horizon, or a value that is not
 * a number.
 */
export function slotValue(bucket, key, slotStart, retentionFrom, bucketSeconds) {
    if (!bucket) return null;
    if (Number.isFinite(retentionFrom) && slotStart + (bucketSeconds > 0 ? bucketSeconds : 0) <= retentionFrom) return null;

    const value = bucket[key];

    return typeof value === 'number' && Number.isFinite(value) ? value : null;
}

/**
 * The complete bucket with the largest value of `key` ({ at, value }), or
 * null. Partial buckets do not count: the current minute is still filling.
 */
export function findPeak(buckets, key) {
    let peak = null;

    (Array.isArray(buckets) ? buckets : []).forEach(bucket => {
        const value = bucket && !bucket.partial ? bucket[key] : null;

        if (typeof value === 'number' && (peak === null || value > peak.value)) {
            peak = {at: bucket.at, value};
        }
    });

    return peak;
}

/**
 * The longest run of consecutive complete buckets whose `key` value is below
 * `ratio` × the median ({ from, to, value }: `to` is the start of the run's
 * last bucket and `value` its lowest value), or null. A missing bucket ends
 * a run.
 */
export function findDip(buckets, key, bucketSeconds, ratio = 0.6) {
    const complete = (Array.isArray(buckets) ? buckets : [])
        .filter(bucket => bucket && !bucket.partial && typeof bucket[key] === 'number')
        .sort((a, b) => a.at - b.at);

    if (complete.length < 3) return null;

    const sorted = complete.map(bucket => bucket[key]).sort((a, b) => a - b);
    const median = sorted[Math.floor(sorted.length / 2)];
    const limit = median * ratio;
    let best = null;
    let run = null;

    complete.forEach(bucket => {
        const low = bucket[key] < limit;
        const continues = run && bucket.at - run.to === bucketSeconds;

        if (low && continues) {
            run.to = bucket.at;
            run.value = Math.min(run.value, bucket[key]);
            run.length++;
        } else if (low) {
            run = {from: bucket.at, to: bucket.at, value: bucket[key], length: 1};
        } else {
            run = null;
        }

        if (run && (!best || run.length > best.length)) {
            best = {...run};
        }
    });

    return best ? {from: best.from, to: best.to, value: best.value} : null;
}

/**
 * A "nice" upper bound for an axis with `ticks` equal steps: the smallest
 * 1, 2, 2.5 or 5 × 10^n step whose `ticks` multiples reach `max`.
 */
export function niceAxis(max, ticks = 3) {
    if (!(max > 0)) return {max: ticks, step: 1};

    const raw = max / ticks;
    const magnitude = Math.pow(10, Math.floor(Math.log10(raw)));
    const step = [1, 2, 2.5, 5, 10].map(factor => factor * magnitude).find(candidate => candidate >= raw);

    return {max: step * ticks, step};
}

/** The spacing of the x-axis labels, in seconds: the smallest that gives at most five labels. */
const TICK_INTERVALS = [60, 300, 600, 900, 1800, 3600, 7200, 10800, 21600, 43200, 86400, 172800];

/**
 * The x-axis labels of a chart: the round times of the display zone that
 * fall inside a slot, `{interval, ticks: [{at, slot, onStart, date}]}`.
 * `offsetOf(epoch)` is the zone's offset in seconds east of UTC (time.js
 * zoneOffset()). A round time is searched inside each slot, not only at its
 * start, so a zone with a 30 or 45 minute offset still gets labels on
 * hourly slots. `date` marks a tick that needs its date: every tick of a
 * daily spacing, and a midnight tick of a window of a day or longer.
 */
export function axisTicks(starts, bucketSeconds, offsetOf) {
    const span = starts.length * bucketSeconds;
    const interval = TICK_INTERVALS.find(candidate => span / candidate <= 5 && candidate >= bucketSeconds)
        || TICK_INTERVALS[TICK_INTERVALS.length - 1];
    const ticks = [];

    starts.forEach(slot => {
        const offset = offsetOf(slot);
        const rest = ((slot + offset) % interval + interval) % interval;
        const at = rest === 0 ? slot : slot + interval - rest;

        if (at >= slot + bucketSeconds) return;

        const midnight = ((at + offset) % 86400 + 86400) % 86400 === 0;

        ticks.push({at, slot, onStart: at === slot, date: interval >= 86400 || (span >= 86400 && midnight)});
    });

    return {interval, ticks};
}

/** The estimated width of one character of a 12 px axis label. */
const AXIS_CHAR_WIDTH = 7;

/**
 * Where a chart draws its zone label in the time-label row: from the left
 * edge (`x` 0, anchored at its start), so a long zone name ("GMT+5:30") is
 * never cut. `minLabelX` is the smallest middle of a time label that keeps
 * a label of about five characters clear of it: at least `left + 14`, the
 * rule of the value axis.
 */
export function zoneLabelSpace(zone, left) {
    const width = String(zone).length * AXIS_CHAR_WIDTH;

    return {x: 0, anchor: 'start', minLabelX: Math.max(left + 14, width + 20)};
}
