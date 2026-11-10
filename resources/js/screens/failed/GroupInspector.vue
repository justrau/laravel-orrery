<script>
    import BarChart from '../../components/ui/BarChart.vue';
    import Button from '../../components/ui/Button.vue';
    import {formatHourMinuteZoned, formatLocal, relativeTime} from '../../support/time';
    import {
        attemptsText,
        countText,
        groupCountAtLeast,
        groupGoneTitle,
        groupQueuesText,
        groupStartUncertain,
        markerBefore,
        markerName,
        operationLabel,
        peakOf,
        peakText,
        perUnit,
        queueName,
        seenTimeText,
        shortName,
        timesText,
        traceLocation,
        traceText,
    } from './failures';

    /** The affected jobs that the inspector lists. */
    const AFFECTED_ROWS = 5;

    /**
     * The inspector of the selected failure group:
     * the header, the stored message, the facts, Retry and
     * Forget, the properties, the occurrences per bucket, the affected jobs
     * and the stack trace of the latest member. It shows the detail read of
     * the live window (`detail`); `group: null` means that the read found no
     * member in the window: certainly gone only when the read was complete
     * (`complete`, groupGoneTitle()). A capped count is "at least", and a
     * start from a truncated fallback scan "or earlier".
     * Every text is rendered as text.
     */
    export default {
        components: {BarChart, 'o-button': Button},

        props: {
            groupKey: {type: String, required: true},
            detail: {type: Object, default: null},
            loading: {type: Boolean, default: false},
            error: {type: String, default: null},
            markers: {type: Array, default: () => []},
            window: {type: Object, required: true},
            rangeWords: {type: String, default: 'past hour'},
            now: {type: Number, required: true},
            /** The Jobs list of the group's failed jobs (failures.js failedJobsLink()). */
            jobsLink: {type: Object, default: () => ({path: '/jobs', query: {status: 'failed'}})},
        },

        emits: ['retry', 'forget'],

        data() {
            return {traceLabel: null};
        },

        watch: {
            // The trace location is parsed only when the trace text changes, not on every poll.
            'detail.latest_trace': {
                immediate: true,
                handler(trace) {
                    this.traceLabel = traceLocation(trace);
                },
            },
        },

        computed: {
            group() {
                return this.detail ? this.detail.group : null;
            },

            /** The trace, or a sentence when the server left it out as too large. */
            trace() {
                return this.detail ? traceText(this.detail.latest_trace) : null;
            },

            /** The group's count is a lower bound: capped, or with rows of a truncated fallback scan. */
            truncated() {
                return !!this.detail && (this.detail.count_capped === true || groupCountAtLeast(this.group));
            },

            goneTitle() {
                return groupGoneTitle(this.detail);
            },

            /** "or earlier" after the first failure when the start may be earlier. */
            startSuffix() {
                return groupStartUncertain(this.group) ? ' or earlier' : '';
            },

            count() {
                return this.detail && typeof this.detail.total === 'number' ? this.detail.total : (this.group ? this.group.count : 0);
            },

            jobs() {
                return this.detail && Array.isArray(this.detail.jobs) ? this.detail.jobs.slice(0, AFFECTED_ROWS) : [];
            },

            bucketSeconds() {
                return this.detail && this.detail.bucket_seconds > 0 ? this.detail.bucket_seconds : 60;
            },

            series() {
                const retentionFrom = this.detail && typeof this.detail.retention_from === 'number' ? this.detail.retention_from : null;

                return [{key: 'count', label: 'failures', color: 'var(--color-danger)', retentionFrom}];
            },

            buckets() {
                return this.group && Array.isArray(this.group.buckets) ? this.group.buckets : [];
            },

            peak() {
                return peakOf(this.buckets, 'count');
            },

            annotations() {
                return this.peak
                    ? [{at: this.peak.at, text: `Peak ${this.peak.value.toLocaleString()}${perUnit(this.bucketSeconds)}`, target: 'main'}]
                    : [];
            },

            chartMarkers() {
                return this.markers.map(marker => ({at: marker.at, kind: marker.kind, label: marker.label}));
            },

            firstMarker() {
                return this.group ? markerBefore(this.markers, this.group.first_failed_at, this.window.from) : null;
            },

            /** The whole group, as the bulk run freezes it: "Retry 37…", or "Retry ≥ 5,000…" (operationLabel()). */
            retryLabel() {
                return `${operationLabel('Retry', this.count, this.truncated)}…`;
            },

            forgetLabel() {
                return `${operationLabel('Forget', this.count, this.truncated)}…`;
            },
        },

        methods: {
            shortName,
            queueName,
            countText,
            timesText,
            attemptsText,
            groupQueuesText,
            markerName,
            peakText,
            formatHourMinuteZoned,
            formatLocal,
            relativeTime,

            /** "09:14 GMT+3" within the last 24 h, else the date, the time and the zone (seenTimeText()). */
            timeOf(epoch) {
                return seenTimeText(epoch, this.now);
            },

            jobLabel(job) {
                return Array.isArray(job.tags) && job.tags.length ? job.tags[0] : job.uuid.slice(0, 8);
            },
        },
    };
</script>

<template>
    <div class="o-inspector" data-inspector :data-key="groupKey">
        <div v-if="error" class="o-inspector__muted" data-inspector-error>
            {{ detail ? 'The group could not be refreshed' : 'The group could not be loaded' }} ({{ error }}).
        </div>

        <div v-if="loading && !detail" class="o-inspector__muted" data-inspector-loading>Loading…</div>

        <template v-else-if="detail && !group">
            <div class="o-inspector__eyebrow">Failure group</div>
            <div class="o-inspector__gone" data-group-gone>
                <div class="o-inspector__gone-title" data-group-gone-title>{{ goneTitle }}</div>
                <div class="o-inspector__muted">
                    {{ detail.complete === true
                        ? 'They may have been retried or forgotten, or they are older than the range.'
                        : 'Only part of the failures without a stored group key could be read, so the group may still have failed jobs in this range.' }}
                </div>
            </div>
        </template>

        <template v-else-if="group">
            <div class="o-inspector__header">
                <div class="o-inspector__eyebrow">Failure group</div>
                <div class="o-inspector__title" data-inspector-title>{{ shortName(group.exception_class) }} {{ timesText(count, truncated) }}</div>
                <div class="o-inspector__class">{{ group.exception_class || 'No exception class' }}</div>
            </div>

            <div class="o-inspector__message" data-inspector-message>{{ group.message || 'No message' }}</div>

            <div class="o-inspector__facts" data-inspector-facts>
                <div>{{ countText(count, truncated) }} {{ count === 1 ? 'job' : 'jobs' }} · {{ attemptsText(detail.attempts) }}</div>
                <div class="o-inspector__secondary">first in this range {{ timeOf(group.first_failed_at) }}{{ startSuffix }} · last {{ relativeTime(group.last_failed_at, now) }}</div>
            </div>

            <div v-if="!failedReadOnly" class="o-inspector__actions" data-inspector-actions>
                <o-button variant="primary" size="lg" icon="retry" block operate data-inspector-retry
                          @click="$emit('retry')">{{ retryLabel }}</o-button>
                <o-button variant="danger" size="lg" operate title="Forget deletes the failed jobs. This cannot be undone."
                          data-inspector-forget @click="$emit('forget')">{{ forgetLabel }}</o-button>
            </div>

            <div class="o-props">
                <div class="o-props__row">
                    <span class="o-props__label">Job</span>
                    <span class="o-props__value">{{ group.job || '—' }}</span>
                </div>
                <div class="o-props__row">
                    <span class="o-props__label">{{ (group.queues || []).length > 1 ? 'Queues' : 'Queue' }}</span>
                    <span class="o-props__value" :title="groupQueuesText(group)" data-inspector-queues>{{ groupQueuesText(group) }}</span>
                </div>
                <div class="o-props__row">
                    <span class="o-props__label" title="The first failure inside the selected range; the group may have failed before it">First in this range</span>
                    <span class="o-props__value o-props__value--row" :title="formatLocal(group.first_failed_at)">
                        {{ timeOf(group.first_failed_at) }}{{ startSuffix }}
                        <span v-if="firstMarker" class="o-marker-chip" data-marker-chip :title="formatLocal(firstMarker.at)">
                            <svg width="10" height="12" viewBox="0 0 10 12" aria-hidden="true"><path d="M5 1v10" stroke="var(--color-text-secondary)" stroke-width="1.3" stroke-linecap="round" stroke-dasharray="2 1.6" /></svg>
                            {{ markerName(firstMarker) }} {{ formatHourMinuteZoned(firstMarker.at) }}
                        </span>
                    </span>
                </div>
                <div class="o-props__row">
                    <span class="o-props__label">Last seen</span>
                    <span class="o-props__value" :title="formatLocal(group.last_failed_at)">{{ timeOf(group.last_failed_at) }}, {{ relativeTime(group.last_failed_at, now) }}</span>
                </div>
                <div class="o-props__row">
                    <span class="o-props__label">Attempts</span>
                    <span class="o-props__value">{{ attemptsText(detail.attempts, 'on every job') }}</span>
                </div>
            </div>

            <div class="o-section" data-occurrences>
                <div class="o-section__head">
                    <span class="o-section__title">Occurrences {{ bucketSeconds === 3600 ? 'per hour' : 'per minute' }}</span>
                    <span class="o-section__note">{{ rangeWords }}{{ peak ? ` · ${peakText(peak, bucketSeconds, truncated)}` : '' }}</span>
                </div>
                <bar-chart :from="window.from" :to="window.to" :bucket-seconds="bucketSeconds" :buckets="buckets" :series="series"
                           :markers="chartMarkers" :annotations="annotations" :y-ticks="2" unit="failures" />
            </div>

            <div class="o-section" data-affected>
                <div class="o-section__head">
                    <span class="o-section__title">Affected jobs</span>
                    <o-button variant="link" :to="jobsLink" data-show-all>Failed jobs of this class</o-button>
                </div>
                <router-link v-for="job in jobs" :key="job.uuid" class="o-affected" data-affected-row
                             :to="{name: 'job-detail', params: {uuid: job.uuid}}" :title="job.uuid">
                    <span class="o-affected__name">{{ jobLabel(job) }}</span>
                    <span class="o-affected__attempt">attempt {{ job.attempts }}</span>
                    <span class="o-affected__ago" :title="formatLocal(job.failed_at)">{{ relativeTime(job.failed_at, now) }}</span>
                </router-link>
            </div>

            <details v-if="trace" class="o-trace" data-trace>
                <summary class="o-trace__summary">
                    <span class="o-trace__title">Stack trace</span>
                    <span class="o-trace__location">{{ traceLabel }}</span>
                </summary>
                <pre class="o-trace__text" data-trace-text>{{ trace }}</pre>
            </details>
        </template>
    </div>
</template>

<style scoped>
    .o-inspector {
        display: flex;
        flex-direction: column;
        gap: 12px;
        overflow-wrap: anywhere;
    }

    .o-inspector__header {
        display: flex;
        flex-direction: column;
        gap: 3px;
    }

    .o-inspector__eyebrow {
        color: var(--color-text-secondary);
        font-size: var(--text-caption);
        font-weight: var(--font-weight-semibold);
        letter-spacing: var(--tracking-caps);
        line-height: 16px;
        text-transform: uppercase;
    }

    .o-inspector__title {
        color: var(--color-text);
        font-size: var(--text-section);
        font-weight: var(--font-weight-semibold);
        letter-spacing: -0.01em;
        line-height: 22px;
    }

    .o-inspector__class {
        color: var(--color-text-secondary);
        font-family: var(--font-mono);
        font-size: var(--text-caption);
        line-height: 16px;
        word-break: break-all;
    }

    .o-inspector__message {
        background-color: var(--color-danger-tint);
        border: 1px solid color-mix(in srgb, var(--color-danger) 20%, transparent);
        border-radius: 8px;
        color: var(--color-text);
        font-size: var(--text-body);
        line-height: 20px;
        padding: 10px 12px;
        white-space: pre-wrap;
    }

    .o-inspector__facts {
        color: var(--color-text);
        font-size: var(--text-table);
        line-height: 18px;
    }

    .o-inspector__secondary,
    .o-inspector__muted {
        color: var(--color-text-secondary);
        font-size: var(--text-table);
        line-height: 18px;
    }

    .o-inspector__gone {
        border: 1px dashed var(--color-separator);
        border-radius: 8px;
        display: flex;
        flex-direction: column;
        gap: 4px;
        padding: 12px;
    }

    .o-inspector__gone-title {
        color: var(--color-text);
        font-size: var(--text-table);
        font-weight: var(--font-weight-semibold);
        line-height: 18px;
    }

    .o-inspector__actions {
        align-items: center;
        display: flex;
        gap: 8px;
    }

    .o-props {
        border-top: 1px solid var(--color-separator);
        display: flex;
        flex-direction: column;
    }

    .o-props__row {
        align-items: center;
        border-bottom: 1px solid var(--color-separator);
        display: flex;
        min-height: 28px;
        padding-block: 4px;
    }

    .o-props__label {
        color: var(--color-text-secondary);
        flex-shrink: 0;
        font-size: var(--text-caption);
        line-height: 16px;
        width: 84px;
    }

    .o-props__value {
        color: var(--color-text);
        font-size: var(--text-table);
        line-height: 18px;
        min-width: 0;
    }

    .o-props__value--row {
        align-items: center;
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
    }

    .o-marker-chip {
        align-items: center;
        border: 1px solid var(--color-separator);
        border-radius: 5px;
        color: var(--color-text-secondary);
        display: inline-flex;
        font-size: var(--text-caption);
        font-weight: var(--font-weight-medium);
        gap: 5px;
        height: 20px;
        line-height: 16px;
        padding-inline: 7px;
    }

    .o-section {
        display: flex;
        flex-direction: column;
        gap: 6px;
    }

    .o-section__head {
        align-items: baseline;
        display: flex;
        justify-content: space-between;
    }

    .o-section__title {
        color: var(--color-text);
        font-size: var(--text-table);
        font-weight: var(--font-weight-semibold);
        line-height: 18px;
    }

    .o-section__note {
        color: var(--color-text-secondary);
        font-size: var(--text-caption);
        line-height: 16px;
    }

    .o-affected {
        align-items: center;
        border-bottom: 1px solid var(--color-separator);
        color: var(--color-text);
        display: flex;
        height: 28px;
        text-decoration: none;
    }

    .o-affected:hover {
        color: var(--color-text);
        text-decoration: none;
        background-color: var(--color-subtle);
    }

    .o-affected__name {
        flex-grow: 1;
        font-size: var(--text-table);
        line-height: 18px;
        min-width: 0;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .o-affected__attempt,
    .o-affected__ago {
        color: var(--color-text-secondary);
        flex-shrink: 0;
        font-size: var(--text-caption);
        line-height: 16px;
    }

    .o-affected__attempt { width: 82px; }
    .o-affected__ago { text-align: right; width: 70px; }

    .o-trace {
        background-color: var(--color-bg);
        border: 1px solid var(--color-separator);
        border-radius: var(--radius-control);
    }

    .o-trace__summary {
        align-items: center;
        cursor: pointer;
        display: flex;
        gap: 8px;
        height: 32px;
        padding-inline: 10px;
    }

    .o-trace__title {
        color: var(--color-text);
        flex-grow: 1;
        font-size: var(--text-table);
        font-weight: var(--font-weight-semibold);
        line-height: 18px;
    }

    .o-trace__location {
        color: var(--color-text-secondary);
        font-family: var(--font-mono);
        font-size: var(--text-caption);
        line-height: 16px;
    }

    .o-trace__text {
        border-top: 1px solid var(--color-separator);
        color: var(--color-text);
        font-family: var(--font-mono);
        font-size: 11px;
        line-height: 16px;
        margin: 0;
        max-height: 420px;
        overflow: auto;
        padding: 10px;
        white-space: pre;
    }
</style>
