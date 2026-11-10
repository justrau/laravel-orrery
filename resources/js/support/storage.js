/**
 * localStorage for per-viewer conveniences only (the auto-load toggle, a
 * bulk run's ref, the inspector's state). Its accessor throws in a private
 * window, with blocked site data or in a sandbox, so every read and write
 * goes through these helpers and the page works without it.
 *
 * Every key is scoped by the dashboard's base path (`window.Orrery.basePath`,
 * scopedKey()): two dashboards on one origin (two applications under one
 * host) never read or delete each other's values.
 */

function defaultStorage() {
    try {
        return typeof window !== 'undefined' ? window.localStorage : null;
    } catch (error) {
        return null;
    }
}

function defaultBasePath() {
    try {
        return typeof window !== 'undefined' && window.Orrery && typeof window.Orrery.basePath === 'string' ? window.Orrery.basePath : '';
    } catch (error) {
        return '';
    }
}

/** The stored key of `key` under a dashboard's base path: "orreryBulkRun@/admin/orrery"; the key itself without one. */
export function scopedKey(key, basePath = defaultBasePath()) {
    return typeof basePath === 'string' && basePath !== '' ? `${key}@${basePath}` : key;
}

/** The stored text of `key`, or null when it is absent or the storage cannot be read. */
export function readStored(key, storage = defaultStorage(), basePath = defaultBasePath()) {
    try {
        return storage ? storage.getItem(scopedKey(key, basePath)) : null;
    } catch (error) {
        return null;
    }
}

/** Stores `value` as text under `key`; false when the storage cannot be written. */
export function writeStored(key, value, storage = defaultStorage(), basePath = defaultBasePath()) {
    try {
        if (!storage) return false;

        storage.setItem(scopedKey(key, basePath), String(value));

        return true;
    } catch (error) {
        return false;
    }
}

/** Removes `key`; false when the storage cannot be written. */
export function removeStored(key, storage = defaultStorage(), basePath = defaultBasePath()) {
    try {
        if (!storage) return false;

        storage.removeItem(scopedKey(key, basePath));

        return true;
    } catch (error) {
        return false;
    }
}
