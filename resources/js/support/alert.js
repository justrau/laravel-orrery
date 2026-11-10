/**
 * The rules of the global alert host (components/ui/AlertHost.vue). The
 * root data keeps the shape that errors.js and the screens write: `alert`
 * {type, autoClose, message, confirmationProceed, confirmationCancel} and
 * `lastDismissedAlert` {message, at}. Pure helpers, so Node can test them.
 */

/** The alert types that errors.js and the screens set. */
export const ALERT_TYPES = Object.freeze(['error', 'reload', 'success', 'confirmation']);

const BUTTONS = {
    error: [{action: 'close', label: 'Close', variant: 'primary'}],
    reload: [{action: 'close', label: 'Close', variant: 'secondary'}, {action: 'confirm', label: 'Reload page', variant: 'primary'}],
    success: [{action: 'close', label: 'Okay', variant: 'primary'}],
    confirmation: [{action: 'cancel', label: 'Cancel', variant: 'secondary'}, {action: 'confirm', label: 'Yes', variant: 'danger'}],
};

/**
 * The buttons of an alert, in their visual order (the main one last). An
 * unknown type still gets a Close button: an alert that cannot be closed
 * would block the page.
 */
export function alertButtons(type) {
    const buttons = Object.prototype.hasOwnProperty.call(BUTTONS, type) ? BUTTONS[type] : [{action: 'close', label: 'Close', variant: 'primary'}];

    return buttons.map(button => ({...button}));
}

/**
 * The action of the button that gets the focus when the alert opens (the
 * Return key). A confirmation defaults to Cancel: Return never confirms a
 * destructive question.
 */
export function defaultAction(type) {
    if (type === 'confirmation') return 'cancel';
    if (type === 'reload') return 'confirm';

    return 'close';
}

/** The action of the Escape key: it never confirms, and never reloads. */
export function escapeAction(type) {
    return type === 'confirmation' ? 'cancel' : 'close';
}

/** The record of a dismissed message, which errors.js reads (REPEAT_AFTER_MS). */
export function dismissedAlert(message, now) {
    return {message, at: now};
}

/** The cleared alert: no type, no message, no callbacks. */
export function emptyAlert() {
    return {type: null, autoClose: 0, message: '', confirmationProceed: null, confirmationCancel: null};
}
