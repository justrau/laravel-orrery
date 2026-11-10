// Tests of resources/js/support/failed-records.js.

import {test} from 'node:test';
import assert from 'node:assert/strict';

import {failedRecordsReadOnly, READ_ONLY_FALLBACK} from '../../resources/js/support/failed-records.js';

const MESSAGE = 'Retry and forget need the database-uuids or null failed-job driver; this application uses Illuminate\\Queue\\Failed\\FileFailedJobProvider. Its failures are shown read-only.';

// Gate fix 1. Rejects: a read-only driver whose Retry and Forget actions stay (the SPA ignores failed_records), a
// read-only state without the server's message, and a read-only state for the sql and none adapters. It still passes
// an SPA that keeps the actions while the health is unknown (the server refuses provider_read_only anyway).
test('failedRecordsReadOnly reads the read-only adapter of the health response', () => {
    assert.deepEqual(failedRecordsReadOnly({failed_records: {adapter: 'read_only', reason: 'provider_read_only', message: MESSAGE}}),
        {reason: 'provider_read_only', message: MESSAGE});
    assert.deepEqual(failedRecordsReadOnly({failed_records: {adapter: 'read_only', reason: 'ids_may_be_reused', message: ''}}),
        {reason: 'ids_may_be_reused', message: READ_ONLY_FALLBACK}, 'a read-only adapter without a message still hides the actions');
    assert.deepEqual(failedRecordsReadOnly({failed_records: {adapter: 'read_only', reason: 7, message: null}}),
        {reason: null, message: READ_ONLY_FALLBACK});

    assert.equal(failedRecordsReadOnly({failed_records: {adapter: 'sql', reason: null, message: null}}), null);
    assert.equal(failedRecordsReadOnly({failed_records: {adapter: 'none', reason: null, message: null}}), null);
    assert.equal(failedRecordsReadOnly({failed_records: null}), null, 'unknown: the server still refuses');
    assert.equal(failedRecordsReadOnly({}), null, 'an older server without the field');
    assert.equal(failedRecordsReadOnly(null), null, 'the health is not loaded yet');
});
