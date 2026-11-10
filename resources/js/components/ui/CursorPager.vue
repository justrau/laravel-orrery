<script>
    import {PER_PAGE_CHOICES, pagerActions, pagerLabels, pagerRequest, pagerSummary} from '../../support/lists.js';

    /**
     * The keyset pager of the list engine:
     * Newest, Newer, Older, Oldest, no page numbers (a
     * keyset page has no position), the rows shown of the filtered total
     * ("at least" when capped, "total unknown" when null), and the page size.
     * Below 768 px only Newer and Older remain.
     *
     * `page` is {order, response} of the current page: the request order and
     * the list answer with `prev_cursor` and `next_cursor`. A button emits
     * `navigate` with the request of its page ({order, cursor}, see
     * support/lists.js pagerRequest()); the screen loads it and shows the
     * rows in its display order (pageRows()). The cursor never goes into the
     * URL. With `edges` false (a list with one fixed order) Newest and
     * Oldest are not shown, and the other two say Previous and Next
     * (pagerLabels()).
     */
    export default {
        props: {
            page: {type: Object, default: null},
            rows: {type: Number, default: 0},
            total: {type: Object, default: null},
            displayOrder: {type: String, default: 'desc'},
            perPage: {type: Number, default: 50},
            loading: {type: Boolean, default: false},
            /** False for a list with one fixed order (the stuck signals): no Newest and no Oldest. */
            edges: {type: Boolean, default: true},
        },

        emits: ['navigate', 'update:perPage'],

        computed: {
            actions() {
                const page = this.page || {};

                return pagerActions(page.response || null, page.order || 'desc');
            },

            summary() {
                return pagerSummary({rows: this.rows, total: this.total, actions: this.actions, displayOrder: this.displayOrder});
            },

            shortSummary() {
                return pagerSummary({rows: this.rows, total: this.total, actions: this.actions, displayOrder: this.displayOrder, short: true});
            },

            labels() {
                return pagerLabels(this.edges);
            },

            choices() {
                return PER_PAGE_CHOICES.includes(this.perPage) ? PER_PAGE_CHOICES : [...PER_PAGE_CHOICES, this.perPage].sort((a, b) => a - b);
            },
        },

        methods: {
            go(action) {
                if (this.loading || !this.actions[action]) return;

                const request = pagerRequest(action, this.page);

                // The action too, so a host with its own paging rule can decide (jobs.js pagerRequestFor()).
                if (request) this.$emit('navigate', request, action);
            },

            setPerPage(event) {
                const value = Number(event.target.value);

                if (Number.isInteger(value) && value >= 1 && value <= 100) this.$emit('update:perPage', value);
            },
        },
    };
</script>

<template>
    <nav class="o-pager" aria-label="Pages">
        <span class="o-pager__summary o-pager__summary--wide" aria-live="polite">{{ summary }}</span>

        <div class="o-pager__buttons">
            <button v-if="edges" type="button" class="o-pager__button o-pager__button--edge" :disabled="loading || !actions.newest" @click="go('newest')">Newest</button>
            <button type="button" class="o-pager__button" :disabled="loading || !actions.newer" @click="go('newer')">
                <svg width="10" height="10" viewBox="0 0 10 10" aria-hidden="true"><path d="M6.5 2L3.5 5l3 3" /></svg>
                {{ labels.newer }}
            </button>
            <span class="o-pager__summary o-pager__summary--narrow">{{ shortSummary }}</span>
            <button type="button" class="o-pager__button" :disabled="loading || !actions.older" @click="go('older')">
                {{ labels.older }}
                <svg width="10" height="10" viewBox="0 0 10 10" aria-hidden="true"><path d="M3.5 2l3 3-3 3" /></svg>
            </button>
            <button v-if="edges" type="button" class="o-pager__button o-pager__button--edge" :disabled="loading || !actions.oldest" @click="go('oldest')">Oldest</button>
        </div>

        <label class="o-pager__size">
            <span class="o-pager__visually-hidden">Rows per page</span>
            <select :value="perPage" :disabled="loading" @change="setPerPage">
                <option v-for="choice in choices" :key="choice" :value="choice">{{ choice }} per page</option>
            </select>
        </label>
    </nav>
</template>

<style scoped>
    .o-pager {
        align-items: center;
        display: flex;
        flex-wrap: wrap;
        font-family: var(--font-sans);
        gap: 8px;
        min-width: 0;
    }

    .o-pager__summary {
        color: var(--color-text-secondary);
        font-size: var(--text-table);
        line-height: var(--leading-table);
        margin-right: auto;
        min-width: 0;
    }

    .o-pager__summary--narrow {
        display: none;
    }

    .o-pager__buttons {
        align-items: center;
        border: 1px solid var(--color-separator);
        border-radius: var(--radius-control);
        display: flex;
        height: 28px;
        overflow: hidden;
    }

    .o-pager__button {
        align-items: center;
        background-color: var(--color-bg);
        border: 0;
        border-right: 1px solid var(--color-separator);
        color: var(--color-text);
        cursor: pointer;
        display: flex;
        font-family: var(--font-sans);
        font-size: var(--text-table);
        font-weight: var(--font-weight-medium);
        gap: 4px;
        height: 28px;
        line-height: var(--leading-table);
        padding-inline: 10px;
    }

    .o-pager__button:last-child {
        border-right: 0;
    }

    .o-pager__button svg path {
        fill: none;
        stroke: currentColor;
        stroke-linecap: round;
        stroke-linejoin: round;
        stroke-width: 1.4;
    }

    .o-pager__button:hover:not(:disabled) {
        background-color: var(--color-subtle);
    }

    .o-pager__button:disabled {
        color: var(--color-text-tertiary);
        cursor: default;
        font-weight: var(--font-weight-regular);
    }

    .o-pager__button:focus-visible,
    .o-pager__size select:focus-visible {
        outline: 2px solid color-mix(in srgb, var(--color-accent) 55%, transparent);
        outline-offset: -2px;
    }

    .o-pager__size select {
        appearance: auto;
        background-color: var(--color-bg);
        border: 1px solid var(--color-separator);
        border-radius: var(--radius-control);
        color: var(--color-text);
        font-family: var(--font-sans);
        font-size: var(--text-table);
        height: 28px;
        padding-inline: 8px;
    }

    .o-pager__visually-hidden {
        clip: rect(0 0 0 0);
        height: 1px;
        overflow: hidden;
        position: absolute;
        white-space: nowrap;
        width: 1px;
    }

    /* Below 768 px: Newer and Older around the short summary. */
    @media (max-width: 767px) {
        .o-pager__summary--wide,
        .o-pager__button--edge,
        .o-pager__size {
            display: none;
        }

        .o-pager__buttons {
            border: 0;
            flex: 1 1 auto;
            justify-content: space-between;
        }

        .o-pager__button {
            border-right: 0;
            color: var(--color-accent);
        }

        .o-pager__summary--narrow {
            display: block;
            font-size: var(--text-caption);
            margin: 0;
            text-align: center;
        }
    }
</style>
