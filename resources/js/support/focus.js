/**
 * The focus rules of the dialogs (AlertHost, ConfirmSheet):
 * Tab stays inside an open dialog, and Escape returns the focus to the
 * control that opened it (the components keep that control).
 */

/** The elements that Tab reaches. */
export const FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

/**
 * The element that Tab (or Shift+Tab with `backwards`) must move the focus
 * to so that it stays among `items`, or null when the browser's own move
 * stays inside. Focus outside the items goes to the first item (the last
 * one backwards).
 */
export function wrapFocus(items, active, backwards) {
    if (!Array.isArray(items) || items.length === 0) return null;

    const first = items[0];
    const last = items[items.length - 1];
    const index = items.indexOf(active);

    if (index === -1) return backwards ? last : first;
    if (backwards && active === first) return last;
    if (!backwards && active === last) return first;

    return null;
}

/**
 * The open modal layers that own Escape before the shell's drawer and the
 * inspector: a confirmation sheet (ConfirmSheet, BulkRun) and the alert
 * (AlertHost, `role="alertdialog"`, above every sheet).
 */
export const MODAL_SELECTOR = '[role="dialog"][aria-modal="true"], [role="alertdialog"]';

/**
 * Whether a modal layer (MODAL_SELECTOR) other than `own` is open in `root`
 * (the document); false without one. `own` is the caller's own layer: the
 * shell's drawer is a modal dialog itself while it is open.
 */
export function modalOpen(root, own = null) {
    if (!root || typeof root.querySelectorAll !== 'function') return false;

    return [...root.querySelectorAll(MODAL_SELECTOR)].some(element => element !== own);
}
