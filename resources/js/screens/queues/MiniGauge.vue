<script>
    import {arcPath, gaugeFraction, tickPath} from '../../support/gauge';

    /** The geometry of the design's table gauge (40 × 23, radius 16). */
    const CX = 20;
    const CY = 20;
    const R = 16;

    /**
     * The small wait gauge of a table row: the same scale and zones as the
     * large WaitGauge (0 to 4× the limit, the limit at a quarter), drawn as a
     * track, the value arc and the limit tick. Without a limit it has no tick
     * and no value; a paused queue has a dashed track.
     */
    export default {
        props: {
            oldestAge: {type: Number, default: null},
            threshold: {type: Number, default: 0},
            overLimit: {type: Boolean, default: false},
            paused: {type: Boolean, default: false},
        },

        computed: {
            track() {
                return arcPath(CX, CY, R, 0, 1);
            },

            fraction() {
                return this.paused ? null : gaugeFraction(this.oldestAge, this.threshold);
            },

            value() {
                return this.fraction !== null && this.fraction > 0.001 ? arcPath(CX, CY, R, 0, this.fraction) : null;
            },

            tick() {
                return this.threshold > 0 && !this.paused ? tickPath(CX, CY, R, 0.25, 4) : null;
            },
        },
    };
</script>

<template>
    <svg width="40" height="23" viewBox="0 0 40 23" xmlns="http://www.w3.org/2000/svg" class="o-mini-gauge" aria-hidden="true">
        <path :d="track" fill="none" stroke="var(--color-separator)" stroke-width="4" stroke-linecap="round"
              :stroke-dasharray="paused ? '1.5 4' : null" />
        <path v-if="value" :d="value" fill="none" :stroke="overLimit ? 'var(--color-warning)' : 'var(--color-accent)'" stroke-width="4"
              stroke-linecap="round" data-gauge-value />
        <path v-if="tick" :d="tick" fill="none" stroke="var(--color-text)" stroke-width="1.2" stroke-linecap="round" />
    </svg>
</template>

<style scoped>
    .o-mini-gauge {
        display: block;
        flex-shrink: 0;
        overflow: visible;
    }
</style>
