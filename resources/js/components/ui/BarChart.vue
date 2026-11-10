<script>
    import {axisTicks, zoneLabelSpace, bucketsByStart, niceAxis, slotStarts, slotValue} from '../../support/series';
    import {formatCount, formatDate, formatDayMinute, formatDayMinuteZoned, formatDayRangeZoned, formatHourMinute, timeZoneLabel, zoneOffset} from '../../support/time';

    let instances = 0;

    /** The vertical layout of the design. */
    const LAYOUT = {
        left: 44,
        markerTop: 18,
        mainTop: 34,
        mainBottom: 170,
        bandTop: 186,
        bandBottom: 210,
        axisY: 226,
        height: 232,
    };

    /** The estimated width of one marker-label character (12 px text), and the gap between two labels. */
    const MARKER_CHAR_WIDTH = 7;
    const MARKER_LABEL_GAP = 6;

    /**
     * Per-bucket bars over a window, from a series response:
     *
     * - `from`, `to`: the half-open window [from, to), epoch seconds;
     * - `bucketSeconds` and `buckets` ({ at, partial?, …values });
     * - `series`: the stacked main bars, [{ key, label, color, retentionFrom }];
     * - `band`: the failures band under the main bars, { key, label, axisLabel?, color, retentionFrom } or null;
 *   the value axis has room for a short `axisLabel` only (else `label`); the tooltips use `label`;
     * - `markers`: vertical lines, [{ at, kind: 'restart' | 'deploy', label }];
     * - `annotations`: [{ at, to?, text, target: 'main' | 'band' }]. A point
     *   annotation has a horizontal leader to its bar; a range (with `to`, the
     *   start of the last bucket) has a bracket and a vertical leader.
     *
     * A slot without a bucket, before a series' `retentionFrom`, or with a
     * null value is unknown: a hatched gap, never a zero bar. The partial
     * (current) bucket is drawn lighter.
     */
    export default {
        props: {
            from: {type: Number, required: true},
            to: {type: Number, required: true},
            bucketSeconds: {type: Number, default: 60},
            buckets: {type: Array, default: () => []},
            series: {type: Array, required: true},
            band: {type: Object, default: null},
            markers: {type: Array, default: () => []},
            annotations: {type: Array, default: () => []},
            yTicks: {type: Number, default: 3},
            unit: {type: String, default: ''},
        },

        data() {
            return {
                width: 538,
                uid: `o-chart-${++instances}`,
                layout: LAYOUT,
            };
        },

        mounted() {
            this.measure();

            if (typeof ResizeObserver !== 'undefined') {
                this.observer = new ResizeObserver(() => this.measure());
                this.observer.observe(this.$el);
            }
        },

        beforeUnmount() {
            if (this.observer) this.observer.disconnect();
        },

        computed: {
            starts() {
                return slotStarts(this.from, this.to, this.bucketSeconds);
            },

            byStart() {
                return bucketsByStart(this.buckets);
            },

            plotWidth() {
                return Math.max(10, this.width - LAYOUT.left);
            },

            step() {
                return this.starts.length ? this.plotWidth / this.starts.length : this.plotWidth;
            },

            barWidth() {
                return Math.max(1, Math.min(this.step * 0.656, 18));
            },

            windowStart() {
                return this.starts.length ? this.starts[0] : this.from;
            },

            slots() {
                return this.starts.map((at, index) => {
                    const bucket = this.byStart.get(at) || null;
                    const values = this.series.map(entry => slotValue(bucket, entry.key, at, entry.retentionFrom, this.bucketSeconds));
                    const known = values.every(value => value !== null);

                    return {
                        at,
                        index,
                        x: LAYOUT.left + index * this.step,
                        partial: !!(bucket && bucket.partial),
                        values,
                        total: known ? values.reduce((sum, value) => sum + value, 0) : null,
                        band: this.band ? slotValue(bucket, this.band.key, at, this.band.retentionFrom, this.bucketSeconds) : null,
                        missingBucket: !bucket,
                    };
                });
            },

            axis() {
                const max = this.slots.reduce((top, slot) => Math.max(top, slot.total || 0), 0);

                return niceAxis(max, this.yTicks);
            },

            bandMax() {
                return this.slots.reduce((top, slot) => Math.max(top, slot.band || 0), 0);
            },

            gridLines() {
                const lines = [];

                for (let tick = 1; tick <= this.yTicks; tick++) {
                    const value = this.axis.step * tick;

                    lines.push({value, y: this.yOf(value), label: formatCount(value)});
                }

                return lines;
            },

            bars() {
                const bars = [];

                this.slots.forEach(slot => {
                    if (slot.total === null) return;

                    let base = LAYOUT.mainBottom;

                    slot.values.forEach((value, seriesIndex) => {
                        if (!(value > 0)) return;

                        const height = (value / this.axis.max) * (LAYOUT.mainBottom - LAYOUT.mainTop);
                        const top = base - height;

                        bars.push({
                            key: `${slot.at}-${seriesIndex}`,
                            x: slot.x + (this.step - this.barWidth) / 2,
                            y: top,
                            height: Math.max(0.5, height),
                            color: this.series[seriesIndex].color,
                            partial: slot.partial,
                            title: this.barTitle(slot, seriesIndex, value),
                        });

                        base = top;
                    });
                });

                return bars;
            },

            bandBars() {
                if (!this.band || !(this.bandMax > 0)) return [];

                const room = LAYOUT.bandBottom - LAYOUT.bandTop;

                return this.slots
                    .filter(slot => slot.band > 0)
                    .map(slot => {
                        const height = Math.max(2.5, (slot.band / this.bandMax) * room);

                        return {
                            key: slot.at,
                            x: slot.x + (this.step - this.barWidth) / 2,
                            y: LAYOUT.bandBottom - height,
                            height,
                            partial: slot.partial,
                            title: `${formatDayMinuteZoned(slot.at, this.to)} · ${formatCount(slot.band)} ${this.band.label || this.band.key}${slot.partial ? ' (in progress)' : ''}`,
                        };
                    });
            },

            gaps() {
                return [
                    ...this.gapRuns(slot => slot.total === null, LAYOUT.mainTop, LAYOUT.mainBottom, this.series),
                    ...(this.band ? this.gapRuns(slot => slot.band === null, LAYOUT.bandTop, LAYOUT.bandBottom, [this.band]) : []),
                ];
            },

            xLabels() {
                // The labels fall on round times of the display time zone (series.js axisTicks()), each with its
                // own offset, so they stay on round hours across a daylight-saving change and in zones with a
                // 30 or 45 minute offset. A tick on a slot start sits under the bar's middle; a tick inside a
                // slot sits at its own time. A tick of another day shows its date, never a bare "00:00".
                return axisTicks(this.starts, this.bucketSeconds, zoneOffset).ticks
                    .map(tick => ({
                        at: tick.at,
                        x: this.xOf(tick.at) + (tick.onStart ? this.step / 2 : 0),
                        label: tick.date ? formatDate(tick.at) : formatHourMinute(tick.at),
                    }))
                    .filter(label => label.x > this.zoneSpace.minLabelX && label.x < this.width - 14);
            },

            /** The zone label from the left edge, and the room the time labels leave for it (series.js zoneLabelSpace()). */
            zoneSpace() {
                return zoneLabelSpace(this.zoneLabel, LAYOUT.left);
            },

            /**
             * The marker lines and their labels. Labels share one row, so a
             * label that would overlap a newer marker's label is dropped: its
             * line stays, and the line's tooltip names it (`showLabel` false).
             */
            markerLines() {
                const lines = this.markers
                    .filter(marker => marker && Number.isFinite(marker.at) && marker.at >= this.windowStart && marker.at < this.to)
                    .map((marker, index) => {
                        const x = this.xOf(marker.at);
                        const right = x > LAYOUT.left + 130;
                        const name = marker.kind === 'deploy'
                            ? ['Deploy', marker.label].filter(Boolean).join(' ')
                            : 'Restart requested';
                        const time = formatDayMinute(marker.at, this.to);
                        const textX = right ? x - 4 : x + 4;
                        const width = (name.length + 1 + time.length) * MARKER_CHAR_WIDTH;

                        return {
                            key: `${marker.kind}-${marker.at}-${index}`,
                            at: marker.at,
                            x,
                            name,
                            time,
                            title: `${name} ${formatDayMinuteZoned(marker.at, this.to)}`,
                            anchor: right ? 'end' : 'start',
                            textX,
                            box: right ? [textX - width, textX] : [textX, textX + width],
                            showLabel: true,
                        };
                    });

                const shown = [];

                [...lines].sort((a, b) => b.at - a.at).forEach(line => {
                    const overlaps = shown.some(box => line.box[0] < box[1] + MARKER_LABEL_GAP && box[0] < line.box[1] + MARKER_LABEL_GAP);

                    if (overlaps) {
                        line.showLabel = false;
                    } else {
                        shown.push(line.box);
                    }
                });

                return lines;
            },

            annotationMarks() {
                return this.annotations
                    .filter(entry => entry && Number.isFinite(entry.at) && typeof entry.text === 'string')
                    .map((entry, index) => (entry.target === 'band'
                        ? this.bandAnnotation(entry, index)
                        : this.mainAnnotation(entry, index)))
                    .filter(Boolean);
            },

            /** The zone of the axis labels, under the value axis: absolute times show the zone. */
            zoneLabel() {
                return timeZoneLabel(this.to);
            },

            description() {
                const names = this.series.map(entry => entry.label || entry.key).join(', ');

                return `Bar chart of ${names} per ${this.bucketSeconds === 60 ? 'minute' : `${this.bucketSeconds} seconds`}`
                    + `, ${formatDayRangeZoned(this.from, this.to, this.to)}.`;
            },
        },

        methods: {
            measure() {
                const width = this.$el && this.$el.clientWidth;

                if (width > 0 && Math.abs(width - this.width) > 0.5) {
                    this.width = width;
                }
            },

            xOf(at) {
                const span = this.starts.length * this.bucketSeconds || (this.to - this.from);

                return LAYOUT.left + ((at - this.windowStart) / span) * this.plotWidth;
            },

            yOf(value) {
                return LAYOUT.mainBottom - (value / this.axis.max) * (LAYOUT.mainBottom - LAYOUT.mainTop);
            },

            slotAt(at) {
                const index = Math.floor((at - this.windowStart) / this.bucketSeconds);

                return this.slots[index] || null;
            },

            barTitle(slot, seriesIndex, value) {
                const entry = this.series[seriesIndex];
                const unit = this.unit ? ` ${this.unit}` : '';

                return `${formatDayMinuteZoned(slot.at, this.to)} · ${formatCount(value)}${unit} ${entry.label || entry.key}${slot.partial ? ' (in progress)' : ''}`;
            },

            /**
             * The runs of consecutive unknown slots, as hatched rectangles. A
             * run before every series' retention horizon is "not kept"; any
             * other run is "no data" (unobserved gaps cannot be ruled out).
             */
            gapRuns(unknown, top, bottom, entries) {
                const runs = [];
                let run = null;

                this.slots.forEach(slot => {
                    if (unknown(slot)) {
                        if (!run) {
                            run = {first: slot, last: slot};
                            runs.push(run);
                        } else {
                            run.last = slot;
                        }
                    } else {
                        run = null;
                    }
                });

                return runs.map(entry => {
                    const x = entry.first.x;
                    const width = entry.last.x + this.step - x;
                    const expired = entries.every(series => Number.isFinite(series.retentionFrom)
                        && entry.last.at + this.bucketSeconds <= series.retentionFrom);
                    const label = expired ? 'not kept' : 'no data';

                    return {
                        key: `${top}-${entry.first.at}`,
                        x,
                        y: top,
                        width,
                        height: bottom - top,
                        label,
                        showLabel: width >= 56 && bottom - top >= 40,
                        title: `${formatDayRangeZoned(entry.first.at, entry.last.at + this.bucketSeconds, this.to)}: `
                            + (expired ? 'older than the retention, no longer kept' : 'no data'),
                    };
                });
            },

            mainAnnotation(entry, index) {
                const first = this.slotAt(entry.at);

                if (!first) return null;

                if (Number.isFinite(entry.to)) {
                    const last = this.slotAt(entry.to) || this.slots[this.slots.length - 1];
                    const covered = this.slots.slice(first.index, last.index + 1);
                    const top = covered.reduce((lowest, slot) => Math.min(lowest, slot.total !== null ? this.yOf(slot.total) : LAYOUT.mainBottom), LAYOUT.mainBottom);
                    const x0 = first.x + (this.step - this.barWidth) / 2;
                    const x1 = last.x + (this.step + this.barWidth) / 2;
                    const bracketY = top - 3;
                    const middle = (x0 + x1) / 2;
                    const labelY = Math.max(LAYOUT.mainTop + 12, bracketY - 42);
                    const right = middle < this.width - 120;

                    return {
                        key: `a${index}`,
                        path: `M${x0.toFixed(1)} ${(bracketY + 3).toFixed(1)}V${bracketY.toFixed(1)}H${x1.toFixed(1)}V${(bracketY + 3).toFixed(1)}`
                            + `M${middle.toFixed(1)} ${bracketY.toFixed(1)}V${(labelY + 4).toFixed(1)}`,
                        text: entry.text,
                        x: right ? middle - 6 : middle + 6,
                        y: labelY,
                        anchor: right ? 'start' : 'end',
                    };
                }

                if (first.total === null) return null;

                const barRight = first.x + (this.step + this.barWidth) / 2;
                const barLeft = first.x + (this.step - this.barWidth) / 2;
                const y = this.yOf(first.total);
                const right = barRight < this.width - 120;

                return {
                    key: `a${index}`,
                    path: right
                        ? `M${barRight.toFixed(1)} ${y.toFixed(1)}H${(barRight + 10).toFixed(1)}`
                        : `M${barLeft.toFixed(1)} ${y.toFixed(1)}H${(barLeft - 10).toFixed(1)}`,
                    text: entry.text,
                    x: right ? barRight + 14 : barLeft - 14,
                    y: y + 4,
                    anchor: right ? 'start' : 'end',
                };
            },

            bandAnnotation(entry, index) {
                if (!this.band) return null;

                const first = this.slotAt(entry.at);

                if (!first) return null;

                const x0 = first.x + (this.step - this.barWidth) / 2;
                const y = (LAYOUT.bandTop + LAYOUT.bandBottom) / 2;
                const left = x0 > LAYOUT.left + 150;

                if (left) {
                    return {
                        key: `b${index}`,
                        path: `M${(x0 - 7).toFixed(1)} ${y}H${(x0 - 3).toFixed(1)}`,
                        text: entry.text,
                        x: x0 - 9,
                        y: y + 4,
                        anchor: 'end',
                    };
                }

                const last = Number.isFinite(entry.to) ? (this.slotAt(entry.to) || first) : first;
                const x1 = last.x + (this.step + this.barWidth) / 2;

                return {
                    key: `b${index}`,
                    path: `M${(x1 + 3).toFixed(1)} ${y}H${(x1 + 7).toFixed(1)}`,
                    text: entry.text,
                    x: x1 + 9,
                    y: y + 4,
                    anchor: 'start',
                };
            },
        },
    };
</script>

<template>
    <div class="o-chart">
        <svg :width="width" :height="layout.height" :viewBox="`0 0 ${width} ${layout.height}`" xmlns="http://www.w3.org/2000/svg"
             role="img" :aria-label="description">
            <defs>
                <pattern :id="`${uid}-hatch`" width="6" height="6" patternUnits="userSpaceOnUse" patternTransform="rotate(45)">
                    <rect width="6" height="6" fill="var(--color-subtle)" />
                    <path d="M0 0V6" stroke="var(--color-separator)" stroke-width="2" />
                </pattern>
            </defs>

            <path v-for="line in gridLines" :key="line.value" :d="`M${layout.left} ${line.y.toFixed(1)}H${width}`"
                  fill="none" stroke="var(--color-separator)" stroke-dasharray="2 3" />

            <g v-for="gap in gaps" :key="gap.key">
                <rect :x="gap.x.toFixed(1)" :y="gap.y" :width="gap.width.toFixed(1)" :height="gap.height" :fill="`url(#${uid}-hatch)`" rx="2">
                    <title>{{ gap.title }}</title>
                </rect>
                <text v-if="gap.showLabel" :x="(gap.x + gap.width / 2).toFixed(1)" :y="gap.y + gap.height / 2 + 4" text-anchor="middle"
                      class="o-chart__gap-label">{{ gap.label }}</text>
            </g>

            <path v-for="marker in markerLines" :key="marker.key" :d="`M${marker.x.toFixed(1)} ${layout.markerTop}V${band ? layout.bandBottom : layout.mainBottom}`"
                  fill="none" stroke="var(--color-text-secondary)" stroke-dasharray="3 3" data-marker-line>
                <title>{{ marker.title }}</title>
            </path>

            <path :d="`M${layout.left} ${layout.mainBottom + 0.5}H${width}`" fill="none" stroke="var(--color-separator)" />
            <path v-if="band" :d="`M${layout.left} ${layout.bandBottom + 0.5}H${width}`" fill="none" stroke="var(--color-separator)" />

            <rect v-for="bar in bars" :key="bar.key" :x="bar.x.toFixed(2)" :y="bar.y.toFixed(1)" :width="barWidth.toFixed(2)"
                  :height="bar.height.toFixed(1)" rx="1.2" :fill="bar.color" :opacity="bar.partial ? 0.5 : null">
                <title>{{ bar.title }}</title>
            </rect>

            <rect v-for="bar in bandBars" :key="`band-${bar.key}`" :x="bar.x.toFixed(2)" :y="bar.y.toFixed(1)" :width="barWidth.toFixed(2)"
                  :height="bar.height.toFixed(1)" rx="1" :fill="band.color" :opacity="bar.partial ? 0.5 : null">
                <title>{{ bar.title }}</title>
            </rect>

            <text v-for="line in gridLines" :key="`label-${line.value}`" x="36" :y="(line.y + 4).toFixed(1)" text-anchor="end"
                  class="o-chart__axis">{{ line.label }}</text>
            <text v-if="band" x="36" :y="layout.bandBottom - 8" text-anchor="end" class="o-chart__band-label">{{ band.axisLabel || band.label || band.key }}<title>{{ band.label || band.key }}</title></text>

            <text :x="zoneSpace.x" :y="layout.axisY" :text-anchor="zoneSpace.anchor" class="o-chart__axis" data-axis-zone>{{ zoneLabel }}</text>
            <text v-for="label in xLabels" :key="`x-${label.at}`" :x="label.x.toFixed(1)" :y="layout.axisY" text-anchor="middle"
                  class="o-chart__axis">{{ label.label }}</text>

            <text v-for="marker in markerLines.filter(line => line.showLabel)" :key="`m-${marker.key}`" data-marker-label :x="marker.textX.toFixed(1)" y="12" :text-anchor="marker.anchor"
                  class="o-chart__marker"><tspan class="o-chart__marker-name">{{ marker.name }}</tspan> {{ marker.time }}</text>

            <g v-for="mark in annotationMarks" :key="mark.key">
                <path :d="mark.path" fill="none" stroke="var(--color-text-tertiary)" />
                <text :x="mark.x.toFixed(1)" :y="mark.y.toFixed(1)" :text-anchor="mark.anchor" class="o-chart__annotation">{{ mark.text }}</text>
            </g>
        </svg>
    </div>
</template>

<style scoped>
    .o-chart {
        font-family: var(--font-sans);
        min-width: 0;
        width: 100%;
    }

    .o-chart svg {
        display: block;
        overflow: visible;
    }

    .o-chart__axis {
        fill: var(--color-text-tertiary);
        font-size: var(--text-caption);
        font-variant-numeric: tabular-nums;
    }

    .o-chart__band-label {
        fill: var(--color-danger-text);
        font-size: var(--text-caption);
        font-weight: var(--font-weight-medium);
    }

    .o-chart__marker {
        fill: var(--color-text-secondary);
        font-size: var(--text-caption);
    }

    .o-chart__marker-name {
        fill: var(--color-text);
        font-weight: var(--font-weight-semibold);
    }

    .o-chart__annotation {
        fill: var(--color-text);
        font-size: var(--text-caption);
        font-weight: var(--font-weight-medium);
    }

    .o-chart__gap-label {
        fill: var(--color-text-tertiary);
        font-size: var(--text-caption);
    }
</style>
