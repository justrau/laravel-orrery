<script>
    import {slotStarts} from '../../support/series';
    import {formatCount, formatHourMinuteZoned, formatTimeRangeZoned} from '../../support/time';
    import {markerName} from './queues';

    /** The drawing width of the view box; the SVG stretches to its container. */
    const WIDTH = 300;

    const TONES = {
        accent: {line: 'var(--color-accent)', fill: 'var(--color-accent-tint)'},
        warning: {line: 'var(--color-warning)', fill: 'var(--color-warning-tint)'},
        paused: {line: 'var(--color-text-tertiary)', fill: 'none'},
    };

    /**
     * The ready backlog of one queue over the history window
     * (`GET /api/queues/{c}/{q}/history`), as a line with an area:
     *
     * - `window` ({ from, to, retentionFrom }) and `buckets` ({ at, ready })
     *   from historyBuckets(); one slot per minute;
     * - a slot without a bucket, with a null `ready`, or that ends at or before
     *   the retention horizon is unknown: the line breaks and a dotted
     *   baseline marks the gap, never a zero;
     * - `max` is the y scale (one scale for all cards);
     * - `markers` draw dashed vertical lines ("Restart requested", "Deploy …");
     * - `pausedFrom` tints the part of the window from the current pause on.
     */
    export default {
        props: {
            window: {type: Object, default: null},
            buckets: {type: Array, default: () => []},
            max: {type: Number, default: 1},
            markers: {type: Array, default: () => []},
            pausedFrom: {type: Number, default: null},
            /** The title of the paused tint (queues.js pausedTitle()): a start only when it is known. */
            pausedTitle: {type: String, default: null},
            tone: {type: String, default: 'accent'},
            height: {type: Number, default: 88},
            label: {type: String, default: 'Ready jobs, past hour'},
        },

        data() {
            return {width: WIDTH};
        },

        computed: {
            starts() {
                return this.window ? slotStarts(this.window.from, this.window.to, 60) : [];
            },

            step() {
                return this.starts.length ? WIDTH / this.starts.length : WIDTH;
            },

            top() {
                return 4;
            },

            bottom() {
                return this.height - 4;
            },

            slots() {
                const byStart = new Map(this.buckets.map(bucket => [bucket.at, bucket]));
                const horizon = this.window ? this.window.retentionFrom : null;

                return this.starts.map((at, index) => {
                    const bucket = byStart.get(at);
                    const expired = horizon !== null && horizon !== undefined && at + 60 <= horizon;
                    const value = bucket && !expired && typeof bucket.ready === 'number' ? bucket.ready : null;

                    return {at, index, value};
                });
            },

            colors() {
                return TONES[this.tone] || TONES.accent;
            },

            segments() {
                const segments = [];
                let current = null;

                this.slots.forEach(slot => {
                    if (slot.value === null) {
                        current = null;

                        return;
                    }

                    const point = {x: slot.index * this.step + this.step / 2, y: this.yOf(slot.value)};

                    if (!current) {
                        current = [];
                        segments.push(current);
                    }

                    current.push(point);
                });

                return segments.map((points, index) => {
                    const line = points.map((point, i) => `${i ? 'L' : 'M'}${point.x.toFixed(1)} ${point.y.toFixed(1)}`).join(' ');
                    const first = points[0];
                    const last = points[points.length - 1];
                    const single = points.length === 1 ? ` L${(first.x + 0.5).toFixed(1)} ${first.y.toFixed(1)}` : '';

                    return {
                        key: index,
                        line: line + single,
                        area: `${line}${single} L${(last.x + (single ? 0.5 : 0)).toFixed(1)} ${this.bottom} L${first.x.toFixed(1)} ${this.bottom} Z`,
                    };
                });
            },

            gaps() {
                const gaps = [];
                let current = null;

                this.slots.forEach(slot => {
                    if (slot.value === null) {
                        if (current) {
                            current.x1 = (slot.index + 1) * this.step;
                            current.last = slot.at;
                        } else {
                            current = {x0: slot.index * this.step, x1: (slot.index + 1) * this.step, first: slot.at, last: slot.at};
                            gaps.push(current);
                        }
                    } else {
                        current = null;
                    }
                });

                return gaps;
            },

            markerLines() {
                if (!this.starts.length) return [];

                const first = this.starts[0];
                const span = this.starts.length * 60;

                return this.markers
                    .filter(marker => marker && typeof marker.at === 'number' && marker.at >= first && marker.at < this.window.to)
                    .map((marker, index) => ({
                        key: `${marker.kind}-${marker.at}-${index}`,
                        x: ((marker.at - first) / span) * WIDTH,
                        title: `${markerName(marker)} ${formatHourMinuteZoned(marker.at)}`,
                    }));
            },

            pausedX() {
                if (this.pausedFrom === null || !this.starts.length) return null;

                const first = this.starts[0];
                const span = this.starts.length * 60;

                return Math.min(WIDTH, Math.max(0, ((this.pausedFrom - first) / span) * WIDTH));
            },

            description() {
                const known = this.slots.filter(slot => slot.value !== null);
                const latest = known.length ? known[known.length - 1] : null;

                return `${this.label}${latest ? `, ${formatCount(latest.value)} at ${formatHourMinuteZoned(latest.at)}` : ', no data'}`
                    + `${this.gaps.length ? `, ${this.gaps.length} ${this.gaps.length === 1 ? 'gap' : 'gaps'} without data` : ''}`
                    + `${this.markerLines.length ? `, ${this.markerLines.map(marker => marker.title).join(', ')}` : ''}`;
            },
        },

        methods: {
            formatTimeRangeZoned,

            yOf(value) {
                const scale = this.max > 0 ? this.max : 1;

                return this.bottom - (Math.min(value, scale) / scale) * (this.bottom - this.top);
            },
        },
    };
</script>

<template>
    <svg class="o-backlog" :viewBox="`0 0 ${width} ${height}`" :height="height" preserveAspectRatio="none" xmlns="http://www.w3.org/2000/svg"
         role="img" :aria-label="description" :data-gaps="gaps.length" :data-markers="markerLines.length" :data-paused="pausedX !== null ? 'true' : null">
        <rect v-if="pausedX !== null" :x="pausedX.toFixed(1)" :y="0" :width="(width - pausedX).toFixed(1)" :height="height"
              fill="color-mix(in srgb, var(--color-paused) 12%, transparent)" data-paused-tint>
            <title>{{ pausedTitle || 'Paused' }}</title>
        </rect>
        <path :d="`M0 ${bottom + 0.5}H${width}`" fill="none" stroke="var(--color-separator)" vector-effect="non-scaling-stroke" />
        <g v-for="gap in gaps" :key="`gap-${gap.first}`" data-gap>
            <path :d="`M${gap.x0.toFixed(1)} ${bottom}H${gap.x1.toFixed(1)}`" fill="none" stroke="var(--color-text-tertiary)"
                  stroke-dasharray="1 3" vector-effect="non-scaling-stroke" />
            <rect :x="gap.x0.toFixed(1)" :y="top" :width="(gap.x1 - gap.x0).toFixed(1)" :height="bottom - top" fill="transparent">
                <title>{{ formatTimeRangeZoned(gap.first, gap.last + 60) }}: no data</title>
            </rect>
        </g>
        <g v-for="segment in segments" :key="segment.key">
            <path v-if="colors.fill !== 'none'" :d="segment.area" :fill="colors.fill" />
            <path :d="segment.line" fill="none" :stroke="colors.line" stroke-width="1.6" stroke-linejoin="round" stroke-linecap="round"
                  vector-effect="non-scaling-stroke" data-line />
        </g>
        <path v-if="pausedX !== null" :d="`M${pausedX.toFixed(1)} ${bottom}H${width}`" fill="none" stroke="var(--color-paused)"
              stroke-width="1.6" stroke-dasharray="3 3" vector-effect="non-scaling-stroke" />
        <g v-for="marker in markerLines" :key="marker.key" data-marker-line>
            <path :d="`M${marker.x.toFixed(1)} 0V${bottom}`" fill="none" stroke="var(--color-text-tertiary)" stroke-dasharray="2 2"
                  vector-effect="non-scaling-stroke" />
            <rect :x="(marker.x - 3).toFixed(1)" y="0" width="6" :height="bottom" fill="transparent"><title>{{ marker.title }}</title></rect>
        </g>
    </svg>
</template>

<style scoped>
    .o-backlog {
        display: block;
        overflow: visible;
        width: 100%;
    }
</style>
