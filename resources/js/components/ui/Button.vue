<script>
    import Icon from './Icon.vue';

    /**
     * The buttons:
     * - `link`: a read link in the accent color with a chevron ("Show queue ›");
     * - `write`: the bordered white button of an action ("Pause", "Retry 27…");
     * - `primary`: the filled accent button ("Retry 27 jobs");
     * - `danger`: the bordered red button ("Terminate all workers…");
     * - `danger-solid`: the filled red button, for a destructive confirmation.
     *
     * With `to`, the button is a router link. With `operate`, it is a write
     * action: it is not rendered for a viewer without the operate ability
     * (window.Orrery.canOperate). The server refuses such writes anyway.
     */
    export default {
        components: {Icon},

        props: {
            variant: {
                type: String,
                default: 'write',
                validator: value => ['link', 'write', 'primary', 'danger', 'danger-solid'].includes(value),
            },
            size: {
                type: String,
                default: 'md',
                validator: value => ['sm', 'md', 'lg'].includes(value),
            },
            icon: {type: String, default: null},
            to: {type: [String, Object], default: null},
            disabled: {type: Boolean, default: false},
            busy: {type: Boolean, default: false},
            operate: {type: Boolean, default: false},
            accent: {type: Boolean, default: false},
            block: {type: Boolean, default: false},
            title: {type: String, default: null},
        },

        emits: ['click'],

        computed: {
            visible() {
                return !this.operate || this.canOperate;
            },

            classes() {
                return [
                    'o-button',
                    `o-button--${this.variant}`,
                    `o-button--${this.size}`,
                    {
                        'o-button--accent': this.accent,
                        'o-button--block': this.block,
                        'o-button--icon-only': !this.$slots.default,
                    },
                ];
            },

            iconSize() {
                return this.size === 'sm' ? 14 : (this.variant === 'link' ? 12 : 16);
            },
        },

        methods: {
            onClick(event) {
                if (this.disabled || this.busy) {
                    event.preventDefault();

                    return;
                }

                this.$emit('click', event);
            },
        },
    };
</script>

<template>
    <router-link v-if="visible && to" :to="to" :class="classes" :title="title">
        <icon v-if="icon" :name="icon" :size="iconSize" />
        <span class="o-button__label"><slot /></span>
        <icon v-if="variant === 'link'" name="chevron" :size="12" />
    </router-link>
    <button v-else-if="visible"
            type="button"
            :class="classes"
            :disabled="disabled || busy"
            :aria-busy="busy ? 'true' : null"
            :title="title"
            @click="onClick">
        <icon v-if="icon" :name="icon" :size="iconSize" />
        <span v-if="$slots.default" class="o-button__label"><slot /></span>
        <icon v-if="variant === 'link'" name="chevron" :size="12" />
    </button>
</template>

<style scoped>
    .o-button {
        align-items: center;
        border-radius: var(--radius-control);
        border: 1px solid transparent;
        box-sizing: border-box;
        cursor: pointer;
        display: inline-flex;
        flex-shrink: 0;
        font-family: var(--font-sans);
        font-size: var(--text-table);
        font-weight: var(--font-weight-medium);
        gap: 6px;
        justify-content: center;
        line-height: 18px;
        text-decoration: none;
        white-space: nowrap;
        transition: background-color 0.12s ease, border-color 0.12s ease;
    }

    .o-button:focus-visible {
        outline: 2px solid color-mix(in srgb, var(--color-accent) 55%, transparent);
        outline-offset: 1px;
    }

    .o-button:disabled {
        cursor: default;
        opacity: 0.5;
    }

    .o-button--sm { height: 26px; padding-left: 8px; padding-right: 10px; gap: 5px; }
    .o-button--md { height: 28px; padding-left: 9px; padding-right: 11px; }
    .o-button--lg { height: 30px; padding-inline: 14px; border-radius: 7px; }
    .o-button--icon-only { padding-inline: 0; }
    .o-button--sm.o-button--icon-only { width: 26px; }
    .o-button--md.o-button--icon-only { width: 30px; }
    .o-button--lg.o-button--icon-only { width: 30px; }
    .o-button--block { display: flex; flex-basis: 0; flex-grow: 1; }

    .o-button--write {
        background-color: var(--color-bg);
        border-color: var(--color-separator);
        box-shadow: var(--shadow-button);
        color: var(--color-text);
    }

    .o-button--lg.o-button--write {
        border-color: color-mix(in srgb, var(--color-text) 18%, transparent);
        box-shadow: none;
    }

    .o-button--write:hover:not(:disabled) {
        background-color: var(--color-subtle);
    }

    .o-button--write.o-button--accent {
        color: var(--color-accent);
    }

    .o-button--primary {
        background-color: var(--color-accent-fill);
        color: var(--color-on-accent);
        font-weight: var(--font-weight-semibold);
    }

    .o-button--primary:hover:not(:disabled) {
        background-color: color-mix(in srgb, var(--color-accent-fill) 88%, var(--color-shade));
    }

    .o-button--danger {
        background-color: var(--color-bg);
        border-color: color-mix(in srgb, var(--color-danger) 35%, transparent);
        color: var(--color-danger-text);
    }

    .o-button--danger:hover:not(:disabled) {
        background-color: var(--color-danger-tint);
    }

    .o-button--danger .o-icon {
        color: var(--color-danger);
    }

    .o-button--danger-solid {
        background-color: var(--color-danger);
        color: var(--color-on-accent);
        font-weight: var(--font-weight-semibold);
    }

    .o-button--danger-solid:hover:not(:disabled) {
        background-color: color-mix(in srgb, var(--color-danger) 88%, var(--color-shade));
    }

    .o-button--link {
        background: none;
        border: none;
        color: var(--color-accent);
        gap: 2px;
        height: auto;
        padding: 0;
    }

    .o-button--link:hover:not(:disabled) .o-button__label {
        text-decoration: underline;
    }
</style>
