<script>
    import Button from './Button.vue';
    import {FOCUSABLE, wrapFocus} from '../../support/focus.js';

    /** A deep copy of plain data (the selection is JSON: scope, keys, occurrence pairs). */
    function snapshot(value) {
        return value === undefined ? null : JSON.parse(JSON.stringify(value));
    }

    /**
     * The confirmation sheet of a write action (Retry, Forget, Pause,
     * Terminate).
     *
     * The content is a SNAPSHOT:
     * when `open` becomes true, the sheet copies `title`,
     * `message`, `confirmLabel`, `tone` and `selection`, and renders only the
     * copies. A background refresh that changes the props of an open sheet
     * changes nothing on the sheet, and `confirm` emits the frozen selection.
     * The next opening takes a new snapshot.
     *
     * The contract of the snapshot:
     * - It is taken when `open` changes from false to true, and when
     *   `snapshotKey` changes while the sheet is open.
     * - A screen that builds a new selection for an open sheet (a fresh
     *   detail read), or that closes and reopens the sheet in one tick (Vue
     *   then sees no change of `open`), changes `snapshotKey`, for example a
     *   counter that it increments for every confirmation.
     * - No other prop change reaches an open sheet.
     *
     * The slot `default` receives `{ selection }` (the frozen copy) for the
     * details. Escape and the Cancel button emit `cancel`; the parent closes
     * the sheet by setting `open` to false.
     *
     * Keyboard: the sheet focuses Cancel when it opens, keeps
     * Tab inside itself, and gives the focus back to the control that had it
     * before (its opener) when it closes. `confirmDisabled` disables the
     * confirm button (a bulk forget until the typed count matches).
     */
    export default {
        components: {'o-button': Button},

        props: {
            open: {type: Boolean, default: false},
            title: {type: String, default: ''},
            message: {type: String, default: null},
            confirmLabel: {type: String, default: 'Confirm'},
            tone: {type: String, default: 'primary', validator: value => ['primary', 'danger'].includes(value)},
            selection: {type: [Object, Array], default: null},
            busy: {type: Boolean, default: false},
            confirmDisabled: {type: Boolean, default: false},
            snapshotKey: {type: [String, Number], default: null},
        },

        emits: ['confirm', 'cancel'],

        data() {
            return {
                frozen: null,
                opener: null,
            };
        },

        watch: {
            snapshotKey() {
                if (this.open) this.freeze();
            },

            open: {
                immediate: true,
                handler(open) {
                    if (open) {
                        this.opener = typeof document !== 'undefined' ? document.activeElement : null;
                        this.freeze();
                        this.$nextTick(() => this.focusCancel());
                        document.addEventListener('keydown', this.onKeydown);
                    } else {
                        this.frozen = null;
                        document.removeEventListener('keydown', this.onKeydown);
                        this.restoreFocus();
                    }
                },
            },
        },

        beforeUnmount() {
            document.removeEventListener('keydown', this.onKeydown);
            if (this.open) this.restoreFocus();
        },

        methods: {
            freeze() {
                this.frozen = {
                    title: this.title,
                    message: this.message,
                    confirmLabel: this.confirmLabel,
                    tone: this.tone,
                    selection: snapshot(this.selection),
                };
            },

            focusCancel() {
                const cancel = this.$refs.sheet && this.$refs.sheet.querySelector('[data-sheet-cancel]');

                if (cancel) cancel.focus();
            },

            restoreFocus() {
                const opener = this.opener;

                this.opener = null;

                if (opener && typeof opener.focus === 'function' && document.contains(opener)) {
                    this.$nextTick(() => opener.focus());
                }
            },

            onKeydown(event) {
                if (event.key === 'Escape' && !this.busy) {
                    event.preventDefault();
                    this.cancel();
                }

                if (event.key === 'Tab' && this.$refs.sheet) {
                    const target = wrapFocus([...this.$refs.sheet.querySelectorAll(FOCUSABLE)], document.activeElement, event.shiftKey);

                    if (target) {
                        event.preventDefault();
                        target.focus();
                    }
                }
            },

            cancel() {
                if (!this.busy) this.$emit('cancel');
            },

            confirm() {
                if (!this.busy && !this.confirmDisabled && this.frozen) {
                    this.$emit('confirm', snapshot(this.frozen.selection));
                }
            },
        },
    };
</script>

<template>
    <teleport to="body">
        <div v-if="open && frozen" class="o-sheet-backdrop" @mousedown.self="cancel">
            <div ref="sheet" class="o-sheet" role="dialog" aria-modal="true" :aria-label="frozen.title">
                <div class="o-sheet__title">{{ frozen.title }}</div>
                <div v-if="frozen.message" class="o-sheet__message">{{ frozen.message }}</div>
                <div v-if="$slots.default" class="o-sheet__details">
                    <slot :selection="frozen.selection" />
                </div>
                <div class="o-sheet__actions">
                    <button type="button" class="o-sheet__cancel" data-sheet-cancel :disabled="busy" @click="cancel">Cancel</button>
                    <o-button size="lg" :variant="frozen.tone === 'danger' ? 'danger-solid' : 'primary'" :busy="busy" :disabled="confirmDisabled" @click="confirm">
                        {{ frozen.confirmLabel }}
                    </o-button>
                </div>
            </div>
        </div>
    </teleport>
</template>

<style scoped>
    .o-sheet-backdrop {
        align-items: flex-start;
        background-color: var(--color-backdrop);
        display: flex;
        inset: 0;
        justify-content: center;
        padding-top: 52px;
        position: fixed;
        z-index: 1060;
    }

    .o-sheet {
        background-color: var(--color-bg);
        border-radius: 0 0 var(--radius-window) var(--radius-window);
        box-shadow: var(--shadow-sheet);
        box-sizing: border-box;
        display: flex;
        flex-direction: column;
        font-family: var(--font-sans);
        gap: 8px;
        max-height: calc(100vh - 104px);
        max-width: calc(100vw - 32px);
        overflow: auto;
        padding: 20px 20px 16px;
        width: 440px;
    }

    .o-sheet__title {
        color: var(--color-text);
        font-size: var(--text-section);
        font-weight: var(--font-weight-semibold);
        letter-spacing: -0.01em;
        line-height: 22px;
    }

    .o-sheet__message {
        color: var(--color-text-secondary);
        font-size: var(--text-table);
        line-height: 18px;
    }

    .o-sheet__details {
        color: var(--color-text);
        font-size: var(--text-table);
        line-height: 18px;
        padding-top: 4px;
    }

    .o-sheet__actions {
        display: flex;
        gap: 8px;
        justify-content: flex-end;
        padding-top: 12px;
    }

    .o-sheet__cancel {
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

    .o-sheet__cancel:hover:not(:disabled) {
        background-color: var(--color-subtle);
    }

    .o-sheet__cancel:focus-visible {
        outline: 2px solid color-mix(in srgb, var(--color-accent) 55%, transparent);
        outline-offset: 1px;
    }
</style>
