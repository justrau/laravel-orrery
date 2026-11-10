// Tests of resources/js/support/alert.js: the rules of the global alert host
// (components/ui/AlertHost.vue, transition rule D-1).

import {test} from 'node:test';
import assert from 'node:assert/strict';

import {
    ALERT_TYPES,
    alertButtons,
    defaultAction,
    dismissedAlert,
    emptyAlert,
    escapeAction,
} from '../../resources/js/support/alert.js';

// Rejects: a host that drops a type that errors.js or a legacy screen sets (the alert would never show), or that offers no way to close one.
test('alertButtons gives every alert type a way to close it', () => {
    assert.deepEqual([...ALERT_TYPES], ['error', 'reload', 'success', 'confirmation']);

    for (const type of ALERT_TYPES) {
        const actions = alertButtons(type).map(button => button.action);

        assert.ok(actions.includes('close') || actions.includes('cancel'), type);
    }

    assert.deepEqual(alertButtons('error').map(button => button.label), ['Close']);
    assert.deepEqual(alertButtons('reload').map(button => [button.action, button.label]), [['close', 'Close'], ['confirm', 'Reload page']]);
    assert.deepEqual(alertButtons('success').map(button => button.label), ['Okay']);
    assert.deepEqual(alertButtons('confirmation').map(button => [button.action, button.label, button.variant]), [['cancel', 'Cancel', 'secondary'], ['confirm', 'Yes', 'danger']]);
});

// Rejects: an unknown type that renders no button, so the dialog cannot be closed.
test('alertButtons closes an unknown type', () => {
    assert.deepEqual(alertButtons('surprise').map(button => button.action), ['close']);
    assert.deepEqual(alertButtons(null).map(button => button.action), ['close']);
});

// Rejects: a confirmation whose default (focused) button is "Yes", so Return confirms a destructive action, and a reload alert whose Escape reloads the page.
test('the default and the Escape action never confirm a confirmation', () => {
    assert.equal(defaultAction('confirmation'), 'cancel');
    assert.equal(defaultAction('reload'), 'confirm');
    assert.equal(defaultAction('error'), 'close');
    assert.equal(defaultAction('success'), 'close');

    assert.equal(escapeAction('confirmation'), 'cancel');
    assert.equal(escapeAction('reload'), 'close');
    assert.equal(escapeAction('error'), 'close');
});

// Rejects: a dismissal that is not recorded with its message and time (errors.js would show a repeating poll failure again at once), and a cleared alert that keeps a callback of the old one.
test('dismissedAlert and emptyAlert keep the shape that errors.js reads', () => {
    assert.deepEqual(dismissedAlert('The server could not be reached.', 1000), {message: 'The server could not be reached.', at: 1000});
    assert.deepEqual(emptyAlert(), {type: null, autoClose: 0, message: '', confirmationProceed: null, confirmationCancel: null});
    assert.notEqual(emptyAlert(), emptyAlert());
});
