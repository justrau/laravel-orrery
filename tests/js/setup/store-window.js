// A browser window for tests/js/shell-store.test.js. The store reads the
// inspector choice from localStorage when it loads, so this module runs
// first (ES modules run their imports in order) and gives the store a
// stored "closed" choice under a base path.

const values = new Map([['orreryInspectorOpen@/admin/orrery', '0']]);

export const storage = {
    getItem: key => (values.has(key) ? values.get(key) : null),
    setItem: (key, value) => values.set(key, String(value)),
    removeItem: key => values.delete(key),
};

globalThis.window = {localStorage: storage, Orrery: {basePath: '/admin/orrery'}};
