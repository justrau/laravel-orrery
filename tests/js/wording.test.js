// Tests of resources/js/support/wording.js.

import {test} from 'node:test';
import assert from 'node:assert/strict';

import {
    AWAITING_REASONS,
    CODES,
    OPERATION_STATES,
    OUTCOMES,
    SOURCES,
    awaitingText,
    codeText,
    fill,
    itemText,
    outcomeLabel,
    outcomeText,
    sourceText,
    stateLabel,
    stateText,
} from '../../resources/js/support/wording.js';

// Rejects: a template filled with "undefined" or "null" or an empty text for a missing value (unknown is never blank), and a fill that reads markup as anything but text.
test('fill puts stored values into a template and a dash for a missing one', () => {
    assert.equal(fill('Run php artisan orrery:resolve {id}.', {id: 'op-1'}), 'Run php artisan orrery:resolve op-1.');
    assert.equal(fill('Run {id}.', {}), 'Run —.');
    assert.equal(fill('Run {id}.', {id: null}), 'Run —.');
    assert.equal(fill('Run {id}.', {id: ''}), 'Run —.');
    assert.equal(fill('Run {id}.', null), 'Run —.');
    assert.equal(fill('{n} of {n}', {n: 0}), '0 of 0', 'zero is a value, not a missing one');
    assert.equal(fill('By {actor}.', {actor: '<b>x</b>'}), 'By <b>x</b>.', 'the text is kept as text; the screen escapes it');
    assert.equal(fill('No placeholders.', {id: 1}), 'No placeholders.');
});

// Rejects: a source table without the 5.6 sources, and an unknown source shown as nothing.
test('sourceText words every history source and treats another as unknown', () => {
    for (const source of ['push', 'native', 'dashboard', 'batch', 'bulk', 'cli', 'unknown']) {
        assert.equal(sourceText(source), SOURCES[source]);
    }

    assert.equal(sourceText('something_new'), SOURCES.unknown);
    assert.equal(sourceText(null), SOURCES.unknown);
    assert.equal(sourceText('constructor'), SOURCES.unknown, 'an Object.prototype name is not a source');
});

// Rejects: an awaiting text without the resolve command and the operation id, and an unknown reason shown as nothing.
test('awaitingText names the resolve command with the operation id', () => {
    assert.equal(
        awaitingText('sqlite_host_file', {id: '9f1c'}),
        "Waiting for an operator: this step writes the application's SQLite file. Run php artisan orrery:resolve 9f1c.",
    );
    assert.match(awaitingText('sqlite_reads_not_allowed', {id: 'a'}), /orrery:resolve a\.$/);
    assert.equal(Object.keys(AWAITING_REASONS).length, 2);
    assert.equal(awaitingText('disk_full', {id: 'x'}), 'Waiting for an operator (disk_full). Run php artisan orrery:resolve x.');
    assert.equal(awaitingText('sqlite_host_file'), "Waiting for an operator: this step writes the application's SQLite file. Run php artisan orrery:resolve —.");
});

// Rejects: an outcome or state table that misses one of the bulk outcomes or ledger states, and an unknown value shown as nothing.
test('outcomeText and stateText word every outcome and state', () => {
    for (const outcome of ['accepted', 'refused', 'uncertain', 'not_member', 'already_done', 'not_confirmed', 'not_eligible', 'failed', 'deleted']) {
        assert.equal(outcomeText(outcome), OUTCOMES[outcome]);
    }

    for (const state of ['claimed', 'published', 'aborting', 'done', 'aborted']) {
        assert.equal(stateText(state), OPERATION_STATES[state]);
    }

    assert.equal(outcomeText('exploded'), 'The server answered exploded.');
    assert.equal(stateText('paused'), 'The operation is paused.');
    assert.equal(outcomeText(undefined), 'The server answered —.');
});

// Rejects: a code table without the 5.6 codes, the spec's frozen_expired wording, an unknown code shown as nothing, and a null code turned into a sentence.
test('codeText words every code of the 5.6 table', () => {
    const table = [
        'provider_read_only', 'ids_may_be_reused', 'occurrence_unprovable', 'fence_missing', 'queue_unreachable', 'mirror_unreachable', 'record_unreachable', 'target_changed', 'schema_outdated', 'app_key_missing',
        // orrery:forget --all: the failed-job storage changed between the count and the run.
        'scope_changed',
        'not_failed', 'occurrence_mismatch', 'operation_in_progress', 'record_missing',
        'prepare_failed', 'claim_failed', 'record_gone', 'record_delete_failed', 'cancelled', 'deadline_passed', 'publish_failed', 'provider_error', 'row_moved', 'restore_conflict', 'restore_failed', 'resolution_failed', 'finalize_failed', 'delete_decision_failed',
        'claim_uncertain', 'publish_uncertain', 'record_delete_uncertain',
        'missing', 'occurrence_changed', 'other_group', 'out_of_scope', 'retry_in_progress', 'retried', 'gone', 'mirror_pending', 'retry_uncertain',
        'frozen_invalid', 'frozen_expired', 'confirmation_mismatch', 'frozen_mismatch', 'total_not_exact', 'too_many', 'config_invalid', 'index_missing',
        // The raw-query routes (RawQuery, QueryTooLong): a query of max_input_vars pairs.
        'query_too_long',
    ];

    for (const code of table) {
        assert.ok(Object.prototype.hasOwnProperty.call(CODES, code), code);
        assert.equal(codeText(code), fill(CODES[code], {}));
    }

    assert.equal(codeText('frozen_expired'), 'This run was idle for more than an hour; start a new one.');
    assert.equal(codeText('too_many', {maximum: 10000}), 'More than 10000 records match, so a forget cannot start. Narrow the selection.');
    assert.equal(codeText('brand_new_code'), 'The server answered with the code brand_new_code.');
    assert.equal(codeText(null), null);
    assert.equal(codeText(undefined), null);
    assert.equal(codeText('toString'), 'The server answered with the code toString.');
});

// Rejects: a template with a placeholder that no caller fills (it would always show "—"), and a template without a closing full stop.
test('every template uses only the known placeholders and ends a sentence', () => {
    // {queue} and {field}: the link filter problems (support/lists.js queryProblem(), jobs.js listFailure()) fill them.
    const known = new Set(['id', 'maximum', 'queue', 'field']);

    for (const table of [SOURCES, AWAITING_REASONS, OUTCOMES, OPERATION_STATES, CODES]) {
        for (const [key, template] of Object.entries(table)) {
            for (const [, name] of template.matchAll(/\{([a-z_]+)\}/g)) {
                assert.ok(known.has(name), `${key} uses {${name}}`);
            }

            assert.match(template, /[.]$/, key);
        }
    }
});

// Rejects: an item text that shows the outcome while an awaiting reason or a code explains more, and one that reads `result` but not `outcome`.
test('itemText prefers the awaiting reason, then the code, then the outcome', () => {
    assert.equal(
        itemText({result: 'uncertain', code: 'awaiting_operator', awaiting_reason: 'sqlite_host_file', operation_id: 'op-7'}),
        "Waiting for an operator: this step writes the application's SQLite file. Run php artisan orrery:resolve op-7.",
    );
    assert.equal(itemText({result: 'not_eligible', code: 'occurrence_mismatch'}), CODES.occurrence_mismatch);
    assert.equal(itemText({outcome: 'not_confirmed', code: null}), OUTCOMES.not_confirmed);
    assert.equal(itemText({result: 'accepted', code: null}), OUTCOMES.accepted);
    assert.equal(itemText({result: 'uncertain', code: 'awaiting_operator', operation_id: 'op-2'}), 'The operation stopped, and its next step needs an operator. Resolve it on the job page, or run php artisan orrery:resolve op-2.');
    assert.equal(itemText(null), null);
});

// Rejects: texts that describe a retry where a forget can return the same code, and the old "another retry was published first" reading of `cancelled` (it is the resolver's cancel: nothing was published).
test('the codes that retry and forget share are worded for both', () => {
    const shared = [
        'cancelled', 'deadline_passed', 'claim_failed', 'claim_uncertain', 'target_changed', 'mirror_unreachable',
        'record_unreachable', 'occurrence_unprovable', 'fence_missing', 'operation_in_progress', 'not_failed', 'occurrence_mismatch',
    ];

    for (const code of shared) {
        assert.doesNotMatch(codeText(code), /\bretry\b|published first/i, code);
    }

    assert.equal(codeText('cancelled'), 'The runner cancelled this operation before it took effect: nothing was published or deleted. Try again.');
    assert.equal(codeText('deadline_passed'), 'The operation did not finish before its deadline: nothing changed. Try again.');
});

// Rejects: a schema text that sends the operator to php artisan migrate (the package schema has its own upgrade steps).
test('schema_outdated points at the upgrade steps', () => {
    assert.equal(codeText('schema_outdated'), "Orrery's tables are older than this version. Follow the upgrade steps in docs/upgrading.md; the health footer shows the command.");
    assert.doesNotMatch(codeText('schema_outdated'), /migrate/);
});

// Rejects: an outcome rendered as its raw code ("not_member"), and an unknown outcome rendered as nothing.
test('outcomeLabel names every outcome in words', () => {
    assert.equal(outcomeLabel('accepted'), 'accepted');
    assert.equal(outcomeLabel('not_member'), 'outside the selection');
    assert.equal(outcomeLabel('already_done'), 'already done');
    assert.equal(outcomeLabel('not_confirmed'), 'not confirmed');
    assert.equal(outcomeLabel('surprise'), 'unknown outcome');
    assert.equal(outcomeLabel(null), 'unknown outcome');
});

// Rejects: an operation state shown by its raw code in a chip, and an unknown state shown as nothing.
test('stateLabel names every operation state in short words', () => {
    assert.equal(stateLabel('claimed'), 'starting');
    assert.equal(stateLabel('published'), 'published');
    assert.equal(stateLabel('aborting'), 'stopping');
    assert.equal(stateLabel('done'), 'done');
    assert.equal(stateLabel('aborted'), 'stopped');
    assert.equal(stateLabel('paused'), 'state unknown');
    assert.equal(stateLabel(null), 'state unknown');
});

// Rejects: a missing text for the screen prompts of a bulk run (leaving during a run, a run that cannot start).
test('the bulk prompts are worded', () => {
    assert.match(codeText('bulk_run_in_progress'), /stops after its current page/);
    assert.match(codeText('no_bulk_members'), /nothing to act on/);
});

// Review nit. Rejects: the leave prompt ("If you leave this screen…") shown when the user tries to start a
// second run on the same screen.
test('a busy bulk run has its own sentence', () => {
    assert.match(codeText('bulk_run_busy'), /already in progress here/);
    assert.doesNotMatch(codeText('bulk_run_busy'), /leave/);
});
