<script>
    import {bucketsByStart, slotStarts, slotValue} from '../../support/series';
    import {formatCount, formatHourMinuteZoned} from '../../support/time';

    /**
     * A small per-bucket chart for table rows and cards, from a series
     * response: `from`, `to`, `bucketSeconds`, `buckets`, the value `key` and
     * the series' `retentionFrom`.
     *
     * - `variant` "bars" (the failure-group rows) or "line" (the backlog).
     * - An unknown slot (no bucket, before `retentionFrom`, or a null value) is
     *   a gap: bars show a dotted baseline there, and a line breaks.
     * - `max` fixes the scale (for example one scale for all queues); else the
     *   largest value sets it.
     */
    export default {
        props: {
            from: {type: Number, required: true},
            to: {type: Number, required: true},
            bucketSeconds: {type: Number, default: 60},
            buckets: {type: Array, default: () => []},
            valueKey: {type: String, required: true},
            retentionFrom: {type: Number, default: null},
            variant: {type: String, default: 'bars', validator: value => ['bars', 'line'].includes(value)},
            color: {type: String, default: 'var(--color-danger)'},
            fill: {type: String, default: null},
            width: {type: Number, default: 90},
            height: {type: Number, default: 20},
            max: {type: Number, default: null},
            label: {type: String, default: null},
        },

        computed: {
            slots() {
                const byStart = bucketsByStart(this.buckets);
                const starts = slotStarts(this.from, this.to, this.bucketSeconds);

                return starts.map((at, index) => ({
                    at,
                    index,
                    value: slotValue(byStart.get(at), this.valueKey, at, this.retentionFrom, this.bucketSeconds),
                }));
            },

            step() {
                return this.slots.length ? this.width / this.slots.length : this.width;
            },

            scale() {
                const largest = this.slots.reduce((top, slot) => Math.max(top, slot.value || 0), 0);

                return this.max !== null && this.max > 0 ? this.max : largest;
            },

            baseline() {
                return this.height - 0.5;
            },

            bars() {
                if (this.variant !== 'bars' || !(this.scale > 0)) return [];

                const room = this.height - 1;
                const width = Math.max(1, Math.min(this.step * 0.66, 4));

                return this.slots
                    .filter(slot => slot.value > 0)
                    .map(slot => {
                        const height = Math.max(1.5, (Math.min(slot.value, this.scale) / this.scale) * room);

                        return {
                            key: slot.at,
                            x: slot.index * this.step + (this.step - width) / 2,
                            y: room - height,
                            width,
                            height,
                            title: `${formatHourMinuteZoned(slot.at)} · ${formatCount(slot.value)}`,
                        };
                    });
            },

            gapSegments() {
                const segments = [];
                let segment = null;

                this.slots.forEach(slot => {
                    if (slot.value === null) {
                        if (segment) {
                            segment.x1 = (slot.index + 1) * this.step;
                        } else {
                            segment = {x0: slot.index * this.step, x1: (slot.index + 1) * this.step};
                            segments.push(segment);
                        }
                    } else {
                        segment = null;
                    }
                });

                return segments;
            },

            knownSegments() {
                const segments = [];
                let start = null;

                this.slots.forEach(slot => {
                    if (slot.value !== null && start === null) {
                        start = slot.index;
                    }

                    if (slot.value === null && start !== null) {
                        segments.push([start, slot.index]);
                        start = null;
                    }
                });

                if (start !== null) segments.push([start, this.slots.length]);

                return segments;
            },

            lines() {
                if (this.variant !== 'line') return [];

                const top = 2;
                const bottom = this.height - 2;
                const scale = this.scale > 0 ? this.scale : 1;

                return this.knownSegments.map(([first, end]) => {
                    const points = this.slots.slice(first, end).map(slot => ({
                        x: slot.index * this.step + this.step / 2,
                        y: bottom - (Math.min(slot.value, scale) / scale) * (bottom - top),
                    }));
                    const line = points.map((point, index) => `${index ? 'L' : 'M'}${point.x.toFixed(1)} ${point.y.toFixed(1)}`).join(' ');
                    const area = points.length
                        ? `${line} L${points[points.length - 1].x.toFixed(1)} ${bottom} L${points[0].x.toFixed(1)} ${bottom} Z`
                        : '';

                    return {key: first, line, area, last: points[points.length - 1]};
                });
            },

            lastPoint() {
                const last = this.lines[this.lines.length - 1];

                return last && last.last && this.slots[this.slots.length - 1].value !== null ? last.last : null;
            },
        },
    };
</script>

<template>
    <svg :width="width" :height="height" :viewBox="`0 0 ${width} ${height}`" xmlns="http://www.w3.org/2000/svg" class="o-sparkline"
         role="img" :aria-label="label || `Sparkline of ${valueKey}`">
        <path v-if="variant === 'bars'" :d="`M0 ${baseline}H${width}`" fill="none" stroke="var(--color-separator)" />
        <path v-for="gap in gapSegments" :key="`gap-${gap.x0}`" :d="`M${gap.x0.toFixed(1)} ${baseline}H${gap.x1.toFixed(1)}`" fill="none"
              stroke="var(--color-text-tertiary)" stroke-dasharray="1 2" stroke-width="1" class="o-sparkline__gap" />
        <rect v-for="bar in bars" :key="bar.key" :x="bar.x.toFixed(1)" :y="bar.y.toFixed(1)" :width="bar.width.toFixed(1)"
              :height="bar.height.toFixed(1)" rx="0.6" :fill="color">
            <title>{{ bar.title }}</title>
        </rect>
        <g v-for="segment in lines" :key="segment.key">
            <path v-if="fill" :d="segment.area" :fill="fill" />
            <path :d="segment.line" fill="none" :stroke="color" stroke-width="1.6" stroke-linejoin="round" stroke-linecap="round" />
        </g>
        <circle v-if="variant === 'line' && lastPoint" :cx="lastPoint.x.toFixed(1)" :cy="lastPoint.y.toFixed(1)" r="2.4" :fill="color" />
    </svg>
</template>

<style scoped>
    .o-sparkline {
        display: block;
        flex-shrink: 0;
        overflow: visible;
    }
</style>
