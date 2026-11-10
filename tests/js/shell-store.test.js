// Tests of resources/js/shell/store.js.

import {storage} from './setup/store-window.js';

import {test} from 'node:test';
import assert from 'node:assert/strict';
import {isReactive} from 'vue';

import {enterTerminal, resetTerminalForTesting} from '../../resources/js/errors.js';
import {
    closeNav,
    closeOverlay,
    inspectorVisible,
    live,
    liveFailed,
    liveWatcherHealth,
    requestLiveRefresh,
    resetToolbar,
    revealInspector,
    setLiveRefresh,
    setNarrow,
    setPhone,
    setToolbar,
    shell,
    toggleInspector,
    toggleNav,
} from '../../resources/js/shell/store.js';

const NOW = 1790000000;

function wide() {
    setNarrow(false);
    setPhone(false);
}

// Rejects: a store that is plain objects (the toolbar and the screens would not update), and an inspector
// that starts open although the viewer closed it under this base path.
test('the store is reactive and starts with the stored inspector choice', () => {
    assert.equal(isReactive(shell), true);
    assert.equal(isReactive(live), true);
    assert.equal(shell.inspectorOpen, false, 'the stored "0" under the base path');
    for (const part of ['workload', 'workers', 'health', 'groupsHour', 'counts', 'batches', 'at']) {
        assert.equal(live[part], null, `${part} is unknown before its first read, never empty`);
    }
});

// Rejects: a toolbar that keeps the text of the previous screen, and a setToolbar() without arguments that
// keeps the old text.
test('setToolbar and resetToolbar set and clear the toolbar text', () => {
    setToolbar({title: 'Jobs', subtitle: '3 queues'});
    assert.equal(shell.title, 'Jobs');
    assert.equal(shell.subtitle, '3 queues');

    setToolbar({title: 'Failed'});
    assert.equal(shell.subtitle, null);

    setToolbar({title: 'Failed', subtitle: 'x'});
    setToolbar();
    assert.equal(shell.title, null);
    assert.equal(shell.subtitle, null);

    setToolbar({title: 'Jobs', subtitle: 'y'});
    resetToolbar();
    assert.equal(shell.title, null);
    assert.equal(shell.subtitle, null);
});

// Rejects: "Stale" that ignores one of the live parts, and "Stale" for a failed slow read (counts, batches,
// the failure groups), which the toolbar does not cover.
test('liveFailed covers the workload, the workers and the health only', () => {
    assert.equal(liveFailed(), false);

    for (const part of ['workloadError', 'workersError', 'healthError']) {
        live[part] = true;
        assert.equal(liveFailed(), true, part);
        live[part] = false;
    }

    live.countsError = true;
    live.batchesError = true;
    live.groupsHourError = true;
    assert.equal(liveFailed(), false);
    live.countsError = false;
    live.batchesError = false;
    live.groupsHourError = false;
});

// Rejects: a footer health before the first read, a failed health read shown as healthy, and a page that
// left its polls whose footer still computes from the last data.
test('liveWatcherHealth follows the live data and the terminal state', () => {
    assert.equal(liveWatcherHealth(NOW), null);

    live.health = {
        tick: {observer_tick_at: NOW - 10},
        windows: [],
        missing_fence_tables: [],
        retention: {state: 'ok', at: NOW - 30, deleted: 0, behind: false, lag_seconds: null, partitions_unchecked: 0},
    };
    live.workers = [];
    assert.equal(liveWatcherHealth(NOW).title, 'Watcher healthy');

    live.healthError = true;
    assert.equal(liveWatcherHealth(NOW).tone, 'unknown');
    live.healthError = false;

    enterTerminal('expired');
    try {
        const stopped = liveWatcherHealth(NOW);
        assert.equal(stopped.tone, 'unknown');
        assert.equal(stopped.tickAge, null);
    } finally {
        resetTerminalForTesting();
        live.health = null;
        live.workers = null;
    }
});

// Rejects: a refresh request that throws without a shell, a refresh that is not the registered one, and a
// cleared registration that still refreshes (after AppShell unmounts).
test('requestLiveRefresh calls the registered refresh only', () => {
    requestLiveRefresh();

    let calls = 0;
    setLiveRefresh(() => { calls += 1; });
    requestLiveRefresh();
    requestLiveRefresh();
    assert.equal(calls, 2);

    setLiveRefresh(null);
    requestLiveRefresh();
    setLiveRefresh('not a function');
    requestLiveRefresh();
    assert.equal(calls, 2);
});

// Rejects: a layout change that leaves the drawer or the overlay open, a truthy value taken as true, and a
// drawer that opens in the wide layout.
test('setNarrow, setPhone, toggleNav and closeNav keep the drawer to the narrow layout', () => {
    setNarrow(true);
    toggleNav();
    assert.equal(shell.navOpen, true);
    toggleNav();
    assert.equal(shell.navOpen, false);
    toggleNav();
    closeNav();
    assert.equal(shell.navOpen, false);

    toggleNav();
    revealInspector();
    setNarrow(false);
    assert.equal(shell.narrow, false);
    assert.equal(shell.navOpen, false);
    assert.equal(shell.overlayOpen, false);

    toggleNav();
    assert.equal(shell.navOpen, false, 'no drawer in the wide layout');

    setNarrow('yes');
    assert.equal(shell.narrow, false);

    setPhone(true);
    assert.equal(shell.phone, true);
    setPhone(1);
    assert.equal(shell.phone, false);
    wide();
});

// Rejects: a selection that opens the overlay in the wide layout (or changes the viewer's kept choice), and
// an overlay that a selection does not open in the narrow layout.
test('revealInspector and closeOverlay open and close the overlay in the narrow layout only', () => {
    wide();
    const kept = shell.inspectorOpen;
    revealInspector();
    assert.equal(shell.overlayOpen, false);
    assert.equal(shell.inspectorOpen, kept);

    setNarrow(true);
    revealInspector();
    assert.equal(shell.overlayOpen, true);
    assert.equal(inspectorVisible(), true);
    closeOverlay();
    assert.equal(shell.overlayOpen, false);
    assert.equal(inspectorVisible(), false);
    wide();
});

// Rejects: a toggle in the narrow layout that changes or stores the wide choice, a wide toggle that is not
// kept in localStorage under the base path, and an inspectorVisible() that reads the wrong flag per layout.
test('toggleInspector toggles the overlay when narrow and the kept choice when wide', () => {
    wide();
    shell.inspectorOpen = false;
    storage.setItem('orreryInspectorOpen@/admin/orrery', '0');

    toggleInspector();
    assert.equal(shell.inspectorOpen, true);
    assert.equal(inspectorVisible(), true);
    assert.equal(storage.getItem('orreryInspectorOpen@/admin/orrery'), '1');

    toggleInspector();
    assert.equal(shell.inspectorOpen, false);
    assert.equal(inspectorVisible(), false);
    assert.equal(storage.getItem('orreryInspectorOpen@/admin/orrery'), '0');

    setNarrow(true);
    toggleInspector();
    assert.equal(shell.overlayOpen, true);
    assert.equal(shell.inspectorOpen, false, 'the wide choice stays');
    assert.equal(storage.getItem('orreryInspectorOpen@/admin/orrery'), '0');
    assert.equal(inspectorVisible(), true);
    toggleInspector();
    assert.equal(shell.overlayOpen, false);
    wide();
});
