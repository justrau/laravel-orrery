<script>
    import Button from '../../components/ui/Button.vue';
    import Icon from '../../components/ui/Icon.vue';
    import WaitGauge from '../../components/ui/WaitGauge.vue';
    import {gaugePause, queueContinueApplies} from '../../support/pause.js';
    import {countOf, limitFooter, num, queueState} from './overview';

    /**
     * One queue card of the Overview:
     * the name and the state pill, the wait gauge, ready, running and
     * workers, Pause or Continue, and the limit footer. A queue whose pause
     * state is unknown offers both Pause and Continue, because neither state
     * is proved.
     *
     * A queue pause is Laravel's own: Continue lifts it, also
     * one from `queue:pause`, but never a broader pause (its sheet says so).
     * Laravel's global pause shows the command that lifts it. An unknown
     * backlog shows no gauge: the gauge would say "nothing waiting".
     */
    export default {
        components: {Icon, WaitGauge, 'o-button': Button},

        props: {
            row: {type: Object, required: true},
            label: {type: String, required: true},
            now: {type: Number, default: null},
            busy: {type: Boolean, default: false},
        },

        emits: ['control'],

        computed: {
            state() {
                return queueState(this.row);
            },

            footer() {
                return limitFooter(this.row, this.now ?? undefined);
            },

            oldestAge() {
                return num(this.row.oldest_age);
            },

            /** The pause that the gauge draws (a cause from the command line has no actor or time). */
            gaugePaused() {
                return gaugePause(this.state.pause);
            },

            /** A queue Continue: while a queue cause holds (also a possible one), or while the pause state is unknown. */
            showContinue() {
                return queueContinueApplies(this.state.pause);
            },

            /** Orrery's Pause: not while a pause holds. */
            showPause() {
                return !this.state.pause.paused;
            },

            threshold() {
                return num(this.row.threshold) || 0;
            },

            stats() {
                return [
                    {key: 'ready', value: countOf(this.row.length), label: 'ready'},
                    {key: 'running', value: countOf(this.row.running), label: 'running'},
                    {key: 'workers', value: countOf(this.row.processes), label: 'workers'},
                ];
            },
        },
    };
</script>

<template>
    <div class="o-qcard" :class="{'o-qcard--over': state.pill.key === 'over', 'o-qcard--paused': state.pill.key === 'paused'}"
         data-queue-card :data-queue="`${row.connection}:${row.name}`">
        <div class="o-qcard__head">
            <div class="o-qcard__name">{{ label }}</div>
            <div class="o-pill" :class="`o-pill--${state.pill.key}`" :title="state.pill.label" data-pill>
                <span v-if="state.pill.key === 'running'" class="o-pill__dot"></span>
                <icon v-else-if="state.pill.key === 'over'" name="warning-small" :size="10" />
                <icon v-else-if="state.pill.key === 'paused'" name="paused-small" :size="12" />
                <span class="o-pill__text">{{ state.pill.label }}</span>
            </div>
        </div>

        <div class="o-qcard__gauge">
            <div v-if="state.backlogUnknown" class="o-qcard__unknown" data-backlog-unknown>
                <div class="o-qcard__unknown-title">—</div>
                <div class="o-qcard__unknown-text">The backlog could not be read</div>
            </div>
            <wait-gauge v-else :oldest-age="oldestAge" :threshold="threshold" :over-limit="state.overLimit"
                        :paused="gaugePaused" :now="now" />
        </div>

        <div v-if="state.hint" class="o-qcard__hint" data-native-hint>{{ state.hint }}</div>

        <div class="o-qcard__stats">
            <div v-for="stat in stats" :key="stat.key" class="o-qcard__stat" :data-stat="stat.key">
                <div class="o-qcard__value">{{ stat.value }}</div>
                <div class="o-qcard__label">{{ stat.label }}</div>
            </div>
        </div>

        <div class="o-qcard__foot">
            <o-button v-if="showContinue" variant="write" size="sm" icon="play" accent operate
                      :busy="busy" data-continue @click="$emit('control', 'continue')">Continue</o-button>
            <o-button v-if="showPause" variant="write" size="sm" icon="pause" operate
                      :busy="busy" data-pause @click="$emit('control', 'pause')">Pause</o-button>
            <div class="o-qcard__footer" :class="`o-qcard__footer--${footer.tone}`" data-footer>{{ footer.text }}</div>
        </div>
    </div>
</template>

<style scoped>
    .o-qcard {
        background-color: var(--color-bg);
        border: 1px solid var(--color-separator);
        border-radius: var(--radius-card);
        box-sizing: border-box;
        display: flex;
        flex: 1 1 0%;
        flex-direction: column;
        gap: 10px;
        min-width: 0;
        padding: 14px 16px;
    }

    .o-qcard--over {
        border-color: color-mix(in srgb, var(--color-warning) 65%, transparent);
        box-shadow: color-mix(in srgb, var(--color-warning) 12%, transparent) 0 0 0 3px;
    }

    .o-qcard--paused {
        background-color: var(--color-subtle);
    }

    .o-qcard__head {
        align-items: center;
        display: flex;
        flex-shrink: 0;
        gap: 8px;
        height: 22px;
    }

    .o-qcard__name {
        color: var(--color-text);
        flex: 1 1 0%;
        font-size: var(--text-body);
        font-weight: var(--font-weight-semibold);
        line-height: 20px;
        min-width: 56px;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .o-qcard--paused .o-qcard__name {
        color: var(--color-text-secondary);
    }

    .o-pill {
        align-items: center;
        border-radius: 11px;
        display: flex;
        flex-shrink: 1;
        font-size: var(--text-caption);
        font-weight: var(--font-weight-semibold);
        gap: 4px;
        height: 22px;
        line-height: 16px;
        padding-inline: 7px 8px;
        white-space: nowrap;
    }

    .o-pill {
        min-width: 0;
    }

    .o-pill__text {
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .o-pill .o-icon,
    .o-pill__dot {
        flex-shrink: 0;
    }

    .o-pill--running {
        color: var(--color-text-secondary);
        font-weight: var(--font-weight-regular, 400);
        padding-inline: 0;
    }

    .o-pill__dot {
        background-color: var(--color-success);
        border-radius: 50%;
        height: 7px;
        width: 7px;
    }

    .o-pill--over {
        background-color: var(--color-warning-tint);
        color: var(--color-warning-text);
    }

    .o-pill--over .o-icon { color: var(--color-warning); }

    .o-pill--paused,
    .o-pill--unknown,
    .o-pill--idle {
        background-color: var(--color-separator);
        color: var(--color-text-secondary);
        padding-left: 6px;
    }

    .o-pill--unknown,
    .o-pill--idle {
        padding-left: 8px;
    }

    .o-qcard__gauge {
        display: flex;
        justify-content: center;
    }

    .o-qcard__unknown {
        align-items: center;
        display: flex;
        flex-direction: column;
        height: 90px;
        justify-content: center;
        width: 176px;
    }

    .o-qcard__unknown-title {
        color: var(--color-text);
        font-size: var(--text-section);
        font-weight: var(--font-weight-semibold);
        line-height: 22px;
    }

    .o-qcard__unknown-text,
    .o-qcard__hint {
        color: var(--color-text-secondary);
        font-size: var(--text-caption);
        line-height: 16px;
    }

    /* The hint is prose with an occasional command, so it keeps the body font. */
    .o-qcard__hint {
        overflow-wrap: anywhere;
    }

    .o-qcard__stats {
        display: flex;
        padding-top: 2px;
    }

    .o-qcard__stat {
        display: flex;
        flex: 1 1 0%;
        flex-direction: column;
        min-width: 0;
    }

    .o-qcard__value {
        color: var(--color-text);
        font-size: var(--text-body);
        font-variant-numeric: tabular-nums;
        font-weight: var(--font-weight-semibold);
        line-height: 20px;
    }

    .o-qcard__label {
        color: var(--color-text-secondary);
        font-size: var(--text-caption);
        line-height: 16px;
    }

    .o-qcard__foot {
        align-items: center;
        border-top: 1px solid var(--color-separator);
        display: flex;
        flex-wrap: wrap;
        gap: 6px 8px;
        margin-top: auto;
        padding-top: 12px;
    }

    .o-qcard__footer {
        color: var(--color-text-secondary);
        flex: 1 0 auto;
        white-space: nowrap;
        font-size: var(--text-table);
        line-height: 18px;
        min-width: 0;
        text-align: right;
    }

    .o-qcard__footer--over {
        color: var(--color-warning-text);
        font-weight: var(--font-weight-medium);
    }
</style>
