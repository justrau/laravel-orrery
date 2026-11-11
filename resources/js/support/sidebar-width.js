import {readStored, removeStored, writeStored} from './storage.js';

/**
 * The width of the desktop sidebar, which a drag handle on its right edge
 * changes (shell/AppShell.vue). The width is per browser: localStorage
 * through support/storage.js, which never throws, so the page works when
 * the storage cannot be read or written. The narrow drawer layout keeps its
 * own width.
 *
 * - The bounds: SIDEBAR_MIN_WIDTH, and the smaller of SIDEBAR_MAX_WIDTH and
 *   half the viewport (never below the minimum).
 * - The keys: Left and Right by SIDEBAR_STEP (Shift: SIDEBAR_BIG_STEP),
 *   Home and End to the bounds, Enter to the default width. With Cmd, Ctrl
 *   or Alt held a key is the browser's or the system's, not the handle's.
 * - The default width (the `--container-sidebar` token) is not stored, so a
 *   reset removes the stored value.
 */
export const SIDEBAR_DEFAULT_WIDTH = 236;
export const SIDEBAR_MIN_WIDTH = 200;
export const SIDEBAR_MAX_WIDTH = 480;
export const SIDEBAR_STEP = 8;
export const SIDEBAR_BIG_STEP = 40;

const KEY = 'orrerySidebarWidth';

/** The largest width for a viewport of `viewport` px: at most 480 px and half the viewport, never below the minimum. */
export function sidebarMaxWidth(viewport) {
    if (typeof viewport !== 'number' || !Number.isFinite(viewport)) return SIDEBAR_MAX_WIDTH;

    return Math.max(SIDEBAR_MIN_WIDTH, Math.min(SIDEBAR_MAX_WIDTH, Math.floor(viewport / 2)));
}

/** `width` rounded and within the bounds; the default width when `width` is not a finite number. */
export function clampSidebarWidth(width, viewport) {
    if (typeof width !== 'number' || !Number.isFinite(width)) return SIDEBAR_DEFAULT_WIDTH;

    return Math.min(sidebarMaxWidth(viewport), Math.max(SIDEBAR_MIN_WIDTH, Math.round(width)));
}

/**
 * The width after the key `key` on the handle, or null for a key the
 * handle does not handle. `modifiers` holds the event's `metaKey`,
 * `ctrlKey` and `altKey`: with one of them, null. Enter gives the default
 * width, unclamped (the shell clamps what it draws).
 */
export function sidebarWidthForKey(width, key, shift, viewport, modifiers = {}) {
    if (modifiers && (modifiers.metaKey || modifiers.ctrlKey || modifiers.altKey)) return null;

    const step = shift ? SIDEBAR_BIG_STEP : SIDEBAR_STEP;

    switch (key) {
        case 'ArrowLeft': return clampSidebarWidth(width - step, viewport);
        case 'ArrowRight': return clampSidebarWidth(width + step, viewport);
        case 'Home': return SIDEBAR_MIN_WIDTH;
        case 'End': return sidebarMaxWidth(viewport);
        case 'Enter': return SIDEBAR_DEFAULT_WIDTH;
        default: return null;
    }
}

/** The handle's `aria-valuetext`: "236 pixels". */
export function sidebarWidthText(width) {
    return `${Math.round(width)} pixels`;
}

/** The stored width within the bounds, else the default width. */
export function readSidebarWidth(viewport, storage = undefined, basePath = undefined) {
    const text = readStored(KEY, storage, basePath);
    const width = typeof text === 'string' && /^\d{1,4}$/.test(text) ? Number(text) : Number.NaN;

    return clampSidebarWidth(width, viewport);
}

/** Stores `width`, or removes the value for the default width; false when the storage cannot be written. */
export function writeSidebarWidth(width, storage = undefined, basePath = undefined) {
    return width === SIDEBAR_DEFAULT_WIDTH
        ? removeStored(KEY, storage, basePath)
        : writeStored(KEY, Math.round(width), storage, basePath);
}
