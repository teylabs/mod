export async function resolveModulePage(name, appPages, modulePages) {
    const qualified = name.includes('::');
    const parts = qualified ? name.split('::') : [null, name];
    const [module, path] = parts;
    if (parts.length !== 2 || !path || (module !== null && !/^[A-Za-z0-9_-]+$/.test(module)) || !path.split('/').every((part) => /^[A-Za-z0-9_-]+$/.test(part))) {
        throw new Error(`Invalid Inertia page name: ${name}`);
    }
    const pages = qualified ? modulePages : appPages;
    const keys = Object.keys(pages);
    const matches = keys.filter((key) => {
        if (qualified && key.startsWith(`${module}::`)) return key.replace(/\.[^.]+$/, '') === name;
        const marker = key.match(/\/(?:pages|Pages)\//);
        if (!marker) return false;
        const prefix = key.slice(0, marker.index);
        return (!qualified || prefix.split('/').includes(module)) && key.slice(marker.index + marker[0].length).replace(/\.[^.]+$/, '') === path;
    });
    const sample = keys[0] || Object.keys(appPages)[0] || './pages/Index.vue';
    const extension = sample.split('.').pop();
    const casing = sample.includes('/Pages/') ? 'Pages' : 'pages';
    const expected = qualified ? `${module}::${path}.${extension}` : `./${casing}/${path}.${extension}`;
    if (matches.length !== 1) {
        throw new Error(`${matches.length ? 'Ambiguous' : 'Missing'} Inertia page: ${expected}`);
    }
    const page = pages[matches[0]];
    return typeof page === 'function' ? page() : page;
}
