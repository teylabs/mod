export async function resolveModulePage(name, appPages, modulePages) {
    const [module, path] = pageName(name);
    const qualified = module !== null;
    const pages = qualified ? modulePages : appPages;
    const keys = Object.keys(pages);
    const sample = keys[0] || Object.keys(appPages)[0] || './pages/Index.vue';
    const extension = sample.split('.').pop();
    const casing = sample.includes('/Pages/') ? 'Pages' : 'pages';
    const suffix = `${module}/resources/js/${casing}/${path}.${extension}`;
    const expected = qualified ? `${module}::${path}.${extension}` : `./${casing}/${path}.${extension}`;
    const matches = keys.filter((key) => matchesPageKey(key, module, `${path}.${extension}`, casing));
    if (matches.length !== 1) {
        const root = sample.match(/^(.*\/)[^/]+\/resources\/js\/(?:pages|Pages)\//);
        const file = qualified && root ? `${root[1]}${suffix}` : expected;
        throw new Error(`${matches.length ? 'Ambiguous' : 'Missing'} Inertia page: ${file}`);
    }
    const page = pages[matches[0]];
    return typeof page === 'function' ? page() : page;
}

/** @api Return the glob value in the closest folder, without loading it. */
export function nearestPageFile(name, files) {
    const [module, path] = pageName(name);
    const folders = path.split('/');
    const keys = Object.keys(files);
    while (folders.length) {
        folders.pop();
        const folder = folders.length ? `${folders.join('/')}/` : '';
        const matches = keys.filter((key) => {
            const file = key.split('::').pop().split('/').pop();
            return ['pages', 'Pages'].some((casing) => matchesPageKey(key, module, `${folder}${file}`, casing));
        });
        if (matches.length > 1) {
            throw new Error(`Ambiguous Inertia page file: ${name}`);
        }
        if (matches.length === 1) {
            return files[matches[0]];
        }
    }
    return undefined;
}

function pageName(name) {
    const parts = name.includes('::') ? name.split('::') : [null, name];
    const [module, path] = parts;
    if (parts.length !== 2 || !path || (module !== null && !module.split('/').every((part) => /^[A-Za-z0-9_-]+$/.test(part))) || !path.split('/').every((part) => /^[A-Za-z0-9_-]+$/.test(part))) {
        throw new Error(`Invalid Inertia page name: ${name}`);
    }
    return parts;
}

function matchesPageKey(key, module, file, casing) {
    return module === null
        ? key === `./${casing}/${file}`
        : key === `${module}::${file}` || key.endsWith(`/${module}/resources/js/${casing}/${file}`);
}
