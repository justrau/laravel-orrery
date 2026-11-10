// Tests of resources/js/support/focus.js: the focus rule of the dialogs
// (AlertHost, ConfirmSheet: keyboard focus order).

import {test} from 'node:test';
import assert from 'node:assert/strict';

import {MODAL_SELECTOR, modalOpen, wrapFocus} from '../../resources/js/support/focus.js';

// Rejects: a dialog that lets Tab leave it at either end, one that moves focus inside it (the browser does that), and one that leaves focus outside the dialog.
test('wrapFocus keeps Tab inside the dialog', () => {
    const items = ['cancel', 'input', 'confirm'];

    assert.equal(wrapFocus(items, 'confirm', false), 'cancel');
    assert.equal(wrapFocus(items, 'cancel', true), 'confirm');
    assert.equal(wrapFocus(items, 'input', false), null);
    assert.equal(wrapFocus(items, 'input', true), null);
    assert.equal(wrapFocus(items, 'outside', false), 'cancel');
    assert.equal(wrapFocus(items, 'outside', true), 'confirm');
    assert.equal(wrapFocus([], 'outside', false), null);
    assert.equal(wrapFocus(['only'], 'only', false), 'only');
});

// Rejects: an Escape rule of the shell's drawer or the inspector that closes
// them while a confirmation sheet or the alert (`role="alertdialog"`) is open,
// one that also counts a dialog that is not modal, one that counts the
// caller's own layer (the drawer is a modal dialog itself while it is open,
// so its Escape would never close it), and one that throws without a
// document (Node, an unmounted screen).
test('modalOpen sees an open sheet or alert other than the caller, and nothing else', () => {
    const drawer = {name: 'drawer'};
    const sheet = {name: 'sheet'};
    const root = (...found) => ({querySelectorAll: selector => (selector === MODAL_SELECTOR ? found : [])});

    assert.equal(modalOpen(root(sheet)), true);
    assert.equal(modalOpen(root()), false);
    assert.equal(modalOpen(root(drawer), drawer), false);
    assert.equal(modalOpen(root(drawer, sheet), drawer), true);
    assert.equal(modalOpen(root(drawer)), true);
    assert.equal(modalOpen(null), false);
    assert.equal(modalOpen({}), false);

    assert.match(MODAL_SELECTOR, /\[role="alertdialog"\]/);
    assert.match(MODAL_SELECTOR, /\[role="dialog"\]\[aria-modal="true"\]/);
    assert.doesNotMatch(MODAL_SELECTOR, /(^|, )\[role="dialog"\](,|$)/);
});
