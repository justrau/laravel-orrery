// Tests of resources/js/support/suggest.js: the rules of the filter bar's
// suggestion combobox (Class and Tag).

import {test} from 'node:test';
import assert from 'node:assert/strict';

import {SUGGEST_DEBOUNCE_MS, comboKey, suggestionItems, suggestionRequest} from '../../resources/js/support/suggest.js';

// Rejects: a debounce so long that the list lags behind typing, or none (one request per key press).
// Still passes: another value in the band.
test('the requests wait a short moment after typing', () => {
    assert.ok(SUGGEST_DEBOUNCE_MS >= 100 && SUGGEST_DEBOUNCE_MS <= 400);
});

// Rejects: a class shown only by its full name (hard to scan) or only by its short name (two classes with one
// short name look the same), a selection that fills the short name (the filter is byte-exact), a body of the
// wrong shape read as "no suggestions" instead of a failed read, or duplicate keys.
// Still passes: another secondary text for a class without a namespace (null is used).
test('suggestionItems: the short class name first, the full name second, and the value to fill', () => {
    const data = {values: [
        {value: 'App\\Jobs\\SendInvoice', monitored: false},
        {value: 'Closure', monitored: false},
        {value: 'App\\Jobs\\', monitored: false},
    ]};

    assert.deepEqual(suggestionItems(data, 'class'), [
        {key: 'App\\Jobs\\SendInvoice', value: 'App\\Jobs\\SendInvoice', primary: 'SendInvoice', secondary: 'App\\Jobs\\SendInvoice', monitored: false},
        {key: 'Closure', value: 'Closure', primary: 'Closure', secondary: null, monitored: false},
        {key: 'App\\Jobs\\', value: 'App\\Jobs\\', primary: 'App\\Jobs\\', secondary: null, monitored: false},
    ]);

    assert.deepEqual(suggestionItems({values: [{value: 'tenant:7', monitored: true}, {value: 'a', monitored: 'yes'}]}, 'tag'), [
        {key: 'tenant:7', value: 'tenant:7', primary: 'tenant:7', secondary: null, monitored: true},
        {key: 'a', value: 'a', primary: 'a', secondary: null, monitored: false},
    ]);

    // A row that is not a text is dropped; a body without a list is a failed read.
    assert.deepEqual(suggestionItems({values: [{value: 3}, null, {value: ''}]}, 'tag'), []);
    assert.equal(suggestionItems({}, 'tag'), null);
    assert.equal(suggestionItems(null, 'class'), null);
    assert.equal(suggestionItems({values: [{value: 'x'}, {value: 'x'}]}, 'tag').length, 1);
});

// Rejects: a request without the field, a query that is trimmed or changed (the server matches it), or one for a
// text the server refuses (more than 255 characters: no request, no 422 alert).
// Still passes: a request with the parameters in another order.
test('suggestionRequest names the field and the typed text, or nothing for a text that is too long', () => {
    assert.deepEqual(suggestionRequest('class', ' App\\Jobs '), {url: '/api/suggestions', params: {field: 'class', q: ' App\\Jobs '}});
    assert.deepEqual(suggestionRequest('tag', ''), {url: '/api/suggestions', params: {field: 'tag', q: ''}});
    assert.equal(suggestionRequest('tag', 'a'.repeat(256)), null);
    assert.equal(suggestionRequest('queue', 'a'), null);
});

// Rejects: the ARIA combobox keys done wrong: Down that does not open a closed list or move to the first option,
// Up that does not reach the last, no wrap, Enter that selects without an active option (it must commit the typed
// text instead), Escape that does not close an open list or that is taken from a closed one (the filter panel
// closes on it), Tab that keeps the list open.
// Still passes: a list that keeps its active option when it closes (the component resets it on open).
test('comboKey decides each key of the combobox', () => {
    const open = {open: true, active: null, count: 3};

    assert.deepEqual(comboKey({open: false, active: null, count: 3}, 'ArrowDown'), {open: true, active: 0, prevent: true, select: null});
    assert.deepEqual(comboKey(open, 'ArrowDown'), {open: true, active: 0, prevent: true, select: null});
    assert.deepEqual(comboKey({...open, active: 2}, 'ArrowDown'), {open: true, active: 0, prevent: true, select: null});
    assert.deepEqual(comboKey(open, 'ArrowUp'), {open: true, active: 2, prevent: true, select: null});
    assert.deepEqual(comboKey({...open, active: 0}, 'ArrowUp'), {open: true, active: 2, prevent: true, select: null});
    assert.deepEqual(comboKey({...open, active: 1}, 'Enter'), {open: false, active: null, prevent: true, select: 1});
    assert.deepEqual(comboKey(open, 'Enter'), {open: false, active: null, prevent: false, select: null});
    assert.deepEqual(comboKey({...open, active: 1}, 'Escape'), {open: false, active: null, prevent: true, select: null});
    assert.deepEqual(comboKey({open: false, active: null, count: 3}, 'Escape'), null);
    assert.deepEqual(comboKey({...open, active: 1}, 'Tab'), {open: false, active: null, prevent: false, select: null});
    assert.deepEqual(comboKey({open: false, active: null, count: 0}, 'ArrowDown'), null);
    assert.equal(comboKey(open, 'a'), null);
});
