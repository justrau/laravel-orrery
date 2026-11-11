<script>
    import {slotStarts} from '../../support/series';
    import {formatCompactCount, formatCount, formatHourMinuteZoned, formatTimeRangeZoned, zoneOffset} from '../../support/time';
    import {backlogSlots, backlogTicks, nearestSlot, slotSecondsFor, slotTip, stepSlot} from './backlog.js';
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
     * (`GET /api/queues/history`), as a line with an area:
     *
     * - `window` ({ from, to, retentionFrom }) and `buckets` ({ at, ready })
     *   from historyBuckets(); a window longer than two hours is drawn in
     *   slots of several minutes, each at its peak (backlog.js
     *   backlogSlots(), at most 120 slots);
     * - a slot without a known minute is unknown: the line breaks and a
     *   dotted baseline marks the gap, never a zero;
     * - `max` is the top of the y scale (the screen's shared or per-queue
     *   scale); the axis names it and 0, and a few round times of the display
     *   zone label the x axis (the section's subtitle names the zone);
     * - the pointer, or the focus with Left, Right, Home and End, shows the
     *   nearest slot in a tooltip with a guide line: its value and time, or
     *   "unknown"; Escape hides it. A polite live region repeats it for a
     *   screen reader;
     * - `markers` draw dashed vertical lines ("Restart requested", "Deploy …");
     * - `pausedFrom` tints the part of the window from the current pause on.
     *
     * The positions of the HTML parts (labels, guide, tooltip) are `:style`
     * bindings (the CSSOM), which the Content Security Policy allows.
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
            axis: {type: Boolean, default: true},
        },

        data() {
            return {width: WIDTH, active: null, announce: ''};
        },

        computed: {
            slotSeconds() {
                return this.window ? slotSecondsFor(Math.ceil((this.window.to - Math.floor(this.window.from / 60) * 60) / 60)) : 60;
            },

            slots() {
                return backlogSlots(this.window, this.buckets, this.slotSeconds);
            },

            starts() {
                return this.slots.map(slot => slot.at);
            },

            step() {
                return this.slots.length ? WIDTH / this.slots.length : WIDTH;
            },

            top() {
                return 4;
            },

            bottom() {
                return this.height - 4;
            },

            scale() {
                return this.max > 0 ? this.max : 1;
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
                            current.end = slot.end;
                        } else {
                            current = {x0: slot.index * this.step, x1: (slot.index + 1) * this.step, first: slot.at, end: slot.end};
                            gaps.push(current);
                        }
                    } else {
                        current = null;
                    }
                });

                return gaps;
            },

            span() {
                return this.starts.length * this.slotSeconds;
            },

            markerLines() {
                if (!this.starts.length) return [];

                const first = this.starts[0];

                return this.markers
                    .filter(marker => marker && typeof marker.at === 'number' && marker.at >= first && marker.at < this.window.to)
                    .map((marker, index) => ({
                        key: `${marker.kind}-${marker.at}-${index}`,
                        x: ((marker.at - first) / this.span) * WIDTH,
                        title: `${markerName(marker)} ${formatHourMinuteZoned(marker.at)}`,
                    }));
            },

            pausedX() {
                if (this.pausedFrom === null || !this.starts.length) return null;

                return Math.min(WIDTH, Math.max(0, ((this.pausedFrom - this.starts[0]) / this.span) * WIDTH));
            },

            ticks() {
                return this.axis ? backlogTicks(this.starts, this.slotSeconds, zoneOffset) : [];
            },

            maxLabel() {
                return formatCompactCount(this.scale);
            },

            activeSlot() {
                return this.active === null ? null : this.slots[this.active] || null;
            },

            tip() {
                return this.activeSlot ? slotTip(this.activeSlot) : null;
            },

            /** The guide's place, in % of the width: the middle of the active slot. */
            activeLeft() {
                return this.activeSlot ? ((this.activeSlot.index + 0.5) / this.slots.length) * 100 : null;
            },

            guideStyle() {
                return this.activeLeft === null ? null : {left: `${this.activeLeft.toFixed(2)}%`};
            },

            dotStyle() {
                if (!this.activeSlot || this.activeSlot.value === null) return null;

                return {left: `${this.activeLeft.toFixed(2)}%`, top: `${this.yOf(this.activeSlot.value).toFixed(1)}px`};
            },

            /** The tooltip opens to the right of the guide, or to its left in the right part of the chart. */
            tipSide() {
                if (this.activeLeft === null) return 'start';
                if (this.activeLeft > 70) return 'end';

                return this.activeLeft < 30 ? 'start' : 'middle';
            },

            frameStyle() {
                return {height: `${this.height}px`};
            },

            description() {
                const known = this.slots.filter(slot => slot.value !== null);
                const latest = known.length ? known[known.length - 1] : null;

                return `${this.label}${latest ? `, ${formatCount(latest.value)} at ${formatHourMinuteZoned(latest.at)}` : ', no data'}`
                    + `${this.gaps.length ? `, ${this.gaps.length} ${this.gaps.length === 1 ? 'gap' : 'gaps'} without data` : ''}`
                    + `${this.markerLines.length ? `, ${this.markerLines.map(marker => marker.title).join(', ')}` : ''}`
                    + '. Use the Left and Right keys to read the values.';
            },
        },

        watch: {
            // A new window (another range) has other slots.
            slotSeconds() {
                this.active = null;
            },
        },

        methods: {
            formatTimeRangeZoned,

            yOf(value) {
                return this.bottom - (Math.min(value, this.scale) / this.scale) * (this.bottom - this.top);
            },

            tickClass(tick) {
                if (tick.fraction < 0.08) return 'o-backlog__tick--start';

                return tick.fraction > 0.92 ? 'o-backlog__tick--end' : null;
            },

            tickStyle(tick) {
                return {left: `${(tick.fraction * 100).toFixed(2)}%`};
            },

            point(event) {
                const rect = event.currentTarget.getBoundingClientRect();

                if (!rect.width) return;

                this.active = nearestSlot(this.slots, (event.clientX - rect.left) / rect.width);
            },

            leave() {
                if (document.activeElement !== this.$refs.frame) this.active = null;
            },

            key(event) {
                if (event.key === 'Escape') {
                    if (this.active === null) return;

                    event.preventDefault();
                    event.stopPropagation();
                    this.active = null;

                    return;
                }

                const next = stepSlot(this.active, event.key, this.slots.length);

                if (next === undefined) return;

                event.preventDefault();
                this.active = next;

                const tip = this.tip;

                this.announce = tip ? [tip.value, tip.time, tip.note].filter(Boolean).join(', ') : '';
            },

            blur() {
                this.active = null;
                this.announce = '';
            },
        },
    };
</script>

<template>
    <div class="o-backlog" :class="{'o-backlog--axis': axis}">
        <div ref="frame" class="o-backlog__frame" :style="frameStyle" tabindex="0" role="img" :aria-label="description"
             data-backlog-plot @pointermove="point" @pointerleave="leave" @keydown="key" @blur="blur">
            <svg class="o-backlog__svg" :viewBox="`0 0 ${width} ${height}`" :height="height" preserveAspectRatio="none" xmlns="http://www.w3.org/2000/svg"
                 aria-hidden="true" :data-gaps="gaps.length" :data-markers="markerLines.length" :data-paused="pausedX !== null ? 'true' : null">
                <rect v-if="pausedX !== null" :x="pausedX.toFixed(1)" :y="0" :width="(width - pausedX).toFixed(1)" :height="height"
                      fill="color-mix(in srgb, var(--color-paused) 12%, transparent)" data-paused-tint>
                    <title>{{ pausedTitle || 'Paused' }}</title>
                </rect>
                <path v-if="axis" :d="`M0 ${top + 0.5}H${width}`" fill="none" stroke="var(--color-separator)" stroke-dasharray="2 3"
                      vector-effect="non-scaling-stroke" />
                <path :d="`M0 ${bottom + 0.5}H${width}`" fill="none" stroke="var(--color-separator)" vector-effect="non-scaling-stroke" />
                <g v-for="gap in gaps" :key="`gap-${gap.first}`" data-gap>
                    <path :d="`M${gap.x0.toFixed(1)} ${bottom}H${gap.x1.toFixed(1)}`" fill="none" stroke="var(--color-text-tertiary)"
                          stroke-dasharray="1 3" vector-effect="non-scaling-stroke" />
                    <rect :x="gap.x0.toFixed(1)" :y="top" :width="(gap.x1 - gap.x0).toFixed(1)" :height="bottom - top" fill="transparent">
                        <title>{{ formatTimeRangeZoned(gap.first, gap.end) }}: no data</title>
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

            <span v-if="axis" class="o-backlog__y o-backlog__y--max" aria-hidden="true" data-axis-max>{{ maxLabel }}</span>
            <span v-if="axis" class="o-backlog__y o-backlog__y--zero" aria-hidden="true">0</span>

            <span v-if="guideStyle" class="o-backlog__guide" :style="guideStyle" aria-hidden="true"></span>
            <span v-if="dotStyle" class="o-backlog__dot" :style="dotStyle" aria-hidden="true"></span>
            <div v-if="tip" class="o-backlog__tip" :class="`o-backlog__tip--${tipSide}`" :style="guideStyle" aria-hidden="true" data-backlog-tip>
                <span class="o-backlog__tip-value" :class="{'o-backlog__tip-value--unknown': activeSlot.value === null}">{{ tip.value }}</span>
                <span class="o-backlog__tip-time">{{ tip.time }}</span>
                <span v-if="tip.note" class="o-backlog__tip-note">{{ tip.note }}</span>
            </div>
        </div>

        <div v-if="axis && ticks.length" class="o-backlog__ticks" aria-hidden="true" data-axis-ticks>
            <span v-for="tick in ticks" :key="tick.at" class="o-backlog__tick" :class="tickClass(tick)" :style="tickStyle(tick)">{{ tick.label }}</span>
        </div>

        <span class="o-backlog__live" aria-live="polite">{{ announce }}</span>
    </div>
</template>

<style scoped>
    .o-backlog {
        display: flex;
        flex-direction: column;
        gap: 3px;
        min-width: 0;
    }

    .o-backlog__frame {
        border-radius: 4px;
        position: relative;
        touch-action: pan-y;
    }

    .o-backlog__frame:focus-visible {
        outline: 2px solid color-mix(in srgb, var(--color-accent) 55%, transparent);
        outline-offset: 2px;
    }

    .o-backlog__svg {
        display: block;
        overflow: visible;
        width: 100%;
    }

    /* The y axis: its top and 0, small and recessive, over the plot's left edge. */
    .o-backlog__y {
        color: var(--color-text-tertiary);
        font-size: 10px;
        font-variant-numeric: tabular-nums;
        left: 0;
        line-height: 12px;
        pointer-events: none;
        position: absolute;
    }

    .o-backlog__y--max {
        top: 6px;
    }

    .o-backlog__y--zero {
        bottom: 6px;
    }

    .o-backlog__guide {
        border-left: 1px solid var(--color-text-tertiary);
        bottom: 4px;
        pointer-events: none;
        position: absolute;
        top: 0;
    }

    .o-backlog__dot {
        background-color: var(--color-bg);
        border: 2px solid var(--color-accent);
        border-radius: 50%;
        box-sizing: border-box;
        height: 9px;
        margin-left: -4.5px;
        margin-top: -4.5px;
        pointer-events: none;
        position: absolute;
        width: 9px;
    }

    .o-backlog__tip {
        background-color: var(--color-bg);
        border: 1px solid var(--color-separator);
        border-radius: 6px;
        bottom: calc(100% + 4px);
        box-shadow: var(--shadow-raised);
        display: flex;
        flex-direction: column;
        font-size: var(--text-caption);
        line-height: 16px;
        max-width: 220px;
        padding: 4px 8px;
        pointer-events: none;
        position: absolute;
        white-space: nowrap;
        z-index: 4;
    }

    .o-backlog__tip--middle {
        transform: translateX(-50%);
    }

    .o-backlog__tip--end {
        transform: translateX(-100%);
    }

    .o-backlog__tip-value {
        color: var(--color-text);
        font-variant-numeric: tabular-nums;
        font-weight: var(--font-weight-semibold);
    }

    .o-backlog__tip-value--unknown {
        color: var(--color-text-secondary);
    }

    .o-backlog__tip-time,
    .o-backlog__tip-note {
        color: var(--color-text-secondary);
    }

    .o-backlog__ticks {
        color: var(--color-text-tertiary);
        font-size: 10px;
        font-variant-numeric: tabular-nums;
        height: 12px;
        line-height: 12px;
        position: relative;
    }

    .o-backlog__tick {
        position: absolute;
        top: 0;
        transform: translateX(-50%);
        white-space: nowrap;
    }

    /* A label at an edge stays inside the card. */
    .o-backlog__tick--start {
        transform: none;
    }

    .o-backlog__tick--end {
        transform: translateX(-100%);
    }

    .o-backlog__live {
        clip-path: inset(50%);
        height: 1px;
        overflow: hidden;
        position: absolute;
        white-space: nowrap;
        width: 1px;
    }
</style>
