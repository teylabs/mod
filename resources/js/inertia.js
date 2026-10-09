export async function resolveModulePage(name, appPages, modulePages) {
    const qualified = name.includes('::');
    const parts = qualified ? name.split('::') : [null, name];
    const [module, path] = parts;
    if (parts.length !== 2 || !path || (module !== null && !/^[A-Za-z0-9_-]+$/.test(module)) || !path.split('/').every((part) => /^[A-Za-z0-9_-]+$/.test(part))) {
        throw new Error(`Invalid Inertia page name: ${name}`);
    }
    const pages = qualified ? modulePages : appPages;
    const keys = Object.keys(pages);
    const sample = keys[0] || Object.keys(appPages)[0] || './pages/Index.vue';
    const extension = sample.split('.').pop();
    const casing = sample.includes('/Pages/') ? 'Pages' : 'pages';
    const suffix = `${module}/resources/js/${casing}/${path}.${extension}`;
    const expected = qualified ? `${module}::${path}.${extension}` : `./${casing}/${path}.${extension}`;
    const matches = keys.filter((key) => qualified
        ? key === expected || key.endsWith(`/${suffix}`)
        : key === expected);
    if (matches.length !== 1) {
        const root = sample.match(/^(.*\/)[^/]+\/resources\/js\/(?:pages|Pages)\//);
        const file = qualified && root ? `${root[1]}${suffix}` : expected;
        throw new Error(`${matches.length ? 'Ambiguous' : 'Missing'} Inertia page: ${file}`);
    }
    const page = pages[matches[0]];
    return typeof page === 'function' ? page() : page;
}
