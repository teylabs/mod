import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';

const load = async (path) => import(`data:text/javascript;base64,${Buffer.from(await readFile(new URL(path, import.meta.url))).toString('base64')}`);
const current = await load('../../resources/js/inertia.js');
const legacy = await load('../Fixtures/inertia/inertia.js');

test('resolveModulePage preserves the pinned 0.6 lookup, loading and diagnostics', async () => {
    for (const extension of ['vue', 'tsx', 'jsx', 'svelte']) {
        for (const casing of ['pages', 'Pages']) {
            const app = { [`./${casing}/Client/Edit/Page.${extension}`]: Promise.resolve('app') };
            const modules = {
                [`../../app/Modules/Sales/Reports/resources/js/${casing}/Client/Edit/Page.${extension}`]: async () => 'nested',
                [`UI::Client/Edit/Page.${extension}`]: 'mounted',
                [`billing::Client/Edit/Page.${extension}`]: 'package',
            };
            for (const name of ['Client/Edit/Page', 'Sales/Reports::Client/Edit/Page', 'UI::Client/Edit/Page', 'billing::Client/Edit/Page', 'Missing', 'Other::Missing', '../Page', 'UI::', 'UI::Page::Other']) {
                const outcome = async (resolver) => {
                    try { return await resolver(name, app, modules); }
                    catch (error) { return error.message; }
                };
                assert.deepEqual(await outcome(current.resolveModulePage), await outcome(legacy.resolveModulePage));
            }
            const ambiguous = { ...modules, [`Sales/Reports::Client/Edit/Page.${extension}`]: 'duplicate' };
            await assert.rejects(current.resolveModulePage('Sales/Reports::Client/Edit/Page', app, ambiguous), /Ambiguous Inertia page:/);
        }
    }
    assert.equal(await current.resolveModulePage('Index', { './pages/Index.vue': 'eager' }, {}), 'eager');
    await assert.rejects(current.resolveModulePage('Index', {}, {}), { message: 'Missing Inertia page: ./pages/Index.vue' });
});

test('nearestPageFile prefers the own folder, then each ancestor, through the app root', () => {
    const files = {
        './Layout.vue': 'outside',
        './pages/Client/Edit/Child/Frame.vue': 'descendant',
        './pages/Client/Editor/Frame.vue': 'sibling',
        './pages/Frame.vue': 'root',
        './pages/Client/Frame.vue': 'parent',
        './pages/Client/Edit/Frame.vue': 'own',
    };
    for (const [key, expected] of [
        ['./pages/Client/Edit/Frame.vue', 'own'],
        ['./pages/Client/Frame.vue', 'parent'],
        ['./pages/Frame.vue', 'root'],
    ]) {
        assert.equal(current.nearestPageFile('Client/Edit/Page', files), expected);
        delete files[key];
    }
    assert.equal(current.nearestPageFile('Client/Edit/Page', files), undefined);
    assert.equal(current.nearestPageFile('Index', { './pages/Anything.vue': 'root' }), 'root');
    assert.equal(current.nearestPageFile('Missing', {}), undefined);
});

test('nearestPageFile shares canonical and suffix matching without crossing owners', () => {
    const files = {
        './pages/Client/Frame.vue': 'app',
        '../../app/Modules/Sales/resources/js/pages/Frame.vue': 'outer module',
        '../../app/Modules/Sales/Reports/resources/js/pages/Frame.vue': 'nested root',
        '../../app/Modules/Sales/Reports/resources/js/pages/Client/Frame.vue': 'nested parent',
        '../../app/Modules/Sales/Reports/resources/js/Frame.vue': 'outside',
        'UI::Client/Frame.vue': 'mounted',
        'billing::Client/Frame.vue': 'package',
        'UI/Other::Frame.vue': 'other mount',
    };
    assert.equal(current.nearestPageFile('Sales/Reports::Client/Edit/Page', files), 'nested parent');
    delete files['../../app/Modules/Sales/Reports/resources/js/pages/Client/Frame.vue'];
    assert.equal(current.nearestPageFile('Sales/Reports::Client/Edit/Page', files), 'nested root');
    delete files['../../app/Modules/Sales/Reports/resources/js/pages/Frame.vue'];
    assert.equal(current.nearestPageFile('Sales/Reports::Client/Edit/Page', files), undefined);
    assert.equal(current.nearestPageFile('UI::Client/Edit/Page', files), 'mounted');
    assert.equal(current.nearestPageFile('billing::Client/Edit/Page', files), 'package');
    delete files['UI::Client/Frame.vue'];
    assert.equal(current.nearestPageFile('UI::Client/Edit/Page', files), undefined);
    assert.equal(current.nearestPageFile('Other::Client/Edit/Page', files), undefined);
    files['UI::Frame.vue'] = 'mounted root';
    files['billing::Frame.vue'] = 'package root';
    assert.equal(current.nearestPageFile('UI::Client/Edit/Page', files), 'mounted root');
    delete files['billing::Client/Frame.vue'];
    assert.equal(current.nearestPageFile('billing::Client/Edit/Page', files), 'package root');
    assert.equal(current.nearestPageFile('Other::Client/Edit/Page', files), undefined);
});

test('nearestPageFile accepts Pages casing, mixed React extensions and arbitrary basenames', () => {
    const files = {
        './pages/Frame.vue': 'unrelated casing and extension',
        './Pages/Client/Wrapper.jsx': 'react app',
        '../../src/Modules/Sales/resources/js/Pages/Client/Shell.tsx': 'react module',
        'UI::Client/Boundary.jsx': 'react mount',
        'billing::Client/Boundary.svelte': 'package svelte',
    };
    assert.equal(current.nearestPageFile('Client/Edit/Page', files), 'react app');
    assert.equal(current.nearestPageFile('Sales::Client/Edit/Page', files), 'react module');
    assert.equal(current.nearestPageFile('UI::Client/Edit/Page', files), 'react mount');
    assert.equal(current.nearestPageFile('billing::Client/Edit/Page', files), 'package svelte');
    assert.equal(current.nearestPageFile('Sales::Client/Edit/Page', {
        '../../Pages/Sales/resources/js/pages/Client/metadata': 17,
    }), 17);
    const loader = () => { throw new Error('A lookup must not invoke a loader or component'); };
    assert.equal(current.nearestPageFile('Index', { './pages/Frame.tsx': loader }), loader);
});

test('nearestPageFile rejects invalid names and equally near files rather than guessing', () => {
    for (const name of ['', '../Page', 'UI::../Page', 'UI::', 'UI::Page::Other', '/Page']) {
        assert.throws(() => current.nearestPageFile(name, {}), { message: `Invalid Inertia page name: ${name}` });
    }
    assert.throws(() => current.nearestPageFile('Client/Page', {
        './pages/Client/One.vue': 1,
        './pages/Client/Two.vue': 2,
    }), /Ambiguous Inertia page file:/);
});
