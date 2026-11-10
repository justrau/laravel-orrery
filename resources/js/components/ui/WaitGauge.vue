<script>
    import {ZONES, arcPath, arcPoint, gaugeFraction, limitMultiple, tickPath} from '../../support/gauge';
    import {formatClockDuration, formatLimit, relativeTime} from '../../support/time';

    /** The two sizes of the design: the queue card and the inspector. */
    const SIZES = {
        card: {width: 176, height: 90, svgHeight: 86, cx: 88, cy: 82, r: 74, stroke: 7, knob: 5.5, tick: 7.5, svgLeft: 0},
        large: {width: 300, height: 148, svgHeight: 128, cx: 132, cy: 122, r: 110, stroke: 10, knob: 7.5, tick: 11, svgLeft: 18},
    };

    const ZONE_COLORS = {
        neutral: 'var(--color-separator)',
        warning: 'color-mix(in srgb, var(--color-warning) 30%, transparent)',
        danger: 'color-mix(in srgb, var(--color-danger) 26%, transparent)',
    };

    /**
     * The wait gauge of a queue: the oldest wait against the queue's wait
     * limit, on a half circle from 0 to 4× the limit.
     *
     * - `oldestAge`, `threshold` and `overLimit` are the workload row's
     *   `oldest_age`, `threshold` and `over_limit`. The color follows the
     *   server's `over_limit`, not a second computation.
     * - `threshold` 0 is "No limit": a gauge without zones and without a
     *   value arc.
     * - `paused` is the row's `paused` ({ scope, issued_at, issued_by } or
     *   null): a dashed track, and who paused the queue and when.
     */
    export default {
        props: {
            oldestAge: {type: Number, default: null},
            threshold: {type: Number, default: 0},
            overLimit: {type: Boolean, default: false},
            paused: {type: Object, default: null},
            size: {type: String, default: 'card', validator: value => Object.prototype.hasOwnProperty.call(SIZES, value)},
            now: {type: Number, default: null},
        },

        computed: {
            g() {
                return SIZES[this.size];
            },

            hasLimit() {
                return this.threshold > 0;
            },

            fraction() {
                return gaugeFraction(this.oldestAge, this.threshold);
            },

            zones() {
                return ZONES.map(([from, to, kind]) => ({
                    kind,
                    path: arcPath(this.g.cx, this.g.cy, this.g.r, from, to),
                    color: ZONE_COLORS[kind],
                    round: kind === 'danger',
                }));
            },

            fullTrack() {
                return arcPath(this.g.cx, this.g.cy, this.g.r, 0, 1);
            },

            limitTick() {
                return tickPath(this.g.cx, this.g.cy, this.g.r, 0.25, this.g.tick);
            },

            valueColor() {
                return this.overLimit ? 'var(--color-warning)' : 'var(--color-accent)';
            },

            valuePath() {
                return this.fraction !== null && this.fraction > 0.001
                    ? arcPath(this.g.cx, this.g.cy, this.g.r, 0, this.fraction)
                    : null;
            },

            knob() {
                return this.fraction === null ? null : arcPoint(this.g.cx, this.g.cy, this.g.r, this.fraction);
            },

            /** The oldest wait, also while the queue is paused (its jobs still wait); "—" when unknown or none. */
            valueText() {
                return this.oldestAge === null ? '—' : formatClockDuration(this.oldestAge);
            },

            caption() {
                if (this.oldestAge === null) {
                    return 'nothing waiting';
                }

                if (!this.hasLimit) {
                    return 'oldest wait · no limit';
                }

                if (this.size === 'large') {
                    return `oldest wait · ${limitMultiple(this.oldestAge, this.threshold)} its limit`;
                }

                return 'oldest wait';
            },

            pausedBy() {
                if (!this.paused) return null;

                return this.paused.issued_by ? `Paused by ${this.paused.issued_by}` : 'Paused';
            },

            pausedAgo() {
                return this.paused && typeof this.paused.issued_at === 'number'
                    ? relativeTime(this.paused.issued_at, this.now ?? undefined)
                    : null;
            },

            limitLabel() {
                return this.hasLimit ? formatLimit(this.threshold) : 'No limit';
            },

            scaleEnd() {
                return this.hasLimit ? formatLimit(this.threshold * 4) : null;
            },

            label() {
                if (this.paused) return this.oldestAge === null ? `Wait gauge: ${this.pausedBy}` : `Wait gauge: ${this.pausedBy}, oldest wait ${this.valueText}`;
                if (this.oldestAge === null) return 'Wait gauge: nothing waiting';

                return this.hasLimit
                    ? `Wait gauge: oldest wait ${this.valueText}, limit ${this.limitLabel}${this.overLimit ? ', over the limit' : ''}`
                    : `Wait gauge: oldest wait ${this.valueText}, no limit`;
            },
        },
    };
</script>

<template>
    <div class="o-gauge" :class="[`o-gauge--${size}`, {'o-gauge--paused': paused, 'o-gauge--over': overLimit && !paused}]"
         :style="{width: g.width + 'px', height: g.height + 'px'}" role="img" :aria-label="label">
        <svg :width="size === 'large' ? 264 : g.width" :height="g.svgHeight" :viewBox="`0 0 ${size === 'large' ? 264 : g.width} ${g.svgHeight}`"
             xmlns="http://www.w3.org/2000/svg" class="o-gauge__svg" :style="{left: g.svgLeft + 'px'}" aria-hidden="true">
            <template v-if="paused">
                <path :d="fullTrack" fill="none" stroke="var(--color-separator)" :stroke-width="g.stroke" stroke-linecap="round" stroke-dasharray="2 7" />
                <path v-if="hasLimit" :d="limitTick" fill="none" stroke="var(--color-text-tertiary)" stroke-width="1.5" stroke-linecap="round" />
            </template>
            <template v-else-if="!hasLimit">
                <path :d="fullTrack" fill="none" stroke="var(--color-separator)" :stroke-width="g.stroke" stroke-linecap="round" />
            </template>
            <template v-else>
                <path v-for="zone in zones" :key="zone.kind" :d="zone.path" fill="none" :stroke="zone.color" :stroke-width="g.stroke"
                      :stroke-linecap="zone.round ? 'round' : null" />
                <path v-if="valuePath" :d="valuePath" fill="none" :stroke="valueColor" :stroke-width="g.stroke" stroke-linecap="round" />
                <circle v-if="knob" :cx="knob.x.toFixed(2)" :cy="knob.y.toFixed(2)" :r="g.knob" class="o-gauge__knob" :stroke="valueColor" stroke-width="2" />
                <path :d="limitTick" fill="none" stroke="var(--color-text)" stroke-width="1.5" stroke-linecap="round" />
            </template>
        </svg>

        <div class="o-gauge__limit">{{ limitLabel }}</div>
        <template v-if="size === 'large' && hasLimit && !paused">
            <div class="o-gauge__zero">0</div>
            <div class="o-gauge__end">{{ scaleEnd }}</div>
        </template>

        <div class="o-gauge__value">
            <div class="o-gauge__number">{{ valueText }}</div>
            <template v-if="paused">
                <div class="o-gauge__caption">{{ pausedBy }}</div>
                <div v-if="pausedAgo" class="o-gauge__caption o-gauge__caption--tertiary">{{ pausedAgo }}</div>
            </template>
            <div v-else class="o-gauge__caption">{{ caption }}</div>
        </div>
    </div>
</template>

<style scoped>
    .o-gauge {
        box-sizing: border-box;
        flex-shrink: 0;
        font-family: var(--font-sans);
        position: relative;
    }

    .o-gauge__svg {
        overflow: visible;
        position: absolute;
        top: 0;
    }

    .o-gauge__knob {
        fill: var(--color-knob);
    }

    .o-gauge__limit {
        color: var(--color-text-secondary);
        font-size: var(--text-caption);
        left: 0;
        line-height: 14px;
        position: absolute;
        top: 4px;
        white-space: nowrap;
    }

    .o-gauge--large .o-gauge__limit {
        color: var(--color-text);
        font-weight: var(--font-weight-semibold);
        left: 14px;
        line-height: 16px;
        text-align: right;
        top: 20px;
        width: 44px;
    }

    .o-gauge__zero,
    .o-gauge__end {
        color: var(--color-text-tertiary);
        font-size: var(--text-caption);
        line-height: 16px;
        position: absolute;
        text-align: center;
        top: 130px;
    }

    .o-gauge__zero { left: 30px; width: 20px; }
    .o-gauge__end { left: 236px; width: 48px; }

    .o-gauge__value {
        align-items: center;
        bottom: 0;
        display: flex;
        flex-direction: column;
        left: 0;
        position: absolute;
        width: 100%;
    }

    .o-gauge--large .o-gauge__value {
        bottom: auto;
        top: 62px;
    }

    .o-gauge__number {
        color: var(--color-text);
        font-size: var(--text-stat);
        font-variant-numeric: tabular-nums;
        font-weight: var(--font-weight-semibold);
        letter-spacing: -0.02em;
        line-height: 32px;
    }

    .o-gauge--large .o-gauge__number {
        font-size: var(--text-hero);
        font-weight: var(--font-weight-bold);
        letter-spacing: var(--tracking-tight);
        line-height: 44px;
    }

    .o-gauge--over .o-gauge__number {
        color: var(--color-warning-text);
    }

    .o-gauge--paused .o-gauge__number,
    .o-gauge--paused .o-gauge__limit {
        color: var(--color-text-tertiary);
    }

    .o-gauge__caption {
        color: var(--color-text-secondary);
        font-size: var(--text-caption);
        line-height: 16px;
        white-space: nowrap;
    }

    .o-gauge--large .o-gauge__caption {
        font-size: var(--text-table);
        line-height: 18px;
    }

    .o-gauge__caption--tertiary {
        color: var(--color-text-tertiary);
    }
</style>
