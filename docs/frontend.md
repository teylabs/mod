# Frontend wiring

## Full-page Inertia loads

Use `Mod::pageEntry()` in the root Blade view's Vite entries to map the current page to its real folder:

```blade
@vite(['resources/js/app.ts', \Tey\Mod\Facades\Mod::pageEntry($page['component'], 'vue')])
```

For React use the app's entry (such as `resources/js/app.tsx`) and `tsx` or `jsx` as its page extension. Ordinary app pages keep the app's `pages`/`Pages` folder; qualified pages follow the layout's frontend paths. The helper returns a project-relative manifest key and refuses unknown namespaces or traversal. Build the frontend after adding pages. The module page glob in the app entry includes these files in Vite's manifest. This supports direct loads as well as client navigation.

## Declare page entries

When helpers live beside pages, declare the file stems that are Inertia entries:

```php
Mod::layout('app')->extends('modules')->frontend(pageEntries: ['Page', 'Modal']);

// Inside a mounted root:
$root->frontend(pages: 'pages', pageName: 'UI::{path}', pageEntries: ['Page']);

// From a package provider:
Mod::pages('billing', __DIR__.'/../resources/js/pages', entries: ['Page']);
```

Stems are case-sensitive and may contain `*`, such as `Page*`. They match only
the file stem, not its parent folders. An empty list includes no entries. The
default `null` keeps every file eligible and leaves existing installer output,
identities and inventory unchanged. Layout declarations apply to ordinary app
pages and the layout's page trees. Mounted roots and packages declare their own
lists; omitted frontend arguments retain inherited declarations.

Run `mod:install inertia --dry-run --json`, then apply and rebuild. With
`['Page', 'Modal']`, each page tree gets literal includes such as
`import.meta.glob(['./pages/**/Page.vue', './pages/**/Modal.vue'])`.
`Layout.vue` and `Partials/Card.vue` are no longer independent page chunks;
imported helpers still participate in Vite's normal dependency graph. Changing
the list requires another explicit installation. Existing custom resolvers,
including hand-written exclusion globs, receive exact manual instructions if
the installer cannot confirm them, with no edits.

Render names stay verbatim: `Inventory::Widget/Edit/Page`, not
`Inventory::Widget/Edit`. Non-entries keep component `.import` and `.path`
identities but have no render `.name`. `Mod::pageEntry()` refuses them with
their project-relative path. Inertia's backend page-existence and testing
finders remain unfiltered. `mod:list --json` reports declared `page_entries`
in `frontend`, `frontend_owners` and `package_pages`; it omits that key for `null`.

## Defining your own page shape

This example gives the Inventory module a Widget page tree. It uses only public
layout, scaffold and resolver APIs. `Page` and `Modal` are this app's entry stems,
not a convention shipped by mod. A modal is an ordinary page component here:
no modal package, navigation component, styling or database model is required.

### Declare the shape

In `AppServiceProvider::boot()`, declare a layout and recipe. Put the imports at
file scope, and set `'layout' => 'modules'` in `config/mod.php`:

```php
use Tey\Mod\Commands\PlainFileCommand;
use Tey\Mod\Facades\Mod;
use Tey\Mod\Layout\FileType;
use Tey\Mod\Scaffolds\Part;
use Tey\Mod\Scaffolds\Scaffold;

Mod::layout('modules')
    ->frontend(pageEntries: ['Page', 'Modal'])
    ->generates('page', in: '@module/resources/js/pages', nested: true, case: 'studly',
        using: fn (FileType $type) => $type->extension('.vue'))
    ->generates('page-layout', in: '@module/resources/js/pages', fixed: 'Layout', nested: true, case: 'studly',
        using: fn (FileType $type) => $type->extension(Mod::stack()->inertia() === 'react' ? '.tsx' : '.vue'));

Mod::generators()->useFileType('page-layout', PlainFileCommand::class);

Mod::scaffold('resource-pages', fn (Scaffold $s) => $s
    ->asks('modal', type: 'confirm', default: false)
    ->asks('tabs', type: 'list', default: ['Overview', 'Details'])
    ->folder('pages', '{name}', as: 'pageFolder')
    ->makes('page', name: '{name}/Index/Page', as: 'indexPage', stub: 'shape-page')
    ->makes('page', name: '{name}/Create/Page', as: 'createPage', stub: 'shape-page')
    ->makes('page', name: '{name}/Create/Modal', as: 'createModal', stub: 'shape-page', when: 'modal')
    ->makes('page-layout', name: '{name}/Edit/Layout', as: 'editLayout', stub: 'shape-layout')
    ->makes('controller', name: '{name}Controller', as: 'controller', stub: 'shape')
    ->part('tab', configure: fn (Part $p) => $p
        ->asks('tab', type: 'text')
        ->makes('page', name: '{name}/Edit/{tab}/Page', as: 'view', stub: 'shape-tab')
        ->inserts(into: 'controller', at: 'actions', stub: 'shape-action'))
    ->each('tabs', part: 'tab'));
```

`case: 'studly'` also keeps React's stems as `Page` and `Modal`; its default
kebab-case would not match this case-sensitive entry list. Declaring `page`
explicitly requires its path and plain-file extension; the detected stack
selects `.tsx` for React from the `.vue` seed. This layout applies
the entry list to app pages too. Adopt those stems there, or keep an existing app
page lookup when wiring manually. Mounted roots and packages declare their own
entry lists (`pageEntries:` and `Mod::pages(..., entries: [...])`).

The folder member owns the subtree for rename; it does not generate a directory
or change the placement of the file members. `Layout` keeps its component import
and path but has no render name. The app's `page-layout` file type generates the
helper separately from renderable `page` members; its fixed basename distinguishes
it from the general page type, and `PlainFileCommand` supplies its generator.
The optional member and controller block use the same `modal` answer.

### Supply the controller templates

Save `stubs/mod.controller.shape.stub`:

```php
<?php

namespace {{ namespace }};

use Inertia\Inertia;

class {{ class }}
{
    public function index()
    {
        return Inertia::render('{{ indexPage.name }}');
    }

    public function create()
    {
        return Inertia::render('{{ createPage.name }}');
    }

    {{ #modal }}
    public function createModal()
    {
        return Inertia::render('{{ createModal.name }}');
    }
    {{ /modal }}

    // mod:actions
}
```

Save `stubs/mod.insert.shape-action.stub`. The part's `view` alias supplies the
actual render name, rather than assembling it from strings:

```php
    public function {{ tab.camel }}()
    {
        return Inertia::render('{{ tab.view.name }}');
    }

```

### Supply the Vue templates

Save these three variants:

```vue
<!-- stubs/mod.page.shape-page.vue.stub -->
<template><p>{{ name.studly }}</p></template>
```

```vue
<!-- stubs/mod.page.shape-tab.vue.stub -->
<template><p>{{ tab }}</p></template>
```

```vue
<!-- stubs/mod.page-layout.shape-layout.vue.stub -->
<template>
    <section>
        <h1>Edit {{ name.studly }}</h1>
        <slot />
    </section>
</template>
```

### Generate and grow the pages

```bash
php artisan mod:resource-pages Inventory:Widget --modal --no-interaction
php artisan mod:resource-pages.tab Inventory:Widget History --no-interaction
```

Use `--no-modal` instead of `--modal` to omit `Create/Modal` and the controller's
`createModal()` method. The second command grows only the History page and its
controller action; it leaves the existing layout and pages alone. The Vue tree
now contains:

```text
app/Modules/Inventory/resources/js/pages/Widget/
├── Index/Page.vue
├── Create/Page.vue
├── Create/Modal.vue       (only with --modal)
└── Edit/
    ├── Layout.vue
    ├── Overview/Page.vue
    ├── Details/Page.vue
    └── History/Page.vue
```

### Choose the folder layout

Run `php artisan mod:install inertia --dry-run`, then apply with
`--no-interaction` when the preview can wire the bootstrap. If it reports manual
instructions, apply its exact aliases, includes and page globs. Keep the adapter,
plugins and setup callback. In an Inertia 3 Vue app, use this app-owned `layout`
callback for these pages; preserve any existing callback for other pages:

```ts
import { nearestPageFile } from '../../vendor/tey/mod/resources/js/inertia';
import type { DefineComponent } from 'vue';

const folderLayouts = import.meta.glob<DefineComponent>([
    './pages/**/Layout.vue',
    '../../app/Modules/*/resources/js/pages/**/Layout.vue',
], { eager: true, import: 'default' });

// Inside createInertiaApp options:
layout: (name) => {
    const folder = nearestPageFile(name, folderLayouts);
    return folder ? [folder] : [];
},
```

`Inventory::Widget/Edit/History/Page` finds `Widget/Edit/Layout.vue`; Index and
Create find no folder layout. The lookup stops at Inventory's pages root. Mod
does not choose a default layout or add this callback during installation.

Inertia 2 has no global `layout` option. In its existing `resolve` callback,
await `resolveModulePage()` with the installer's maps, then assign the nearest
layout to the resolved component before returning it:

```ts
const page = await resolveModulePage(name, appPages, modulePages);
const folder = nearestPageFile(name, folderLayouts);
page.default.layout = folder ? [folder] : [];
return page.default;
```

Type the Vue module maps as `{ default: DefineComponent & { layout?: DefineComponent[] } }`.
Here `appPages` and `modulePages` stand for the literal glob maps printed by the
installer. Use the existing [full-page Blade entry](#full-page-inertia-loads)
with `Mod::pageEntry()` too, then build the frontend.

### Check entries and serve the resource

From application PHP, the entry helper accepts the page and refuses the layout:

```php
Mod::pageEntry('Inventory::Widget/Edit/History/Page', 'vue');
// app/Modules/Inventory/resources/js/pages/Widget/Edit/History/Page.vue
Mod::pageEntry('Inventory::Widget/Edit/Layout', 'vue');
// throws InvalidArgumentException naming .../Widget/Edit/Layout.vue
```

Add routes in `routes/web.php`:

```php
<?php

use App\Modules\Inventory\Http\Controllers\WidgetController;
use Illuminate\Support\Facades\Route;

Route::get('/widgets', [WidgetController::class, 'index']);
Route::get('/widgets/create', [WidgetController::class, 'create']);
Route::get('/widgets/history', [WidgetController::class, 'history']);
Route::get('/widgets/overview', fn () => inertia('Inventory::Widget/Edit/Overview/Page'));
```

With `--modal`, also route `/widgets/create-modal` to `createModal`. This proves
the component is renderable, not modal presentation. Requests return the exact
identities `Inventory::Widget/Index/Page`, `Inventory::Widget/Create/Page` and
`Inventory::Widget/Edit/History/Page`.

### Rename the owned tree

Add a hand-written `Widget/Partials/Card.vue` beneath the same pages root:

```vue
<template><p>Hand-written card</p></template>
```

Commit the application, then preview with every historical answer, including the
grown tab. After reviewing the preview, apply:

```bash
php artisan mod:rename Inventory:Widget Inventory:Gadget --scaffold=resource-pages --tabs=Overview --tabs=Details --tabs=History --modal --dry-run --json
php artisan mod:rename Inventory:Widget Inventory:Gadget --scaffold=resource-pages --tabs=Overview --tabs=Details --tabs=History --modal --yes --no-interaction
git diff --cached
```

Use `--no-modal` for a tree generated without the modal. The folder moves once,
including the hand-written Partials file and folder layout. The controller moves
to `GadgetController`; supported render literals in it and the hand-written route
change to `Inventory::Gadget/...`. The Partials bytes and existing page copy stay
unchanged: rename does not regenerate templates. Route URLs remain `/widgets`;
review them separately. Build and type-check again, then request the renamed
pages before committing the staged diff. See [rename safety](renaming.md).

### React variant

Keep the recipe and controller templates. The explicit StudlyCase page policy
keeps the same folders and render identities. Replace the three Vue variants:

```tsx
// stubs/mod.page.shape-page.tsx.stub
export default function Page() { return <p>{{ name.studly }}</p>; }
```

```tsx
// stubs/mod.page.shape-tab.tsx.stub
export default function Page() { return <p>{{ tab }}</p>; }
```

```tsx
// stubs/mod.page-layout.shape-layout.tsx.stub
import type { PropsWithChildren } from 'react';

export default function Layout({ children }: PropsWithChildren) {
    return <section><h1>Edit {{ name.studly }}</h1>{children}</section>;
}
```

Use `.tsx` globs, `tsx` in `pageEntry()`, and `Partials/Card.tsx` with a plain
React component. In Inertia 3, the folder callback has the same behaviour:

```tsx
import { nearestPageFile } from '../../vendor/tey/mod/resources/js/inertia';
import type { ComponentType, PropsWithChildren } from 'react';

const folderLayouts = import.meta.glob<ComponentType<PropsWithChildren>>([
    './pages/**/Layout.tsx',
    '../../app/Modules/*/resources/js/pages/**/Layout.tsx',
], { eager: true, import: 'default' });

// Inside createInertiaApp options:
layout: (name) => {
    const folder = nearestPageFile(name, folderLayouts);
    return folder ? [folder] : [];
},
```

For Inertia 2, type each resolved default as `ComponentType & { layout?: (page: ReactNode) => ReactNode }`.
Import `createElement` and the `ReactNode` type from React. In `resolve`, use:

```tsx
const page = await resolveModulePage(name, appPages, modulePages);
const folder = nearestPageFile(name, folderLayouts);
page.default.layout = folder ? (page) => createElement(folder, null, page) : undefined;
return page;
```

Keep the existing setup callback. Build with `npm run build` and check `npx vue-tsc --noEmit` (Vue)
or `npx tsc --noEmit` (React). The docs harness checks both stacks on Laravel 12
and 13, with and without the optional modal, including direct HTTP loads after
rename.

## Customized Inertia bootstraps

Run `php artisan mod:install inertia --dry-run` first. Known starter-kit anchors can be wired automatically. Existing wiring is recognised without edits: a vendor `resolveModulePage` import and call inside `resolve`, including multiline callbacks and array globs with `{ import: 'default' }`; an `@modules` alias using `path.resolve(...)` or `fileURLToPath(new URL(...))` with the module views refresh path; and the exact Tailwind `@source` before or after `@import`. A fully wired app reports `Already wired.`. If a custom shape cannot be confirmed, the installer says so and asks you to check the expected wiring. If the bootstrap has a custom resolver, the installer prints the import and exact page lookup for the configured paths, names the entry file and insertion point, and prints the Tailwind sources. JSON previews include the same instructions in `warnings`. It applies no edits while manual wiring is required.

For a modal adapter or persistent default layout, keep the existing adapter import, plugins and setup callback. Add the resolver import beside the other imports in `resources/js/app.ts`:

```ts
import { resolveModulePage } from '../../vendor/tey/mod/resources/js/inertia';
```

Replace only the page lookup inside the existing `resolve` callback. For the default Vue module paths:

```ts
type PageModule = {
    default: import('vue').DefineComponent & { layout?: typeof DefaultLayout };
};

// Inside the existing createInertiaApp options:
resolve: async (name) => {
    const page = await resolveModulePage(
        name,
        import.meta.glob<PageModule>('./pages/**/*.vue'),
        import.meta.glob<PageModule>('../../app/Modules/*/resources/js/pages/**/*.vue'),
    );
    page.default.layout ??= DefaultLayout;
    return page;
},
```

Keep the application's existing `DefaultLayout` import. A modal package's `createInertiaApp` wrapper can use this same lookup; retain its setup code. React uses the same resolver with `.tsx`/`.jsx` globs and the application's existing layout wrapper. Use the exact globs the installer prints for custom roots, `Pages` casing and JavaScript entries.

For Tailwind v4, add the printed `@source` after the Tailwind import in `resources/css/app.css`:

```css
@source '../../app/Modules/**/resources/**/*.{vue,ts,blade.php}';
```

For Tailwind v3, add the printed project-relative glob to the `content` array in the app's Tailwind config. Apply the Vite alias and TypeScript additions printed for their own files too. Then build the app and check a direct module page load.

### Find a folder file

`nearestPageFile(name, files)` returns the glob value in the page's own folder,
then its closest ancestor, stopping at that owner's pages root. It never falls
back from a module, mounted root or package namespace to another owner or to app
pages. A render name such as `Sales::Client/Edit/Page` searches `Client/Edit`,
`Client`, then the `Sales` pages root; it does not search below `Page`.

The app chooses the filenames and keeps its own layout callback. For example:

```ts
import { resolveModulePage, nearestPageFile } from '../../vendor/tey/mod/resources/js/inertia';

const layouts = import.meta.glob([
    './pages/**/Layout.vue',
    '../../app/Modules/*/resources/js/pages/**/Layout.vue',
], { eager: true, import: 'default' });

// Inside the existing createInertiaApp options, with the app's AppLayout import:
layout: (name) => [AppLayout, nearestPageFile(name, layouts)].filter(Boolean),
```

Use `Pages` casing and `.tsx`/`.jsx` or `.svelte` globs for the app's stack.
Nested module names such as `Sales/Reports::Client/Edit/Page` stop at that
nested module's root, not its parent module. Mounted roots, packages and custom
module paths use the same canonical keys as `resolveModulePage`. For example,
map a mounted `screens` glob to its `UI::` namespace before combining it with
the app and module maps:

```ts
const prefix = '../../app/UI/screens/';
const mountedLayouts = Object.fromEntries(Object.entries(
    import.meta.glob('../../app/UI/screens/**/Layout.vue', { eager: true, import: 'default' }),
).map(([key, value]) => ['UI::' + key.slice(prefix.length), value]));
const folderFiles = { ...layouts, ...mountedLayouts };
// nearestPageFile('UI::Client/Edit/Page', folderFiles)
```

Package maps use their declared namespace in place of `UI::` and their literal
page directory in place of `app/UI/screens`. The installer prints the exact
literal globs and canonical mapping per owner in manual instructions. Narrow
those globs to the app's chosen filename; the helper assumes no basename or
purpose. Installation never adds or rewrites a layout callback.

The helper returns `undefined` when no folder matches. It returns values
unchanged: eager default imports give components, while lazy globs give loaders
that the app must load itself. Invalid render names throw, as in
`resolveModulePage`; multiple files in the nearest matching folder throw an
ambiguity error. Keep the glob to one candidate per folder.


### Read the frontend stack

Packages can call `Mod::stack()` from their provider. Its `inertia()` returns
`vue`, `react`, `svelte` or `null`; `typescript()` checks for `tsconfig.json`,
`pagesPath()` preserves the app's `pages`/`Pages` casing, and `livewire()` detects
`livewire/livewire` or `livewire/flux` in Composer declarations. `toArray()` is the
same `stack` record returned by `mod:list --json`. This reads declarations without
installing dependencies or changing files. Svelte pages use `.svelte`; an app's
`stubs/mod.page.svelte.stub` overrides the minimal default.


### Package pages and wiring

Register package pages from a provider:

```php
Mod::pages('billing', __DIR__.'/../resources/js/pages');
Mod::wiring()
    ->alias('@acme/billing', 'vendor/acme/billing/resources/js')
    ->source('vendor/acme/billing/resources/js');
```

Render `billing::Invoices` normally. Both Inertia page finders and
`Mod::pageEntry('billing::Invoices', 'vue')` use the declared directory.
Namespaces must be unique and page directories must exist inside the application,
including after resolving symlinks. External finder hints retain precedence.

Run `mod:install inertia --dry-run --json` to preview literal package page globs,
aliases, TypeScript paths/includes and Tailwind sources. Apply with
`mod:install inertia --no-interaction`. Repeated installation says "Already
wired." when every declaration is confirmed. Custom bootstraps receive the exact
manual lines; keep adapters, layout assignment and plugins intact. Adding a new
package declaration requires another installation; new files under existing
globs do not. Providers only declare wiring; they never write application files.

A package command can use `$plan = Mod::wiring()->plan()`, inspect
`$plan->toArray()` and explicitly call `$plan->apply()`. Manual wiring warnings prevent writing,
and stale previews are refused before any file is written. `source()` scans
Tailwind classes without loading CSS. `css()` explicitly imports the chosen
stylesheet into `resources/css/app.css`; style order remains application-owned.
Aliases are ordered most specific first. TypeScript paths include both the bare
alias (`"@acme/billing": ["./vendor/acme/billing/resources/js"]`) and its subpaths
(`"@acme/billing/*": ["./vendor/acme/billing/resources/js/*"]`). The bare alias
uses TypeScript's normal directory index resolution; there is no `index:` option.
An existing conflicting TypeScript mapping requires a manual edit.

Composer path repositories installed as symlinks (or Windows directory junctions) are supported. Installed paths
under `vendor/` may resolve outside the application. A provider's absolute
`__DIR__` path is mapped back through the installed package symlink, so all page
entries, globs and planned edits keep their lexical `vendor/...` paths.
Other external paths, traversal and symlink escapes are skipped, as are missing
page directories, invalid names and conflicting declarations. They never stop
application boot. Wiring paths may name directories that will be created later.

The JSON inventory adds `package_pages`, `frontend_wiring` and
`frontend_notices`. Each notice has `code`, `path` and `message`. Stable codes are
`frontend_path_invalid`, `frontend_path_escape`, `frontend_path_unavailable`,
`frontend_alias_invalid`, `frontend_alias_conflict`, `page_namespace_invalid`
and `page_namespace_conflict`, plus `page_entries_invalid` for malformed package
entry lists. `mod:install` prints these notices; JSON previews
include them in `warnings` with the same code and `data.path`. Skipped declarations
do not prevent valid declarations from being installed.


### Frontend files in mounted roots

A root can own a component-only kit without becoming a module:

```php
Mod::layout('custom')->extends('modules')->path('app/Modules/{module}')
    ->mounts('ui', 'App\\UI\\', 'app/UI', fn (Root $root) => $root
        ->frontend(components: 'resources/js', alias: '@/UI'));
```

All `Root::frontend()` paths are relative to the mount. Pages opt in with both
`pages: 'screens'` and `pageName: 'UI::{path}'`; views opt in with both
`views: 'templates'` and `viewNamespace: 'ui'`. Names and aliases are explicit;
conflicts are rejected before installation. Existing layout-level frontend paths
retain their project-relative semantics. Omitted arguments preserve declarations,
and child layouts inherit their parent's owners.

Run the normal installation preview/apply step. The owner adds a specific Vite
alias before broader aliases, TypeScript paths/includes, Tailwind sources and
canonical page globs. Component files are dependencies, never executable inputs.
Views use Laravel's namespace/anonymous-component registration and its reserved
namespace protection. CSS imports, global component registration, layouts,
framework plugins and exclusions remain application-owned. `frontend_owners` in
`mod:list --json` exposes the resolved paths and identities.


### Static assets and executable entries

Declare narrow static patterns separately from styles or scripts that need
compilation:

```php
Mod::layout('modules')->assets(
    files: ['app/Modules/{module}/images/**', 'app/Modules/{module}/fonts/**'],
    entries: ['app/Modules/{module}/styles/editor.css'],
);
// Inside a mount: $root->assets(files: ['images/**'], entries: ['css/theme.css']);
```

Layout paths are project-relative; root paths are mount-relative. Static globs
support `*` and `**` plus declared layout dimensions. Entries are literal CSS/JS/TS
file templates expanded across existing owners; absent optional files are omitted
from inputs and reported in the inventory. Traversal, external paths, malformed
patterns, raw style/script file declarations and escaping symlinks are refused
before writing. Ordinary imports and CSS `url()`/`@import` dependencies continue
to use Vite's normal graph.

Preview with `mod:install assets --dry-run --json`, then apply with
`mod:install assets --no-interaction`. No Inertia stack is required. Automatic
static wiring requires `laravel-vite-plugin` 3; older versions retain ordinary
imports and receive manual instructions until an adapter is verified. Custom
Vite options receive exact instructions. Existing central inputs, static patterns
and plugin options are retained.

The installer owns `resources/mod-assets.json` and imports it into the Vite
configuration. Do not edit that generated file; change declarations and sync.
Adding static files beneath existing globs needs no sync. Adding or removing
independent entries requires another explicit installation before building.
Repeated sync is a no-op, and removed owned entries disappear from the generated
list. Unrelated application inputs remain intact.

Reference images/fonts from JS with imports, or from Blade with
`Vite::asset('app/Modules/Editor/images/logo.svg')`. Select independent stylesheet
loading with `@vite('app/Modules/Editor/styles/editor.css')`. Building that entry
does not load it globally. Use Laravel's usual build-directory/CDN configuration.
Declaring images/fonts neither imports CSS nor adds Tailwind sources. CSS themes,
class sources, import order and loading remain application-owned. The additive
`assets` inventory records declarations, expanded entries, existence and confirmed
wiring; it makes no claim that a production build exists.

The supported build fixture can be verified with `npm ci --prefix
 tests/Fixtures/frontend-build --ignore-scripts` and
`MOD_FRONTEND_BUILD=1 vendor/bin/pest tests/Feature/Install/FrontendBuildTest.php`.
It checks alias resolution, TypeScript, Tailwind, static manifest keys, nested CSS
references, development serving and Laravel references with a custom build path.
