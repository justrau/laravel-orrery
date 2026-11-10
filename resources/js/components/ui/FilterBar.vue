<script>
    import {live} from '../../shell/store.js';
    import {
        TIME_PRESETS,
        activeFilterCount,
        activePreset,
        clearedFilters,
        committedText,
        epochFromInput,
        filterSummary,
        inputFromEpoch,
        mergeQuery,
        presetRange,
        queueOptions,
        rangeProblem,
        rangeZoneNote,
        withFilters,
    } from '../../support/filters.js';
    import {phpTrim, qProblem, tabItems} from '../../support/lists.js';
    import {nowSeconds} from '../../support/time.js';

    /** The wait before a typed search text is applied. */
    const SEARCH_DEBOUNCE_MS = 300;

    /**
     * The filter bar of the lists:
     * optional status tabs with the global
     * counts, the search text `q`, the queue (connection:queue from the
     * shell's workload), the class, the tag, the batch (a removable chip
     * while set), and the time range (1 h, 24 h, 7 d, All, Custom…).
     *
     * `filters` is the object of support/lists.js `filtersFromQuery()`. A
     * change emits `change` with the new filters and, with `syncUrl` (the
     * default), replaces the list keys of the route query (the other keys of
     * the screen stay; the cursor never goes into the URL). The byte-exact
     * values (connection, queue, class, batch) are never trimmed.
     *
     * Below 768 px the tabs scroll as chips, and every filter but the search
     * sits behind a "Filters N" button, with the active filters in
     * words under it. Escape closes that panel and returns the focus to the
     * button.
     */
    export default {
        props: {
            filters: {type: Object, required: true},
            fields: {type: Array, default: () => ['q', 'queue', 'class', 'tag', 'range']},
            tabs: {type: Boolean, default: false},
            counts: {type: Object, default: null},
            workload: {type: Array, default: null},
            syncUrl: {type: Boolean, default: true},
        },

        emits: ['change'],

        data() {
            return {
                qText: this.filters.q || '',
                classText: this.filters.class || '',
                tagText: this.filters.tag || '',
                presetChoice: null,
                customOpen: false,
                customFrom: '',
                customTo: '',
                customProblem: null,
                panelOpen: false,
                searchTimer: null,
            };
        },

        computed: {
            tabList() {
                return tabItems(this.counts, this.filters.status);
            },

            queues() {
                return queueOptions(this.workload !== null ? this.workload : live.workload, this.filters);
            },

            queueKey() {
                return this.filters.connection && this.filters.queue ? `${this.filters.connection}:${this.filters.queue}` : '';
            },

            presets() {
                return TIME_PRESETS;
            },

            preset() {
                return activePreset(this.filters, this.presetChoice);
            },

            count() {
                return activeFilterCount(this.filters);
            },

            summary() {
                return filterSummary(this.filters, this.presetChoice).join(' · ');
            },

            searchProblem() {
                return qProblem(this.qText);
            },

            clearable() {
                return this.count > 0 || !!this.filters.q;
            },
        },

        watch: {
            filters(filters) {
                // A change from the URL (back navigation) or from the screen.
                if ((filters.q || '') !== phpTrim(this.qText)) this.qText = filters.q || '';
                this.classText = filters.class || '';
                this.tagText = filters.tag || '';
            },
        },

        beforeUnmount() {
            clearTimeout(this.searchTimer);
            document.removeEventListener('keydown', this.onPanelKeydown);
        },

        methods: {
            /** The zone that the custom-range inputs are read in (filters.js rangeZoneNote()). */
            zoneNote() {
                return rangeZoneNote(nowSeconds());
            },

            has(field) {
                return this.fields.includes(field);
            },

            apply(next) {
                this.$emit('change', next);

                if (this.syncUrl && this.$router && this.$route) {
                    this.$router.push({query: mergeQuery(this.$route.query, next)}).catch(() => {});
                }
            },

            change(changes) {
                this.apply(withFilters(this.filters, changes));
            },

            selectTab(key) {
                if (key !== this.filters.status) this.apply({...this.filters, status: key});
            },

            onSearchInput() {
                clearTimeout(this.searchTimer);
                this.searchTimer = setTimeout(() => this.commitSearch(), SEARCH_DEBOUNCE_MS);
            },

            commitSearch() {
                clearTimeout(this.searchTimer);

                const value = committedText('q', this.qText);

                if (qProblem(value) !== null || value === this.filters.q) return;

                this.change({q: value});
            },

            selectQueue(event) {
                const option = this.queues.find(entry => entry.key === event.target.value);

                this.change(option ? {connection: option.connection, queue: option.queue} : {connection: null, queue: null});
            },

            commitText(key, typed) {
                const value = committedText(key, typed);

                if (value !== this.filters[key]) this.change({[key]: value});
            },

            choosePreset(key) {
                this.customOpen = false;

                if (key === 'custom') {
                    this.customFrom = inputFromEpoch(this.filters.from);
                    this.customTo = inputFromEpoch(this.filters.to);
                    this.customProblem = null;
                    this.customOpen = true;

                    return;
                }

                const range = presetRange(key, Math.floor(nowSeconds()));

                this.presetChoice = key === 'all' ? null : {key, from: range.from};
                this.change(range);
            },

            applyCustom() {
                const from = epochFromInput(this.customFrom);
                const to = epochFromInput(this.customTo);

                this.customProblem = rangeProblem(from, to);

                if (this.customProblem !== null) return;

                this.presetChoice = null;
                this.customOpen = false;
                this.change({from, to});
            },

            clearAll() {
                this.presetChoice = null;
                this.qText = '';
                this.apply(clearedFilters(this.filters));
            },

            togglePanel() {
                this.panelOpen = !this.panelOpen;

                if (this.panelOpen) document.addEventListener('keydown', this.onPanelKeydown);
                else document.removeEventListener('keydown', this.onPanelKeydown);
            },

            onPanelKeydown(event) {
                if (event.key !== 'Escape' || !this.panelOpen) return;

                this.togglePanel();
                this.$nextTick(() => this.$refs.panelButton && this.$refs.panelButton.focus());
            },
        },
    };
</script>

<template>
    <div class="o-filters">
        <nav v-if="tabs" class="o-filters__tabs" aria-label="States">
            <button v-for="tab in tabList" :key="tab.key" type="button" class="o-filters__tab"
                    :class="{'o-filters__tab--active': tab.active}" :aria-current="tab.active ? 'page' : null"
                    @click="selectTab(tab.key)">
                <span>{{ tab.label }}</span>
                <span v-if="tab.count !== null" class="o-filters__tab-count">{{ tab.count }}</span>
            </button>
        </nav>

        <div class="o-filters__bar">
            <div v-if="has('q')" class="o-filters__search" :class="{'o-filters__search--problem': searchProblem}">
                <svg width="14" height="14" viewBox="0 0 14 14" aria-hidden="true">
                    <circle cx="6" cy="6" r="4.2" />
                    <path d="M9.2 9.2l3 3" />
                </svg>
                <input v-model="qText" type="search" placeholder="uuid, class name or tag" aria-label="Search by uuid, class name or tag"
                       maxlength="200" @input="onSearchInput" @keydown.enter.prevent="commitSearch" @blur="commitSearch">
            </div>

            <button ref="panelButton" type="button" class="o-filters__toggle" :class="{'o-filters__toggle--set': count > 0}"
                    :aria-expanded="panelOpen ? 'true' : 'false'" aria-controls="o-filters-panel" @click="togglePanel">
                <svg width="14" height="14" viewBox="0 0 14 14" aria-hidden="true"><path d="M2 3.5h10M4 7h6M6 10.5h2" /></svg>
                Filters<span v-if="count > 0">&nbsp;{{ count }}</span>
            </button>

            <div id="o-filters-panel" class="o-filters__panel" :class="{'o-filters__panel--open': panelOpen}">
                <label v-if="has('queue')" class="o-filters__chip" :class="{'o-filters__chip--set': queueKey !== ''}">
                    <span class="o-filters__chip-label">Queue</span>
                    <select :value="queueKey" @change="selectQueue">
                        <option value="">Any</option>
                        <option v-for="option in queues" :key="option.key" :value="option.key">{{ option.label }}</option>
                    </select>
                </label>

                <label v-if="has('class')" class="o-filters__chip" :class="{'o-filters__chip--set': !!filters.class}">
                    <span class="o-filters__chip-label">Class</span>
                    <input v-model="classText" type="text" placeholder="Any" aria-label="Exact job class"
                           @keydown.enter.prevent="commitText('class', classText)" @blur="commitText('class', classText)">
                </label>

                <label v-if="has('tag')" class="o-filters__chip" :class="{'o-filters__chip--set': !!filters.tag}">
                    <span class="o-filters__chip-label">Tag</span>
                    <input v-model="tagText" type="text" placeholder="Any" aria-label="Exact tag"
                           @keydown.enter.prevent="commitText('tag', tagText)" @blur="commitText('tag', tagText)">
                </label>

                <span v-if="filters.batch" class="o-filters__chip o-filters__chip--set" :title="filters.batch">
                    <span class="o-filters__chip-label">Batch</span>
                    <span class="o-filters__chip-value">{{ filters.batch }}</span>
                    <button type="button" class="o-filters__remove" aria-label="Remove the batch filter" @click="change({batch: null})">
                        <svg width="10" height="10" viewBox="0 0 10 10" aria-hidden="true"><path d="M2.5 2.5l5 5M7.5 2.5l-5 5" /></svg>
                    </button>
                </span>

                <div v-if="has('range')" class="o-filters__range" role="group" aria-label="Time range">
                    <button v-for="entry in presets" :key="entry.key" type="button" class="o-filters__segment"
                            :class="{'o-filters__segment--active': preset === entry.key}" :aria-pressed="preset === entry.key ? 'true' : 'false'"
                            @click="choosePreset(entry.key)">{{ entry.label }}</button>
                    <button type="button" class="o-filters__segment" :class="{'o-filters__segment--active': preset === 'all'}"
                            :aria-pressed="preset === 'all' ? 'true' : 'false'" @click="choosePreset('all')">All</button>
                    <button type="button" class="o-filters__segment" :class="{'o-filters__segment--active': preset === 'custom'}"
                            :aria-pressed="preset === 'custom' ? 'true' : 'false'" :aria-expanded="customOpen ? 'true' : 'false'"
                            @click="choosePreset('custom')">Custom…</button>
                </div>

                <form v-if="customOpen" class="o-filters__custom" @submit.prevent="applyCustom">
                    <label>From <input v-model="customFrom" type="datetime-local"></label>
                    <label>To <input v-model="customTo" type="datetime-local"></label>
                    <button type="submit" class="o-filters__apply">Apply</button>
                    <button type="button" class="o-filters__link" @click="customOpen = false">Cancel</button>
                    <span class="o-filters__zone" data-range-zone>{{ zoneNote() }}</span>
                    <span v-if="customProblem" class="o-filters__problem" role="alert">{{ customProblem }}</span>
                </form>

                <span class="o-filters__spacer"></span>
                <button v-if="clearable" type="button" class="o-filters__link" @click="clearAll">Clear filters</button>
            </div>
        </div>

        <p v-if="searchProblem" class="o-filters__problem">{{ searchProblem }}</p>
        <p v-if="summary" class="o-filters__summary">{{ summary }}</p>
    </div>
</template>

<style scoped>
    .o-filters {
        display: flex;
        flex-direction: column;
        font-family: var(--font-sans);
        gap: 10px;
        min-width: 0;
    }

    .o-filters__tabs {
        display: flex;
        gap: 4px;
        overflow-x: auto;
        scrollbar-width: none;
    }

    .o-filters__tab {
        align-items: center;
        background: transparent;
        border: 0;
        border-radius: var(--radius-control);
        color: var(--color-text-secondary);
        cursor: pointer;
        display: flex;
        flex-shrink: 0;
        font-family: var(--font-sans);
        font-size: var(--text-table);
        font-weight: var(--font-weight-medium);
        gap: 6px;
        line-height: var(--leading-table);
        padding: 5px 10px;
    }

    .o-filters__tab:hover {
        background-color: var(--color-subtle);
    }

    .o-filters__tab--active {
        background-color: var(--color-accent-tint);
        color: var(--color-accent);
        font-weight: var(--font-weight-semibold);
    }

    .o-filters__tab-count {
        color: var(--color-text-tertiary);
        font-variant-numeric: tabular-nums;
        font-weight: var(--font-weight-regular);
    }

    .o-filters__tab--active .o-filters__tab-count {
        color: var(--color-accent);
    }

    .o-filters__bar {
        align-items: center;
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        min-width: 0;
    }

    .o-filters__search {
        align-items: center;
        background-color: var(--color-bg);
        border: 1px solid var(--color-separator);
        border-radius: var(--radius-control);
        box-sizing: border-box;
        display: flex;
        flex-shrink: 0;
        gap: 6px;
        height: 28px;
        padding-inline: 8px;
        width: 250px;
    }

    .o-filters__search--problem {
        border-color: color-mix(in srgb, var(--color-warning) 60%, transparent);
    }

    .o-filters__search svg circle,
    .o-filters__search svg path,
    .o-filters__toggle svg path,
    .o-filters__remove svg path {
        fill: none;
        stroke: currentColor;
        stroke-linecap: round;
        stroke-width: 1.4;
    }

    .o-filters__search svg {
        color: var(--color-text-secondary);
        flex-shrink: 0;
    }

    .o-filters__search input,
    .o-filters__chip input,
    .o-filters__chip select {
        background: transparent;
        border: 0;
        color: var(--color-text);
        font-family: var(--font-sans);
        font-size: var(--text-table);
        line-height: var(--leading-table);
        min-width: 0;
        outline: none;
    }

    .o-filters__search input {
        flex: 1 1 auto;
    }

    .o-filters__search input::placeholder,
    .o-filters__chip input::placeholder {
        color: var(--color-text-tertiary);
    }

    .o-filters__search:focus-within,
    .o-filters__chip:focus-within {
        border-color: var(--color-accent);
        box-shadow: 0 0 0 3px color-mix(in srgb, var(--color-accent) 20%, transparent);
    }

    .o-filters__toggle {
        display: none;
    }

    .o-filters__panel {
        align-items: center;
        display: flex;
        flex: 1 1 auto;
        flex-wrap: wrap;
        gap: 8px;
        min-width: 0;
    }

    .o-filters__chip {
        align-items: center;
        background-color: var(--color-bg);
        border: 1px solid var(--color-separator);
        border-radius: var(--radius-control);
        box-sizing: border-box;
        display: flex;
        gap: 6px;
        height: 28px;
        max-width: 100%;
        min-width: 0;
        padding-left: 10px;
        padding-right: 8px;
    }

    .o-filters__chip--set {
        background-color: var(--color-accent-tint);
        border-color: color-mix(in srgb, var(--color-accent) 35%, transparent);
    }

    .o-filters__chip-label {
        color: var(--color-text-secondary);
        font-size: var(--text-table);
        line-height: var(--leading-table);
    }

    .o-filters__chip input,
    .o-filters__chip select,
    .o-filters__chip-value {
        font-weight: var(--font-weight-medium);
        max-width: 220px;
    }

    .o-filters__chip input {
        width: 120px;
    }

    .o-filters__chip-value {
        color: var(--color-text);
        font-size: var(--text-table);
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .o-filters__remove {
        align-items: center;
        background: transparent;
        border: 0;
        border-radius: 4px;
        color: var(--color-text-secondary);
        cursor: pointer;
        display: flex;
        padding: 2px;
    }

    .o-filters__range {
        align-items: center;
        background-color: var(--color-separator);
        border-radius: 7px;
        display: flex;
        flex-wrap: wrap;
        padding: 2px;
    }

    .o-filters__segment {
        background: transparent;
        border: 0;
        border-radius: 5px;
        color: var(--color-text);
        cursor: pointer;
        font-family: var(--font-sans);
        font-size: var(--text-table);
        line-height: var(--leading-table);
        padding: 3px 10px;
    }

    .o-filters__segment--active {
        background-color: var(--color-bg);
        box-shadow: 0 1px 2px rgba(0, 0, 0, 0.12);
        font-weight: var(--font-weight-semibold);
    }

    .o-filters__custom {
        align-items: center;
        display: flex;
        flex-basis: 100%;
        flex-wrap: wrap;
        gap: 8px;
    }

    .o-filters__custom label {
        align-items: center;
        color: var(--color-text-secondary);
        display: flex;
        font-size: var(--text-table);
        gap: 6px;
    }

    .o-filters__custom input {
        border: 1px solid var(--color-separator);
        border-radius: var(--radius-control);
        color: var(--color-text);
        font-family: var(--font-sans);
        font-size: var(--text-table);
        height: 28px;
        padding-inline: 6px;
    }

    .o-filters__apply {
        background-color: var(--color-accent);
        border: 0;
        border-radius: var(--radius-control);
        color: var(--color-on-accent);
        cursor: pointer;
        font-family: var(--font-sans);
        font-size: var(--text-table);
        font-weight: var(--font-weight-semibold);
        height: 28px;
        padding-inline: 14px;
    }

    .o-filters__spacer {
        flex: 1 1 auto;
    }

    .o-filters__link {
        background: transparent;
        border: 0;
        color: var(--color-accent);
        cursor: pointer;
        font-family: var(--font-sans);
        font-size: var(--text-table);
        line-height: var(--leading-table);
        padding: 4px 2px;
    }

    .o-filters__zone {
        color: var(--color-text-secondary);
        font-size: var(--text-caption);
        line-height: 16px;
    }

    .o-filters__problem {
        color: var(--color-warning-text);
        font-size: var(--text-caption);
        line-height: 16px;
        margin: 0;
    }

    .o-filters__summary {
        display: none;
    }

    .o-filters__tab:focus-visible,
    .o-filters__toggle:focus-visible,
    .o-filters__segment:focus-visible,
    .o-filters__remove:focus-visible,
    .o-filters__link:focus-visible,
    .o-filters__apply:focus-visible,
    .o-filters__custom input:focus-visible {
        outline: 2px solid color-mix(in srgb, var(--color-accent) 55%, transparent);
        outline-offset: 1px;
    }

    /* Below 768 px: chips, the search on its own, the rest behind "Filters N". */
    @media (max-width: 767px) {
        .o-filters__tab {
            background-color: var(--color-subtle);
            border-radius: 14px;
        }

        .o-filters__tab--active {
            background-color: var(--color-accent-tint);
        }

        .o-filters__search {
            background-color: var(--color-sidebar);
            border-color: transparent;
            border-radius: 8px;
            flex: 1 1 auto;
            height: 34px;
            width: auto;
        }

        .o-filters__search input {
            font-size: var(--text-body);
        }

        .o-filters__toggle {
            align-items: center;
            background-color: var(--color-sidebar);
            border: 0;
            border-radius: 8px;
            color: var(--color-text);
            cursor: pointer;
            display: flex;
            font-family: var(--font-sans);
            font-size: var(--text-body);
            font-weight: var(--font-weight-semibold);
            gap: 6px;
            height: 34px;
            padding-inline: 10px;
        }

        .o-filters__toggle--set {
            background-color: var(--color-accent-tint);
            color: var(--color-accent);
        }

        .o-filters__panel {
            display: none;
        }

        .o-filters__panel--open {
            align-items: stretch;
            display: flex;
            flex-basis: 100%;
            flex-direction: column;
        }

        .o-filters__chip input,
        .o-filters__chip select {
            flex: 1 1 auto;
            max-width: none;
        }

        .o-filters__summary {
            color: var(--color-text-secondary);
            display: block;
            font-size: var(--text-caption);
            line-height: 16px;
            margin: 0;
            overflow-wrap: anywhere;
        }
    }
</style>
