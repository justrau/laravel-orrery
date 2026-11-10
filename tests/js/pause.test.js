// Tests of resources/js/support/pause.js.

import {test} from 'node:test';
import assert from 'node:assert/strict';

import {
    CONTINUE_ALL_COMMAND,
    MASKED_NOTE,
    NATIVE_UNKNOWN_NOTE,
    broaderCauses,
    causesText,
    continueWarning,
    gaugePause,
    isRecordFailed,
    issuedCause,
    pauseCommands,
    pauseLabel,
    pauseNotes,
    pauseState,
    queueContinueApplies,
    recordFailedNote,
    recordFailedWarning,
} from '../../resources/js/support/pause.js';

const cause = (scope, source = 'orrery', extra = {}) => ({scope, source, issued_at: 10, issued_by: 'ops', possible: false, ...extra});
const row = extra => ({pauses: [], paused_unknown: false, queue_cause_unknown: false, native_unknown: false, ...extra});

// Rejects: a label that names only one of several causes, and "Paused" for Laravel's own pause.
test('pauseLabel names every definite cause', () => {
    assert.equal(pauseLabel(pauseState(row({pauses: [cause('queue')]}))), 'Paused');
    assert.equal(pauseLabel(pauseState(row({pauses: [cause('connection')]}))), 'Paused (connection)');
    assert.equal(pauseLabel(pauseState(row({pauses: [cause('global')]}))), 'Paused (all queues)');
    assert.equal(pauseLabel(pauseState(row({pauses: [cause('queue', 'queue:pause')]}))), 'Paused (queue:pause)');
    assert.equal(pauseLabel(pauseState(row({pauses: [cause('global', 'queue:pause --all')]}))), 'Paused (queue:pause --all)');
    assert.equal(pauseLabel(pauseState(row({pauses: [cause('global'), cause('connection'), cause('queue')]}))), 'Paused (all queues, connection, queue)');
    // The queue's own Orrery pause is never dropped beside a broader cause.
    assert.equal(pauseLabel(pauseState(row({pauses: [cause('connection'), cause('queue')]}))), 'Paused (connection, queue)');
    assert.equal(pauseLabel(pauseState(row())), null);
});

// Rejects: a possible cause read as a pause, and a state known off while a part is unknown.
test('pauseState: a possible cause is no pause, and unknown parts are never known off', () => {
    const possible = pauseState(row({pauses: [cause('queue', 'orrery', {possible: true})], native_unknown: true}));

    assert.equal(possible.paused, false);
    assert.equal(possible.knownOff, false);
    assert.equal(pauseLabel(possible), 'Possibly paused');
    assert.equal(pauseLabel(pauseState(row({pauses: [cause('queue', 'orrery', {possible: true})], queue_cause_unknown: true}))), 'Possibly paused (queue:pause)');

    assert.equal(pauseState(row()).knownOff, true);
    assert.equal(pauseState(row({paused_unknown: true})).knownOff, false);
    assert.equal(pauseState(row({queue_cause_unknown: true})).knownOff, false);
    assert.equal(pauseState(row({native_unknown: true})).knownOff, false);
    assert.equal(pauseState({}).knownOff, false, 'a row without a pauses list is unknown');
    assert.equal(pauseState(null).knownOff, false);
});

// Rejects: no note for a masked own cause or an unread Laravel state, and a missing continue command.
test('pauseNotes and pauseCommands name what is unknown and what Orrery cannot lift', () => {
    const masked = pauseState(row({pauses: [cause('global', 'queue:pause --all'), cause('queue', 'orrery', {possible: true})], queue_cause_unknown: true}));

    assert.deepEqual(pauseNotes(masked), ['possibly paused (queue:pause)', MASKED_NOTE]);
    assert.equal(MASKED_NOTE, "Own pause state unknown while Laravel's global pause is on");
    assert.deepEqual(pauseCommands(masked), [CONTINUE_ALL_COMMAND]);
    assert.equal(CONTINUE_ALL_COMMAND, 'php artisan queue:continue --all');

    const unread = pauseState(row({native_unknown: true}));
    assert.deepEqual(pauseNotes(unread), [NATIVE_UNKNOWN_NOTE]);
    assert.equal(NATIVE_UNKNOWN_NOTE, "Laravel's pause state unknown; a queue:continue run from the command line is not seen");
    assert.deepEqual(pauseCommands(unread), []);
    assert.deepEqual(pauseNotes(pauseState(row({paused_unknown: true}))), ['pause state unknown']);
});

// Rejects: a queue Continue offered under a broader pause alone (it lifts nothing), and hidden for a queue cause or an unknown state.
test('queueContinueApplies, broaderCauses and causesText', () => {
    assert.equal(queueContinueApplies(pauseState(row({pauses: [cause('queue', 'queue:pause')]}))), true);
    assert.equal(queueContinueApplies(pauseState(row({pauses: [cause('queue', 'orrery', {possible: true})]}))), true);
    assert.equal(queueContinueApplies(pauseState(row({pauses: [cause('global')]}))), false);
    assert.equal(queueContinueApplies(pauseState(row({paused_unknown: true}))), true);

    const all = pauseState(row({pauses: [cause('global'), cause('global', 'queue:pause --all'), cause('connection'), cause('queue')]}));
    assert.deepEqual(broaderCauses(all).map(entry => [entry.scope, entry.source]), [['global', 'orrery'], ['global', 'queue:pause --all'], ['connection', 'orrery']]);
    assert.equal(causesText(broaderCauses(all)), "the pause of all queues, Laravel's global pause (queue:continue --all) and the pause of the connection");
});

// Rejects: an actor taken from Laravel's pause (it has none) before Orrery's, and no actor for a possible pause alone.
test('issuedCause takes Orrery\'s definite cause first, then a possible one', () => {
    const both = pauseState(row({pauses: [cause('global', 'queue:pause --all', {issued_by: null}), cause('connection', 'orrery', {issued_by: 'a'})]}));
    assert.deepEqual([issuedCause(both).scope, issuedCause(both).issuedBy], ['connection', 'a']);

    const possible = pauseState(row({pauses: [cause('queue', 'orrery', {possible: true, issued_by: 'b'})]}));
    assert.equal(issuedCause(possible).issuedBy, 'b');
    assert.equal(issuedCause(pauseState(row())), null);
    assert.equal(issuedCause(null), null);
});

// Rejects: a gauge without the actor of Orrery's pause, and a gauge for a possible pause.
test('gaugePause draws the first definite cause with its actor', () => {
    assert.deepEqual(gaugePause(pauseState(row({pauses: [cause('connection')]}))), {scope: 'connection', issued_at: 10, issued_by: 'ops'});
    assert.deepEqual(gaugePause(pauseState(row({pauses: [cause('queue', 'queue:pause', {issued_at: null, issued_by: null})]}))), {scope: 'queue', issued_at: null, issued_by: 'queue:pause'});
    assert.equal(gaugePause(pauseState(row({pauses: [cause('queue', 'orrery', {possible: true})]}))), null);
});

// Rejects: a continue called a success while a broader pause or Laravel's pause still holds the target, an answer without
// `still_paused_by` read as running again, and a warning that does not name the cause.
// Still passes: a cause list whose words are wrong for a scope that the server never sends.
test('continueWarning words a continue from the answers', () => {
    const body = {action: 'continue', scope: 'global'};
    const target = () => 'all queues';

    assert.equal(continueWarning('Continue all queues', [{body, data: {still_paused_by: [], still_paused_unknown: false}}], target), null);
    assert.deepEqual(continueWarning('Continue all queues', [{body, data: {still_paused_by: [{scope: 'global', value: '*', source: 'queue:pause --all'}]}}], target), {
        tone: 'warning',
        title: 'Continue all queues: still paused.',
        detail: "all queues stays paused by Laravel's global pause (queue:continue --all).",
    });
    assert.deepEqual(continueWarning('Continue all queues', [{body, data: {}}], target), {
        tone: 'warning',
        title: 'Continue all queues: sent; the pause state is unknown.',
        detail: "Laravel's pause state of all queues could not be read: it may still be paused.",
    });
    assert.equal(continueWarning('Continue all queues', [{body, data: {still_paused_by: [], still_paused_unknown: true}}], target).tone, 'warning');
    assert.equal(continueWarning('Continue all queues', [{body, data: null}], target).tone, 'warning', 'no body is unknown');
});

// Rejects: a 503 `record_failed` (Laravel's pause changed, only Orrery's record failed) read as a failed signal,
// a warning that ignores `paused`, and a missing `paused` read as running or paused.
// Still passes: a warning that names the targets in another order than they were sent.
test('recordFailedWarning words a change whose Orrery record failed', () => {
    const body = {action: 'pause', scope: 'queue', connection: 'database', queue: 'emails'};
    const target = answer => `${answer.connection}:${answer.queue}`;
    const recorded = paused => ({body, failed: true, status: 503, data: {error: 'record_failed', paused}});

    assert.equal(isRecordFailed(recorded(true)), true);
    assert.equal(isRecordFailed({body, failed: true, status: 503, data: {error: 'write_failed'}}), false);
    assert.equal(isRecordFailed({body, data: {error: 'record_failed'}}), false, 'only a failed answer');
    assert.equal(recordFailedWarning('Pause emails', [{body, data: {}}], target), null);
    assert.equal(recordFailedNote([{body, failed: true, status: 500, data: {}}], target), null);

    assert.deepEqual(recordFailedWarning('Pause emails', [recorded(true)], target), {
        tone: 'warning',
        title: "Pause emails: done; Orrery's record of it was not saved.",
        detail: "database:emails is paused. Orrery could not save its record of this change, so the dashboard may not show who made it. Reload to see the current state.",
    });
    assert.equal(recordFailedWarning('Continue emails', [recorded(false)], target).detail.split('. Orrery')[0],
        "Laravel's queue pause of database:emails is lifted; whether another pause still holds it is unknown");
    assert.equal(recordFailedWarning('Pause emails', [recorded(undefined)], target).detail.split('. Orrery')[0],
        'The pause state of database:emails is unknown');
});
