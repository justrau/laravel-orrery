<script>
    import Icon from '../components/ui/Icon.vue';
    import {filtersFromQuery} from '../support/lists.js';
    import {pauseLabel, pauseNotes} from '../support/pause.js';
    import {formatCompactCount, formatCount, formatDuration, formatDurationShort} from '../support/time.js';
    import {queuePath} from '../support/redirects.js';
    import {attentionBadge, attentionTitle, batchesCount, degradationTitle, jobNavItems} from './data.js';

    /**
     * The sidebar of the shell:
     * the static app identity, the navigation
     * with counts and badges, and the watcher health footer. It shows the
     * data that AppShell polls; it fetches nothing itself. A count that is
     * null is unknown and is not shown.
     *
     * - Overview and "Failure groups" (the /failed screen, with the failures
     *   of the past hour as its badge);
     * - Jobs: Pending, Running, Completed, Failed and Stuck, each a tab of the
     *   Jobs list with that tab's own count (shell/data.js jobNavItems());
     * - the database queues, then Batches, Tags, Job classes and Workers.
     *
     * Below 1024 px AppShell shows it as a drawer (`drawer`): it emits
     * `navigate` when a link is followed, so the drawer closes.
     */
    export default {
        components: {Icon},

        props: {
            appName: {type: String, default: null},
            environment: {type: String, default: null},
            /** attentionCount() ({ count, atLeast, possible, unknown }), or null before the workload loaded. */
            attention: {type: Object, default: null},
            counts: {type: Object, default: () => ({})},
            queues: {type: Array, default: () => []},
            /** The workload never loaded: the queue list is unknown, not empty. */
            queuesUnknown: {type: Boolean, default: false},
            health: {type: Object, default: null},
            /** The sidebar is a drawer (below 1024 px): it carries the dialog role while open. */
            drawer: {type: Boolean, default: false},
        },

        emits: ['navigate'],

        computed: {
            name() {
                return this.appName || 'Orrery';
            },

            /** Pending, Running, Completed, Failed and Stuck: each item's count is that of the tab it opens. */
            jobItems() {
                return jobNavItems(this.counts).map(item => ({...item, match: route => this.jobsTab(route) === item.status}));
            },

            failureGroupsActive() {
                return this.$route.path === '/failed' || this.$route.path.startsWith('/failed/');
            },

            monitoringItems() {
                const batches = this.counts.batches;
                const tags = this.counts.tags;

                return [
                    {key: 'batches', label: 'Batches', icon: 'batches', to: '/batches', match: route => route.path.startsWith('/batches'),
                        count: batchesCount(batches, this.counts.batchesFailed).text, title: batchesCount(batches, this.counts.batchesFailed).title},
                    // Job classes replaces Metrics; /metrics redirects here.
                    {key: 'classes', label: 'Job classes', icon: 'metrics', to: '/classes', match: route => route.path === '/classes'},
                    {key: 'tags', label: 'Tags', icon: 'tags', to: '/tags', match: route => route.path === '/tags' || route.path.startsWith('/tags/'),
                        count: typeof tags === 'number' ? (tags > 0 ? `${formatCount(tags)} watched` : null) : (this.counts.tagsKnown ? '—' : null),
                        title: typeof tags === 'number' ? null : 'Monitored tags unknown'},
                    {key: 'workers', label: 'Workers', icon: 'workers', to: '/workers', match: route => route.path === '/workers',
                        count: typeof this.counts.workers === 'number' ? formatCount(this.counts.workers) : null},
                ];
            },

            attentionBadge() {
                return attentionBadge(this.attention);
            },

            attentionTitle() {
                return attentionTitle(this.attention);
            },

            /** The failures of the past hour (AppShell `failuresHour`) as the badge of "Failure groups", or null for none. */
            groupsBadge() {
                const hour = this.counts.failuresHour;

                if (!hour || typeof hour.count !== 'number' || hour.count === 0) return null;

                return `${formatCompactCount(hour.count, {down: hour.atLeast === true})}${hour.atLeast ? '+' : ''}`;
            },

            groupsTitle() {
                const hour = this.counts.failuresHour;

                if (!hour || typeof hour.count !== 'number') return 'Failures of the past hour: unknown';

                return `${hour.atLeast ? 'At least ' : ''}${formatCount(hour.count)} ${hour.count === 1 ? 'failure' : 'failures'} in the past hour`;
            },

            footerDetail() {
                if (!this.health) return null;

                const parts = [];

                if (this.health.tickAge !== null && this.health.tickAge !== undefined) {
                    parts.push(`Tick ${formatDuration(this.health.tickAge)} ago`);
                }

                if (this.health.heartbeatAge !== null && this.health.heartbeatAge !== undefined) {
                    parts.push(`heartbeat ${formatDuration(this.health.heartbeatAge)}`);
                }

                const retention = this.health.retention;

                if (retention && retention.state === 'behind' && retention.lagSeconds !== null) {
                    parts.push(`retention ${formatDuration(retention.lagSeconds)} behind`);
                } else if (retention && retention.state === 'unknown' && this.health.title !== 'Retention state unknown') {
                    parts.push('retention unknown');
                }

                return parts.join(' · ') || null;
            },
        },

        methods: {
            degradationTitle,

            /** The tab of the Jobs list that a route shows, or null on another screen. */
            jobsTab(route) {
                return route.path === '/jobs' ? filtersFromQuery(route.query).status : null;
            },

            isActive(item) {
                return item.match(this.$route);
            },

            queueActive(queue) {
                return this.$route.name === 'queue'
                    && this.$route.params.connection === queue.connection
                    && this.$route.params.queue === queue.queue;
            },

            queueTo(queue) {
                return queuePath(queue.connection, queue.queue);
            },

            queueTitle(queue) {
                const name = `${queue.connection}:${queue.queue}`;

                if (queue.state === 'paused') {
                    return [`${name}: ${pauseLabel(queue.pause)}`, ...pauseNotes(queue.pause)].join(' · ');
                }

                if (queue.backlogUnknown) return `${name}: the backlog could not be read`;

                // Not known off: a possible pause or an unknown part, each named (never "running").
                const unknown = queue.pause.knownOff ? '' : [pauseLabel(queue.pause), ...pauseNotes(queue.pause)].filter(Boolean).map(note => ` · ${note}`).join('');

                if (queue.state === 'over') {
                    return `${name} has waited ${formatDuration(queue.oldestAge)}, over its limit${unknown}`;
                }

                if (queue.state === 'unknown') {
                    return `${name}: ${queue.ready === null ? 'unknown' : formatCount(queue.ready)} ready${unknown}`;
                }

                return `${name}: ${queue.ready === null ? 'unknown' : formatCount(queue.ready)} ready`;
            },

            shortDuration(seconds) {
                return formatDurationShort(seconds);
            },

            formatCountValue(value) {
                return formatCount(value);
            },

            /** A followed link closes the drawer (AppShell listens). */
            onNavClick(event) {
                if (event.target && typeof event.target.closest === 'function' && event.target.closest('a')) this.$emit('navigate');
            },
        },
    };
</script>

<template>
    <nav class="o-sidebar" :class="{'o-sidebar--drawer': drawer}" aria-label="Orrery" @click="onNavClick">
        <div class="o-sidebar__identity">
            <div class="o-sidebar__logo" aria-hidden="true">
                <svg width="20" height="20" viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg">
                    <circle cx="10" cy="10" r="2.8" fill="#FFFFFF" />
                    <ellipse cx="10" cy="10" rx="8" ry="3.6" transform="rotate(-25 10 10)" fill="none" stroke="#8FC3FF" stroke-width="1.1" />
                    <circle cx="16.8" cy="6.9" r="1.4" fill="#0A84FF" />
                </svg>
            </div>
            <div class="o-sidebar__app">
                <div class="o-sidebar__app-name" :title="name">{{ name }}</div>
                <div v-if="environment" class="o-sidebar__env">{{ environment }}</div>
            </div>
        </div>

        <div class="o-sidebar__nav">
            <router-link to="/overview" class="o-nav-item" :class="{'o-nav-item--active': $route.name === 'overview'}">
                <icon name="overview" class="o-nav-item__icon" />
                <span class="o-nav-item__label">Overview</span>
                <span v-if="attentionBadge" class="o-pill o-pill--warning" :title="attentionTitle">{{ attentionBadge }}</span>
            </router-link>
            <router-link to="/failed" class="o-nav-item" :class="{'o-nav-item--active': failureGroupsActive}" :title="groupsTitle" data-nav="failure-groups">
                <icon name="failed" class="o-nav-item__icon" />
                <span class="o-nav-item__label">Failure groups</span>
                <span v-if="groupsBadge" class="o-pill o-pill--danger">{{ groupsBadge }}</span>
                <span v-else-if="counts.failuresHour === null" class="o-nav-item__count">—</span>
            </router-link>

            <div class="o-nav-section">Jobs</div>
            <router-link v-for="item in jobItems" :key="item.key" :to="item.to" class="o-nav-item"
                         :class="{'o-nav-item--active': isActive(item)}" :title="item.title" :data-nav="item.key">
                <icon :name="item.icon" class="o-nav-item__icon" />
                <span class="o-nav-item__label">{{ item.label }}</span>
                <span v-if="item.count" class="o-nav-item__count">{{ item.count }}</span>
            </router-link>

            <div class="o-nav-section o-nav-section--split">
                <router-link to="/queues" class="o-nav-section__link" :class="{'o-nav-section__link--active': $route.name === 'queues'}">Queues · database</router-link>
                <span class="o-nav-section__aside">ready</span>
            </div>
            <router-link v-for="queue in queues" :key="queue.key" :to="queueTo(queue)" class="o-nav-item"
                         :class="{'o-nav-item--active': queueActive(queue), 'o-nav-item--paused': queue.state === 'paused'}" :title="queueTitle(queue)">
                <icon name="queue" class="o-nav-item__icon" />
                <span class="o-nav-item__label">{{ queue.label }}</span>
                <span v-if="queue.state === 'paused'" class="o-nav-item__state">
                    <icon name="paused-small" :size="12" />
                    <span>Paused</span>
                </span>
                <span v-else-if="queue.state === 'over'" class="o-pill o-pill--warning o-pill--icon">
                    <icon name="warning-small" :size="10" class="o-pill__icon" />
                    <span>{{ shortDuration(queue.oldestAge) }}{{ queue.pause.knownOff ? '' : '?' }}</span>
                </span>
                <span v-else-if="queue.backlogUnknown" class="o-nav-item__count">unknown</span>
                <span v-else-if="queue.state === 'unknown'" class="o-nav-item__count">{{ queue.ready === null ? '—' : formatCountValue(queue.ready) }} ?</span>
                <span v-else class="o-nav-item__count">{{ queue.ready === null ? '—' : formatCountValue(queue.ready) }}</span>
            </router-link>
            <div v-if="!queues.length" class="o-nav-empty">{{ queuesUnknown ? 'Queues unknown' : 'No database queues' }}</div>

            <div class="o-nav-section">Monitoring</div>
            <router-link v-for="item in monitoringItems" :key="item.key" :to="item.to" class="o-nav-item"
                         :class="{'o-nav-item--active': isActive(item)}" :title="item.title || null">
                <icon :name="item.icon" class="o-nav-item__icon" />
                <span class="o-nav-item__label">{{ item.label }}</span>
                <span v-if="item.count" class="o-nav-item__count">{{ item.count }}</span>
            </router-link>
        </div>

        <div class="o-sidebar__spacer"></div>

        <div class="o-sidebar__footer" :title="degradationTitle(health)">
            <div class="o-sidebar__health">
                <span class="o-dot" :class="`o-dot--${health ? health.tone : 'unknown'}`"></span>
                <span class="o-sidebar__health-title">{{ health ? health.title : 'Watcher health unknown' }}</span>
            </div>
            <div v-if="footerDetail" class="o-sidebar__health-detail">{{ footerDetail }}</div>
            <div v-if="health && health.rollbackNote" class="o-sidebar__health-detail">{{ health.rollbackNote }}</div>
            <div v-if="health && health.unprovableNote" class="o-sidebar__health-detail" data-unprovable-note>{{ health.unprovableNote }}</div>
            <div v-for="note in (health && health.configNotes) || []" :key="note" class="o-sidebar__health-detail" data-config-note>{{ note }}</div>
        </div>
    </nav>
</template>

<style scoped>
    .o-sidebar {
        background-color: var(--color-sidebar);
        border-right: 1px solid var(--color-separator);
        box-sizing: border-box;
        display: flex;
        flex-direction: column;
        flex-shrink: 0;
        font-family: var(--font-sans);
        height: 100vh;
        overflow-y: auto;
        overflow-wrap: anywhere;
        padding-inline: 10px;
        position: sticky;
        top: 0;
        width: var(--container-sidebar);
        -webkit-font-smoothing: antialiased;
        -moz-osx-font-smoothing: grayscale;
    }

    .o-sidebar__identity {
        align-items: center;
        display: flex;
        gap: 10px;
        padding: 18px 8px 14px;
    }

    .o-sidebar__logo {
        align-items: center;
        background-image: var(--gradient-logo);
        border-radius: 8px;
        display: flex;
        flex-shrink: 0;
        height: 32px;
        justify-content: center;
        width: 32px;
    }

    .o-sidebar__app {
        display: flex;
        flex: 1 1 0%;
        flex-direction: column;
        gap: 3px;
        min-width: 0;
    }

    .o-sidebar__app-name {
        color: var(--color-text);
        font-size: var(--text-body);
        font-weight: var(--font-weight-semibold);
        line-height: 18px;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .o-sidebar__env {
        align-self: flex-start;
        background-color: var(--color-separator);
        border-radius: 4px;
        color: var(--color-text-secondary);
        font-size: var(--text-caption);
        font-weight: var(--font-weight-medium);
        line-height: 18px;
        padding-inline: 6px;
    }

    .o-sidebar__nav {
        display: flex;
        flex-direction: column;
        gap: 1px;
    }

    .o-nav-item {
        align-items: center;
        border-radius: var(--radius-control);
        color: var(--color-text);
        display: flex;
        flex-shrink: 0;
        font-size: var(--text-body);
        gap: 9px;
        height: 30px;
        line-height: 18px;
        padding-inline: 8px;
        text-decoration: none;
    }

    .o-nav-item:hover {
        background-color: color-mix(in srgb, var(--color-separator) 60%, transparent);
        color: var(--color-text);
        text-decoration: none;
    }

    .o-nav-item--active,
    .o-nav-item--active:hover {
        background-color: var(--color-separator);
        font-weight: var(--font-weight-semibold);
    }

    .o-nav-item:focus-visible {
        outline: 2px solid color-mix(in srgb, var(--color-accent) 55%, transparent);
        outline-offset: -2px;
    }

    .o-nav-item__icon {
        color: var(--color-accent);
    }

    .o-nav-item--paused .o-nav-item__icon {
        color: var(--color-paused);
    }

    .o-nav-item--paused .o-nav-item__label {
        color: var(--color-text-secondary);
    }

    .o-nav-item__label {
        flex: 1 1 0%;
        min-width: 0;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .o-nav-item__count {
        color: var(--color-text-secondary);
        font-size: var(--text-table);
        font-variant-numeric: tabular-nums;
        font-weight: var(--font-weight-regular);
        white-space: nowrap;
    }

    .o-nav-item__state {
        align-items: center;
        color: var(--color-text-secondary);
        display: flex;
        font-size: var(--text-table);
        font-weight: var(--font-weight-regular);
        gap: 4px;
    }

    .o-nav-item__state .o-icon {
        color: var(--color-paused);
    }

    .o-pill {
        align-items: center;
        border-radius: 9px;
        display: flex;
        font-size: var(--text-caption);
        font-weight: var(--font-weight-semibold);
        gap: 4px;
        height: 18px;
        justify-content: center;
        line-height: 16px;
        min-width: 20px;
        padding-inline: 6px;
        white-space: nowrap;
    }

    .o-pill--warning {
        background-color: var(--color-warning-tint);
        color: var(--color-warning-text);
    }

    .o-pill--icon {
        padding-left: 6px;
        padding-right: 7px;
    }

    .o-pill__icon {
        color: var(--color-warning);
    }

    .o-pill--danger {
        background-color: var(--color-danger);
        color: var(--color-on-accent);
        padding-inline: 7px;
    }

    .o-nav-section {
        color: var(--color-text-tertiary);
        font-size: var(--text-caption);
        font-weight: var(--font-weight-semibold);
        line-height: 16px;
        padding: 14px 8px 4px;
    }

    .o-nav-section--split {
        align-items: baseline;
        display: flex;
        justify-content: space-between;
    }

    .o-nav-section__link {
        color: inherit;
        text-decoration: none;
    }

    .o-nav-section__link:hover,
    .o-nav-section__link--active {
        color: var(--color-text-secondary);
        text-decoration: none;
    }

    .o-nav-section__aside {
        font-weight: var(--font-weight-regular);
    }

    .o-nav-empty {
        color: var(--color-text-tertiary);
        font-size: var(--text-table);
        line-height: 18px;
        padding: 6px 8px;
    }

    .o-sidebar__spacer {
        flex: 1 1 0%;
        min-height: 16px;
    }

    .o-sidebar__footer {
        border-top: 1px solid var(--color-separator);
        display: flex;
        flex-direction: column;
        gap: 2px;
        padding: 12px 8px 16px;
    }

    .o-sidebar__health {
        align-items: center;
        display: flex;
        gap: 8px;
    }

    .o-sidebar__health-title {
        color: var(--color-text);
        font-size: var(--text-table);
        font-weight: var(--font-weight-semibold);
        line-height: 18px;
    }

    .o-sidebar__health-detail {
        color: var(--color-text-secondary);
        font-size: var(--text-caption);
        line-height: 16px;
        padding-left: 16px;
    }

    .o-dot {
        border-radius: 50%;
        flex-shrink: 0;
        height: 8px;
        width: 8px;
    }

    .o-dot--healthy { background-color: var(--color-success); }
    .o-dot--warning { background-color: var(--color-warning); }
    .o-dot--unknown { background-color: var(--color-text-tertiary); }
</style>
