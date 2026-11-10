<script>
    import Button from './Button.vue';
    import {alertButtons, defaultAction, escapeAction} from '../../support/alert.js';
    import {FOCUSABLE, wrapFocus} from '../../support/focus.js';

    /**
     * The global alert and confirmation host, on the tokens. It
     * replaced the Bootstrap modal of the old components/Alert.vue and kept
     * its contract: errors.js and the screens write
     * the root's `alert` {type, message, autoClose, confirmationProceed,
     * confirmationCancel}; this host shows it while `type` is set.
     *
     * - Close: emits `dismiss`; the root records `lastDismissedAlert` and
     *   clears the alert (errors.js does not show a dismissed poll failure
     *   again for a while).
     * - Confirm: calls `confirmationProceed`, then closes. Cancel calls
     *   `confirmationCancel`, then closes.
     * - `autoClose` (milliseconds) closes it after that time.
     * - Keyboard: the default button has the focus (Cancel for
     *   a confirmation), Tab stays inside, Escape cancels or closes (never
     *   confirms), and the focus goes back to where it was.
     *
     * The message is text, never markup.
     */
    export default {
        components: {'o-button': Button},

        props: {
            alert: {type: Object, required: true},
        },

        emits: ['dismiss'],

        data() {
            return {
                timeout: null,
                opener: null,
            };
        },

        computed: {
            open() {
                return !!this.alert.type;
            },

            buttons() {
                return alertButtons(this.alert.type);
            },
        },

        watch: {
            'alert.type': {
                immediate: true,
                handler(type, previous) {
                    if (type && !previous) this.onOpen();
                    if (!type && previous) this.onClosed();
                    // Another alert replaced the open one (a terminal reload over an error).
                    if (type && previous && type !== previous) this.onReplaced();
                },
            },
        },

        beforeUnmount() {
            clearTimeout(this.timeout);
            window.removeEventListener('keydown', this.onKeydown, true);
        },

        methods: {
            onOpen() {
                this.opener = document.activeElement;
                window.addEventListener('keydown', this.onKeydown, true);

                clearTimeout(this.timeout);

                if (this.alert.autoClose) {
                    this.timeout = setTimeout(() => this.close(), this.alert.autoClose);
                }

                this.focusDefault();
            },

            onReplaced() {
                clearTimeout(this.timeout);

                if (this.alert.autoClose) {
                    this.timeout = setTimeout(() => this.close(), this.alert.autoClose);
                }

                this.focusDefault();
            },

            focusDefault() {
                this.$nextTick(() => {
                    const button = this.$refs.dialog && this.$refs.dialog.querySelector(`[data-alert-action="${defaultAction(this.alert.type)}"]`);

                    if (button) button.focus();
                });
            },

            onClosed() {
                clearTimeout(this.timeout);
                window.removeEventListener('keydown', this.onKeydown, true);

                const opener = this.opener;

                this.opener = null;

                if (opener && typeof opener.focus === 'function' && document.contains(opener)) {
                    this.$nextTick(() => opener.focus());
                }
            },

            onKeydown(event) {
                if (!this.open) return;

                if (event.key === 'Escape') {
                    // Above every sheet: an open ConfirmSheet must not also close.
                    event.preventDefault();
                    event.stopPropagation();
                    this.run(escapeAction(this.alert.type));

                    return;
                }

                if (event.key === 'Tab' && this.$refs.dialog) {
                    event.stopPropagation();

                    const target = wrapFocus([...this.$refs.dialog.querySelectorAll(FOCUSABLE)], document.activeElement, event.shiftKey);

                    if (target) {
                        event.preventDefault();
                        target.focus();
                    }
                }
            },

            run(action) {
                if (action === 'confirm') {
                    const proceed = this.alert.confirmationProceed;

                    this.close();

                    if (typeof proceed === 'function') proceed();

                    return;
                }

                if (action === 'cancel') {
                    const cancel = this.alert.confirmationCancel;

                    this.close();

                    if (typeof cancel === 'function') cancel();

                    return;
                }

                this.close();
            },

            close() {
                clearTimeout(this.timeout);

                if (this.open) this.$emit('dismiss', this.alert.message);
            },
        },
    };
</script>

<template>
    <teleport to="body">
        <div v-if="open" class="o-alert-backdrop">
            <div ref="dialog" class="o-alert" role="alertdialog" aria-modal="true" aria-labelledby="o-alert-message">
                <p id="o-alert-message" class="o-alert__message">{{ alert.message }}</p>
                <div class="o-alert__actions">
                    <template v-for="button in buttons" :key="button.action">
                        <button v-if="button.variant === 'secondary'" type="button" class="o-alert__secondary"
                                :data-alert-action="button.action" @click="run(button.action)">
                            {{ button.label }}
                        </button>
                        <o-button v-else size="lg" :variant="button.variant === 'danger' ? 'danger-solid' : 'primary'"
                                  :data-alert-action="button.action" @click="run(button.action)">
                            {{ button.label }}
                        </o-button>
                    </template>
                </div>
            </div>
        </div>
    </teleport>
</template>

<style scoped>
    .o-alert-backdrop {
        align-items: flex-start;
        background-color: color-mix(in srgb, var(--color-text) 28%, transparent);
        box-sizing: border-box;
        display: flex;
        inset: 0;
        justify-content: center;
        padding: 52px 16px 16px;
        position: fixed;
        z-index: 1080;
    }

    .o-alert {
        background-color: var(--color-bg);
        border-radius: 0 0 var(--radius-window) var(--radius-window);
        box-shadow: 0 12px 32px rgba(0, 0, 0, 0.25), 0 0 0 0.5px rgba(0, 0, 0, 0.14);
        box-sizing: border-box;
        display: flex;
        flex-direction: column;
        font-family: var(--font-sans);
        gap: 16px;
        max-height: calc(100vh - 104px);
        max-width: 100%;
        overflow: auto;
        padding: 20px 20px 16px;
        width: 420px;
    }

    .o-alert__message {
        color: var(--color-text);
        font-size: var(--text-body);
        line-height: var(--leading-body);
        margin: 0;
        overflow-wrap: anywhere;
        white-space: pre-line;
    }

    .o-alert__actions {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        justify-content: flex-end;
    }

    .o-alert__secondary {
        background-color: var(--color-bg);
        border: 1px solid color-mix(in srgb, var(--color-text) 18%, transparent);
        border-radius: 7px;
        color: var(--color-text);
        cursor: pointer;
        font-family: var(--font-sans);
        font-size: var(--text-table);
        font-weight: var(--font-weight-medium);
        height: 30px;
        line-height: 18px;
        padding-inline: 14px;
    }

    .o-alert__secondary:hover {
        background-color: var(--color-subtle);
    }

    .o-alert__secondary:focus-visible {
        outline: 2px solid color-mix(in srgb, var(--color-accent) 55%, transparent);
        outline-offset: 1px;
    }
</style>
