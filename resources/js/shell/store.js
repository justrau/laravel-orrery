import {reactive} from 'vue';
import {terminalState} from '../errors.js';
import {readStored, writeStored} from '../support/storage.js';
import {inspectorShown, shellHealth} from './data.js';

const INSPECTOR_KEY = 'orreryInspectorOpen';

function readInspectorOpen() {
    return readStored(INSPECTOR_KEY) !== '0';
}

/**
 * The state of the application shell that the screens share:
 * - `title` and `subtitle` of the toolbar (null: the route's meta title);
 * - the inspector: `inspectorScreens` counts the mounted InspectorLayouts
 *   with an inspector, so the toolbar shows its toggle only for them;
 *   `inspectorOpen` is the viewer's choice, kept in localStorage.
 * - the narrow layout: `narrow` is true below 1024 px
 *   (AppShell keeps it in line with the viewport). Then the sidebar is a
 *   drawer (`navOpen`) behind the toolbar's toggle, and the inspector an
 *   overlay (`overlayOpen`) that a selection or the toolbar's toggle opens;
 *   both start closed and close on Escape and on a route change. `phone`
 *   is true below 768 px, where the overlay covers the whole pane.
 */
export const shell = reactive({
    title: null,
    subtitle: null,
    inspectorScreens: 0,
    inspectorOpen: readInspectorOpen(),
    narrow: false,
    phone: false,
    navOpen: false,
    overlayOpen: false,
});

/**
 * The shell's polled reads, shared with the screens, so
 * a screen does not fetch them again. AppShell writes them; the screens only
 * read them.
 *
 * - `workload` (`GET /api/workload` rows), `workers` (`GET /api/workers`),
 *   `health` (`GET /api/health` of the last 15 minutes), `groupsHour` (`GET
 *   /api/failures/groups` of the past hour), `counts` (`GET /api/counts`) and
 *   `batches` (the first `GET /api/batches` page): each is null until its
 *   first successful read.
 * - `…Error` is true while the last read of that part failed (a timeout
 *   included). The part then keeps its last data, which is stale: a screen
 *   shows its own error for it, and never reads the old data as current.
 * - `at`: the time (epoch seconds) of the last poll in which the live part
 *   (workload, workers and health) was read without an error; null before.
 *
 * `counts` and `batches` are read on every third poll.
 */
export const live = reactive({
    workload: null,
    workloadError: false,
    workers: null,
    workersError: false,
    health: null,
    healthError: false,
    groupsHour: null,
    groupsHourError: false,
    counts: null,
    countsError: false,
    batches: null,
    batchesError: false,
    at: null,
});

/**
 * The held navigation (support/navigation.js, app.js): `progress` shows the
 * shell's progress bar, `title` is the next screen's route title for its
 * label. app.js writes it; the shell only reads it.
 */
export const navigation = reactive({progress: false, title: null});

/** Whether the live part (workload, workers or health) failed in the last poll: the toolbar says "Stale". */
export function liveFailed() {
    return live.workloadError || live.workersError || live.healthError;
}

/**
 * The watcher health of the footer from the live data (data.js
 * shellHealth()): null before anything loaded, unknown once the page left
 * its polls (errors.js terminalState()). The Overview's subtitle takes the
 * same result.
 */
export function liveWatcherHealth(now) {
    return shellHealth(live, now, terminalState() !== null);
}

let liveRefresh = null;

/**
 * AppShell registers the function that refreshes `live` (shell/poll.js
 * createLiveRefresher().request), and clears it with null on unmount.
 */
export function setLiveRefresh(refresh) {
    liveRefresh = typeof refresh === 'function' ? refresh : null;
}

/**
 * Ask the shell for fresh live data now, for example after a control write
 * settles, instead of waiting up to 10 s for the next poll. A read in flight
 * is never doubled: the refresh runs once after it. Without a shell it does
 * nothing.
 */
export function requestLiveRefresh() {
    if (liveRefresh) liveRefresh();
}

/** Set the toolbar title and subtitle of the current screen. */
export function setToolbar({title = null, subtitle = null} = {}) {
    shell.title = title;
    shell.subtitle = subtitle;
}

/** Clear the toolbar text: the router does this on every route change. */
export function resetToolbar() {
    shell.title = null;
    shell.subtitle = null;
}

/** Whether the inspector of a screen that has one is shown now (data.js inspectorShown()). */
export function inspectorVisible() {
    return inspectorShown(shell);
}

/** The narrow layout starts or ends: the drawer and the overlay close. */
export function setNarrow(narrow) {
    shell.narrow = narrow === true;
    shell.navOpen = false;
    shell.overlayOpen = false;
}

/** Below 768 px or not (the overlay then covers the pane, which becomes inert). */
export function setPhone(phone) {
    shell.phone = phone === true;
}

export function toggleNav() {
    shell.navOpen = shell.narrow && !shell.navOpen;
}

export function closeNav() {
    shell.navOpen = false;
}

/**
 * A screen shows its inspector for a selection the user made (a row
 * opened): in the narrow layout the overlay opens; in the wide layout the
 * viewer's choice stays.
 */
export function revealInspector() {
    if (shell.narrow) shell.overlayOpen = true;
}

export function closeOverlay() {
    shell.overlayOpen = false;
}

/** The toolbar's inspector toggle: the overlay in the narrow layout, else the viewer's kept choice. */
export function toggleInspector() {
    if (shell.narrow) {
        shell.overlayOpen = !shell.overlayOpen;

        return;
    }

    shell.inspectorOpen = !shell.inspectorOpen;

    writeStored(INSPECTOR_KEY, shell.inspectorOpen ? '1' : '0');
}
