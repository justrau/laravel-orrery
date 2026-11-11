// A guard over every .vue file of resources/js: no option key is defined twice.
//
// In an object literal the later key wins without a warning, so a second
// `attention()` in `computed` silently replaced the first one, and the
// Overview said "Everything is healthy" while it listed items. This test
// parses each script block with Vue's own parsers (vue/compiler-sfc: the SFC
// parser and Babel), so strings, comments and nested objects never confuse
// it, and fails on:
//
// - a key defined twice in `props`, `data()`'s returned object, `computed`,
//   `methods` or `watch`;
// - a name defined in two of `props`, `data()`, `computed` and `methods`
//   (Vue gives one of them the instance property).

import {test} from 'node:test';
import assert from 'node:assert/strict';
import {readdirSync, readFileSync} from 'node:fs';
import {join, relative} from 'node:path';
import {fileURLToPath} from 'node:url';
import {babelParse, parse} from 'vue/compiler-sfc';

const ROOT = fileURLToPath(new URL('../..', import.meta.url));
const SECTIONS = ['props', 'data', 'computed', 'methods', 'watch'];
const INSTANCE_SECTIONS = ['props', 'data', 'computed', 'methods'];

function vueFiles(directory) {
    return readdirSync(directory, {withFileTypes: true}).flatMap(entry => {
        const path = join(directory, entry.name);

        if (entry.isDirectory()) return vueFiles(path);

        return entry.name.endsWith('.vue') ? [path] : [];
    });
}

/** The name of a property key, or null for a computed key or a spread. */
function keyName(property) {
    if (property.type === 'SpreadElement' || property.computed) return null;

    const key = property.key;

    if (key.type === 'Identifier') return key.name;
    if (key.type === 'StringLiteral' || key.type === 'NumericLiteral') return String(key.value);

    return null;
}

/** The object that data() returns (the last top-level return of an object literal), or null. */
function dataObject(property) {
    const fn = property.type === 'ObjectMethod' ? property : property.value;
    const body = fn && fn.body;

    if (!body) return null;
    if (body.type === 'ObjectExpression') return body;

    const returns = (body.body || []).filter(statement => statement.type === 'ReturnStatement' && statement.argument && statement.argument.type === 'ObjectExpression');

    return returns.length ? returns[returns.length - 1].argument : null;
}

/** The option objects of a component's script: {section: [names]}. */
function optionKeys(source) {
    const ast = babelParse(source, {sourceType: 'module', plugins: []});
    const declaration = ast.program.body.find(node => node.type === 'ExportDefaultDeclaration');
    const options = declaration && declaration.declaration;
    const result = {};

    if (!options || options.type !== 'ObjectExpression') return result;

    for (const property of options.properties) {
        const name = keyName(property);

        if (!SECTIONS.includes(name)) continue;

        const object = name === 'data' ? dataObject(property) : property.value;

        if (!object || object.type !== 'ObjectExpression') continue;

        result[name] = object.properties.map(keyName).filter(key => key !== null);
    }

    return result;
}

/** The problems of one component's option keys: "computed.attention twice", "computed and methods both define x". */
function duplicateKeys(sections) {
    const problems = [];

    for (const [section, keys] of Object.entries(sections)) {
        const seen = new Set();

        for (const key of keys) {
            if (seen.has(key)) problems.push(`${section}.${key} twice`);
            seen.add(key);
        }
    }

    const owners = new Map();

    for (const section of INSTANCE_SECTIONS) {
        for (const key of new Set(sections[section] || [])) {
            if (owners.has(key)) problems.push(`${owners.get(key)} and ${section} both define ${key}`);
            else owners.set(key, section);
        }
    }

    return problems;
}

function scriptOf(path) {
    const {descriptor} = parse(readFileSync(path, 'utf8'), {filename: path});

    return descriptor.script ? descriptor.script.content : null;
}

// Rejects: a component with a key defined twice, or one name in two instance sections (the
// duplicate `attention()` computed of the Overview). It would still pass a duplicate built
// with a computed key or a spread, which no component uses.
test('no .vue component defines an option key twice', () => {
    const files = vueFiles(join(ROOT, 'resources/js'));
    const problems = [];
    let scanned = 0;

    for (const path of files) {
        const script = scriptOf(path);

        if (script === null) continue;

        scanned++;
        for (const problem of duplicateKeys(optionKeys(script))) {
            problems.push(`${relative(ROOT, path)}: ${problem}`);
        }
    }

    assert.ok(scanned >= 20, `only ${scanned} components scanned`);
    assert.deepEqual(problems, []);
});

// Rejects: a scan that misses a duplicate in computed (the bug this guard is for), in methods,
// props, data() or watch, a duplicate across sections, or one that a string or comment hides.
test('the guard finds each kind of duplicate', () => {
    const component = body => `export default {\n${body}\n};`;

    assert.deepEqual(duplicateKeys(optionKeys(component(`
        computed: {
            attention() { return 1; },
            subtitle() { return '{ attention() {} }'; /* attention() {} */ },
            attention() { return 2; },
        },`))), ['computed.attention twice']);
    assert.deepEqual(duplicateKeys(optionKeys(component('methods: {a() {}, \'a\': () => 1},'))), ['methods.a twice']);
    assert.deepEqual(duplicateKeys(optionKeys(component('props: {a: String, a: Number},'))), ['props.a twice']);
    assert.deepEqual(duplicateKeys(optionKeys(component('data() { const x = {b: 1}; return {a: 1, a: 2}; },'))), ['data.a twice']);
    assert.deepEqual(duplicateKeys(optionKeys(component('watch: {a() {}, a: {handler() {}}},'))), ['watch.a twice']);
    assert.deepEqual(duplicateKeys(optionKeys(component('data: () => ({a: 1}), computed: {a() {}},'))), ['data and computed both define a']);
    assert.deepEqual(duplicateKeys(optionKeys(component('computed: {a() {}}, methods: {b() {}}, watch: {a() {}},'))), []);
});
