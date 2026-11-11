// Tests of resources/js/support/prefetch.js: the cache of the reads that a
// held navigation starts for the next screen, consumed once by the
// screen's own first GET.

import {test} from 'node:test';
import assert from 'node:assert/strict';

import {PREFETCH_TTL_MS, TIME_TOLERANCE_SECONDS, createPrefetchCache, paramsMatch} from '../../resources/js/support/prefetch.js';

const settle = () => new Promise(resolve => setImmediate(resolve));

function clock(start = 1000) {
    let now = start;

    return {now: () => now, add: ms => { now += ms; }};
}

// Rejects: params that match with a missing or extra key, a value of another text, or a time value outside the
// tolerance; a number and its text that do not match (a query sends both as text).
// Still passes: a tolerance a little wider than TIME_TOLERANCE_SECONDS (the bound is the constant).
test('paramsMatch compares every key as text, and the time keys within the tolerance', () => {
    assert.equal(paramsMatch({a: 1, b: 'x'}, {b: 'x', a: '1'}, []), true);
    assert.equal(paramsMatch({a: 1}, {a: 1, b: 2}, []), false);
    assert.equal(paramsMatch({a: 1, b: 2}, {a: 1}, []), false);
    assert.equal(paramsMatch({a: 'x'}, {a: 'y'}, []), false);
    assert.equal(paramsMatch({}, undefined, []), true);
    assert.equal(paramsMatch({null: null}, {}, []), true, 'a null value is not sent, as queryUrl() leaves it out');
    assert.equal(paramsMatch({to: 1000, q: 'a'}, {to: 1000 + TIME_TOLERANCE_SECONDS, q: 'a'}, ['to']), true);
    assert.equal(paramsMatch({to: 1000}, {to: 1001 + TIME_TOLERANCE_SECONDS}, ['to']), false);
    assert.equal(paramsMatch({to: 1000}, {to: 1001}, []), false, 'only the declared keys have a tolerance');
});

// Rejects: a cache that gives one prefetched answer twice (the second read would show old data as a poll), that
// matches another path or a write, or that keeps an answer longer than the TTL after it settled.
// Still passes: a cache that keeps an answer a few ms past the TTL (the fake clock is exact).
test('a prefetched GET is taken once, by the same path and params, within the TTL after it settles', async () => {
    const time = clock();
    const cache = createPrefetchCache({now: time.now});
    const answer = Promise.resolve({data: 1});

    const entry = cache.put('/orrery/api/jobs', {status: 'failed'}, [], answer);

    cache.arm([entry]);

    assert.equal(cache.take('post', '/orrery/api/jobs', {status: 'failed'}), null, 'never for a write');
    assert.equal(cache.take('get', '/orrery/api/jobs', {status: 'pending'}), null);
    assert.equal(cache.take('get', '/orrery/api/batches', {status: 'failed'}), null);
    assert.equal(cache.take('get', '/orrery/api/jobs', {status: 'failed'}), answer);
    assert.equal(cache.take('get', '/orrery/api/jobs', {status: 'failed'}), null, 'consumed once');

    const late = Promise.resolve({data: 2});

    cache.arm([cache.put('/orrery/api/jobs', {}, [], late)]);
    await settle();
    time.add(PREFETCH_TTL_MS + 1);
    assert.equal(cache.take('get', '/orrery/api/jobs', {}), null, 'expired after it settled');
});

// Rejects: an in-flight answer that expires before it settles (the screen would fetch it again, twice in total).
// Still passes: a cache that also keeps a settled answer slightly longer.
test('an answer in flight stays until it settles', async () => {
    const time = clock();
    const cache = createPrefetchCache({now: time.now});
    let resolve;
    const pending = new Promise(yes => {
        resolve = yes;
    });

    cache.arm([cache.put('/orrery/api/workload', {}, [], pending)]);
    time.add(PREFETCH_TTL_MS * 5);
    assert.equal(cache.take('get', '/orrery/api/workload', {}), pending);
    resolve({data: []});
});

// Rejects: a failed prefetch that the screen reuses (its error went through the prefetch's handles, not the
// screen's, so the screen must read again and show its own error).
// Still passes: a cache that drops the failed entry one tick later.
test('a failed prefetch is dropped', async () => {
    const cache = createPrefetchCache();
    const failed = Promise.reject(new Error('503'));

    failed.catch(() => {});
    cache.arm([cache.put('/orrery/api/control', {}, [], failed)]);
    await settle();
    assert.equal(cache.take('get', '/orrery/api/control', {}), null);
});

// Rejects: a discard that leaves an entry (a cancelled navigation's answer would fill a later screen) or that
// does not abort its request.
// Still passes: a discard that aborts a request that already settled (no effect).
test('discard removes the entries of a cancelled navigation and aborts them', () => {
    const cache = createPrefetchCache();
    let aborted = 0;
    const entry = cache.put('/orrery/api/tags', {}, [], new Promise(() => {}), () => aborted++);
    const other = cache.put('/orrery/api/batches', {}, [], new Promise(() => {}));

    cache.arm([entry, other]);

    cache.discard([entry]);
    assert.equal(aborted, 1);
    assert.equal(cache.take('get', '/orrery/api/tags', {}), null);
    assert.notEqual(cache.take('get', '/orrery/api/batches', {}), null);
    assert.ok(other);
});

// Rejects: an entry that a request can take before its navigation committed (the old screen's poll or the shell's
// own poll of the same read would take the next screen's prefetch).
// Still passes: an arm that also restarts the TTL (not observed).
test('an entry can be taken only after it is armed', () => {
    const cache = createPrefetchCache();
    const entry = cache.put('/orrery/api/batches', {}, [], new Promise(() => {}));

    assert.equal(cache.take('get', '/orrery/api/batches', {}), null, 'not before the commit');
    cache.arm([entry]);
    assert.equal(cache.take('get', '/orrery/api/batches', {}), entry.promise);
});

// Rejects: a second prefetch of the same read that leaves the older untaken one in place (the screen would take
// the older answer), or that does not abort it.
test('a new prefetch of the same read replaces the untaken older one', () => {
    const cache = createPrefetchCache();
    let aborted = 0;
    const older = cache.put('/orrery/api/tags', {tag: 'a'}, [], new Promise(() => {}), () => aborted++);
    const newer = cache.put('/orrery/api/tags', {tag: 'a'}, [], new Promise(() => {}));

    cache.arm([older, newer]);
    assert.equal(aborted, 1);
    assert.equal(cache.take('get', '/orrery/api/tags', {tag: 'a'}), newer.promise);
    assert.equal(cache.take('get', '/orrery/api/tags', {tag: 'a'}), null);
});
