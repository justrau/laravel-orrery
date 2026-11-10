// Tests of resources/js/support/storage.js.

import {test} from 'node:test';
import assert from 'node:assert/strict';

import {readStored, removeStored, scopedKey, writeStored} from '../../resources/js/support/storage.js';

const throwing = {
    getItem() { throw new Error('SecurityError'); },
    setItem() { throw new Error('QuotaExceededError'); },
    removeItem() { throw new Error('SecurityError'); },
};

function memory() {
    const values = new Map();

    return {
        getItem: key => (values.has(key) ? values.get(key) : null),
        setItem: (key, value) => values.set(key, String(value)),
        removeItem: key => values.delete(key),
    };
}

// Rejects: a read of localStorage that throws into the page (blocked site data, a private window, a sandbox
// without storage), which stops the SPA from mounting, and a missing storage read as a stored value.
test('readStored gives null when the storage cannot be read', () => {
    const storage = memory();

    storage.setItem('k', '1');
    assert.equal(readStored('k', storage), '1');
    assert.equal(readStored('missing', storage), null);
    assert.equal(readStored('k', throwing), null);
    assert.equal(readStored('k', null), null);
});

// Rejects: a write that throws into the click handler, and a write that does not store the text.
test('writeStored keeps going when the storage cannot be written', () => {
    const storage = memory();

    assert.equal(writeStored('k', 1, storage), true);
    assert.equal(storage.getItem('k'), '1');
    assert.equal(writeStored('k', 1, throwing), false);
    assert.equal(writeStored('k', 1, null), false);
});

// Rejects (nit): stored keys without the dashboard's base path, so two dashboards on one origin read and delete each
// other's stored run; and a remove that throws into the page.
// Still passes: two dashboards with one base path on one origin (they are one dashboard to the browser).
test('the stored keys are scoped by the base path', () => {
    const storage = memory();

    assert.equal(scopedKey('orreryBulkRun', '/admin/orrery'), 'orreryBulkRun@/admin/orrery');
    assert.equal(scopedKey('orreryBulkRun', ''), 'orreryBulkRun');

    writeStored('k', 'a', storage, '/one');
    writeStored('k', 'b', storage, '/two');
    assert.equal(readStored('k', storage, '/one'), 'a');
    assert.equal(readStored('k', storage, '/two'), 'b');

    assert.equal(removeStored('k', storage, '/one'), true);
    assert.equal(readStored('k', storage, '/one'), null);
    assert.equal(readStored('k', storage, '/two'), 'b');
    assert.equal(removeStored('k', throwing, '/one'), false);
    assert.equal(removeStored('k', null), false);
});
