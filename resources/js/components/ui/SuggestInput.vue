<script>
    import {ANY_ERROR_STATUS, BACKGROUND_FAILURES} from '../../errors.js';
    import {SUGGEST_DEBOUNCE_MS, comboKey, suggestionItems, suggestionRequest} from '../../support/suggest.js';

    /** A failed suggestion read is silent: the field works without a list. */
    const HANDLES = [ANY_ERROR_STATUS, ...BACKGROUND_FAILURES];

    /**
     * A text field with suggestions (the ARIA combobox pattern with a
     * listbox): the filter bar's Class and Tag fields.
     *
     * - The input is the combobox (`aria-expanded`, `aria-controls`,
     *   `aria-activedescendant`); the options are a `listbox` of `option`s.
     * - Focus and typing read `GET /api/suggestions` after a short pause
     *   (support/suggest.js); only the newest answer is applied
     *   (startRequest()), and nothing after unmount. A failed read shows no
     *   list.
     * - Down and Up open the list and move through it, Enter selects the
     *   active option (or, without one, commits the typed text as before),
     *   Escape closes the list, Tab closes it and moves on. A click selects.
     * - A selection fills the full value (a class's full name) and emits
     *   `commit`; typed text is committed by the host on Enter and on blur.
     */
    export default {
        props: {
            modelValue: {type: String, default: ''},
            field: {type: String, required: true},
            inputId: {type: String, required: true},
            label: {type: String, required: true},
            placeholder: {type: String, default: ''},
        },

        emits: ['update:modelValue', 'commit', 'blur'],

        data() {
            return {items: [], open: false, active: null, focused: false};
        },

        computed: {
            listId() {
                return `${this.inputId}-list`;
            },

            activeId() {
                return this.open && this.active !== null ? `${this.inputId}-option-${this.active}` : null;
            },

            expanded() {
                return this.open && this.items.length > 0;
            },
        },

        beforeUnmount() {
            clearTimeout(this.timer);
            this.unmounted = true;
            this.startRequest('suggest');
        },

        methods: {
            optionId(index) {
                return `${this.inputId}-option-${index}`;
            },

            schedule() {
                clearTimeout(this.timer);
                this.timer = setTimeout(() => this.load(), SUGGEST_DEBOUNCE_MS);
            },

            load() {
                const request = suggestionRequest(this.field, this.modelValue);
                const sequence = this.startRequest('suggest');

                if (request === null) {
                    this.items = [];
                    this.open = false;

                    return;
                }

                this.$http.get(Orrery.basePath + request.url, {params: request.params, handles: HANDLES})
                    .then(response => {
                        if (this.unmounted || !this.isCurrentRequest('suggest', sequence)) return;

                        this.items = suggestionItems(response.data, this.field) || [];
                        this.active = null;
                        this.open = this.focused && this.items.length > 0;
                    })
                    .catch(() => {
                        if (this.unmounted || !this.isCurrentRequest('suggest', sequence)) return;

                        this.items = [];
                        this.open = false;
                    });
            },

            onFocus() {
                this.focused = true;
                this.schedule();
            },

            onInput(event) {
                this.$emit('update:modelValue', event.target.value);
                this.schedule();
            },

            /** Drops the pending read: an answer that arrives later does not open the list again. */
            cancelLoad() {
                clearTimeout(this.timer);
                this.startRequest('suggest');
            },

            onBlur() {
                this.focused = false;
                this.open = false;
                this.active = null;
                this.cancelLoad();
                this.$emit('blur');
            },

            onKeydown(event) {
                const step = comboKey({open: this.expanded, active: this.active, count: this.items.length}, event.key);

                if (step === null) return;

                if (step.prevent) event.preventDefault();

                this.open = step.open;
                this.active = step.active;

                if (step.cancel) this.cancelLoad();

                if (step.select !== null) {
                    this.choose(step.select);

                    return;
                }

                if (event.key === 'Enter') this.$emit('commit', this.modelValue);

                if (step.active !== null) {
                    this.$nextTick(() => {
                        const option = document.getElementById(this.optionId(step.active));

                        if (option) option.scrollIntoView({block: 'nearest'});
                    });
                }
            },

            choose(index) {
                const item = this.items[index];

                if (!item) return;

                this.cancelLoad();
                this.open = false;
                this.active = null;
                this.$emit('update:modelValue', item.value);
                this.$emit('commit', item.value);
            },
        },
    };
</script>

<template>
    <span class="o-suggest">
        <input :id="inputId" :value="modelValue" type="text" role="combobox" autocomplete="off" spellcheck="false"
               :placeholder="placeholder" :aria-label="label" aria-autocomplete="list" :aria-controls="listId"
               :aria-expanded="expanded ? 'true' : 'false'" :aria-activedescendant="activeId"
               @focus="onFocus" @input="onInput" @blur="onBlur" @keydown="onKeydown">
        <ul v-show="expanded" :id="listId" class="o-suggest__list" role="listbox" :aria-label="`${label} suggestions`" data-suggestions>
            <li v-for="(item, index) in items" :id="optionId(index)" :key="item.key" class="o-suggest__option"
                :class="{'o-suggest__option--active': index === active}" role="option" :aria-selected="index === active ? 'true' : 'false'"
                :title="item.value" data-suggestion @mousedown.prevent @click="choose(index)" @mousemove="active = index">
                <span class="o-suggest__primary">{{ item.primary }}</span>
                <span v-if="item.secondary" class="o-suggest__secondary">{{ item.secondary }}</span>
                <span v-if="item.monitored" class="o-suggest__badge">monitored</span>
            </li>
        </ul>
    </span>
</template>

<style scoped>
    /* The list is placed against the host's positioned box (the filter chip), so it starts at the chip's edge. */
    .o-suggest {
        display: flex;
        flex: 1 1 auto;
        min-width: 0;
    }

    .o-suggest input {
        background: transparent;
        border: 0;
        color: var(--color-text);
        font-family: var(--font-sans);
        font-size: var(--text-table);
        font-weight: var(--font-weight-medium);
        line-height: var(--leading-table);
        max-width: 220px;
        min-width: 0;
        width: 140px;
    }

    .o-suggest input::placeholder {
        color: var(--color-text-tertiary);
    }

    .o-suggest__list {
        background-color: var(--color-bg);
        border: 1px solid var(--color-separator);
        border-radius: 8px;
        box-shadow: var(--shadow-panel);
        box-sizing: border-box;
        left: -1px;
        list-style: none;
        margin: 0;
        max-height: 280px;
        max-width: min(420px, calc(100vw - 32px));
        min-width: 260px;
        overflow-y: auto;
        padding: 4px;
        position: absolute;
        top: calc(100% + 4px);
        z-index: 30;
    }

    .o-suggest__option {
        border-radius: 5px;
        cursor: pointer;
        display: flex;
        flex-direction: column;
        padding: 5px 8px;
    }

    .o-suggest__option--active {
        background-color: var(--color-accent-tint);
    }

    .o-suggest__primary {
        color: var(--color-text);
        font-size: var(--text-table);
        font-weight: var(--font-weight-semibold);
        line-height: 18px;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .o-suggest__secondary {
        color: var(--color-text-secondary);
        font-size: var(--text-caption);
        line-height: 16px;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .o-suggest__badge {
        color: var(--color-accent);
        font-size: var(--text-caption);
        line-height: 16px;
    }

    @media (max-width: 767px) {
        .o-suggest input {
            flex: 1 1 auto;
            max-width: none;
        }

        .o-suggest__list {
            max-width: none;
            min-width: 0;
            right: -1px;
        }
    }
</style>
