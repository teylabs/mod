<?php

use Inertia\ServiceProvider;
use Tey\Mod\Facades\Mod;
use Tey\Mod\Layout\LayoutRegistry;
use Tey\Mod\Layout\Root;
use Tey\Mod\Listing\InventorySectionRegistry;
use Tey\Mod\Scaffolds\Scaffold;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;
use Tey\Mod\Tests\Feature\Install\Kit;
use Tey\Mod\Tests\Support\JsonSchema;

it('keeps null entry declarations byte-identical for starter wiring identities and inventory', function (string $kit) {
    Workspace::run(null, function (Workspace $w) use ($kit) {
        Kit::setup($w, $kit);
        Mod::layout('modules')->frontend(pageEntries: null);
        $inventory = json_decode($w->artisan('mod:list', ['--json' => true])->assertSuccessful()->output, true, flags: JSON_THROW_ON_ERROR);
        $identity = json_decode($w->artisan('mod:page', ['name' => 'Inventory:Widget/Layout', '--dry-run' => true, '--json' => true])->assertSuccessful()->output, true, flags: JSON_THROW_ON_ERROR)['files'][0]['identity'];
        expect($inventory['frontend'])->not->toHaveKey('page_entries');
        expect($identity['name'])->toBe(str_starts_with($kit, 'react') ? 'Inventory::widget/layout' : 'Inventory::Widget/Layout');
        $w->artisan('mod:page', ['name' => 'Inventory:Widget/Layout'])->assertSuccessful()
            ->expectsOutputToContain('Page ['.$identity['path']."] created successfully. Render it with Inertia::render('".$identity['name']."').");
        $w->artisan('mod:install', ['stack' => 'inertia'])->assertSuccessful();
        foreach (Kit::files($kit, 'after') as $file => $contents) {
            expect($w->read($file))->toBe($contents);
        }
        $w->artisan('mod:install', ['stack' => 'inertia'])->assertSuccessful()->expectsOutputToContain('Already wired.');
    });
})->with(['vue-laravel12', 'react-laravel12', 'vue-current', 'react-current']);

it('installs literal entry globs for app module mounted and package owners and repeats without edits', function (string $kit, string $extension) {
    Workspace::run(null, function (Workspace $w) use ($kit, $extension) {
        Kit::setup($w, $kit);
        Mod::layout('modules')->frontend(pageEntries: ['Page', 'Modal'])
            ->mounts('ui', null, 'app/UI', fn (Root $root) => $root->frontend(pages: 'pages', pageName: 'UI::{path}', pageEntries: ['Page', 'Modal']));
        $w->write('app/UI/pages/Widget/Page.'.$extension, '');
        $w->write('vendor/acme/billing/pages/Invoice/Page.'.$extension, '');
        Mod::pages('billing', $w->root->path('vendor/acme/billing/pages'), entries: ['Page', 'Modal']);
        $w->artisan('mod:install', ['stack' => 'inertia'])->assertSuccessful();
        $entry = $w->read('resources/js/app.'.($extension === 'tsx' ? 'tsx' : 'ts'));
        foreach (['./pages', '../../app/Modules/*/resources/js/pages', '../../app/UI/pages', '../../vendor/acme/billing/pages'] as $directory) {
            expect($entry)->toContain($directory.'/**/Page.'.$extension, $directory.'/**/Modal.'.$extension);
            expect($entry)->not->toContain($directory.'/**/*.'.$extension);
        }
        $before = $entry;
        $w->artisan('mod:install', ['stack' => 'inertia'])->assertSuccessful()->expectsOutputToContain('Already wired.');
        expect($w->read('resources/js/app.'.($extension === 'tsx' ? 'tsx' : 'ts')))->toBe($before);
        $data = json_decode($w->artisan('mod:list', ['--json' => true])->assertSuccessful()->output, true, flags: JSON_THROW_ON_ERROR);
        expect($data['frontend']['page_entries'])->toBe(['Page', 'Modal'])
            ->and($data['frontend_owners'][0]['page_entries'])->toBe(['Page', 'Modal'])
            ->and($data['package_pages'][0]['page_entries'])->toBe(['Page', 'Modal'])
            ->and($data['wiring']['inertia'])->toBeTrue()
            ->and(JsonSchema::errors($data, (new InventorySectionRegistry)->schema()))->toBe([]);
        $data['frontend']['page_entries'] = 'Page';
        expect(JsonSchema::errors($data, (new InventorySectionRegistry)->schema()))->not->toBeEmpty();
    });
})->with([['vue-laravel12', 'vue'], ['react-laravel12', 'tsx'], ['vue-current', 'vue'], ['react-current', 'tsx']]);

it('refuses non-entries with their path while leaving backend page finders unfiltered', function () {
    Workspace::run(null, function (Workspace $w) {
        Kit::setup($w);
        Mod::layout('modules')->frontend(pageEntries: ['Page', 'Modal'])
            ->mounts('ui', null, 'app/UI', fn (Root $root) => $root->frontend(pages: 'pages', pageName: 'UI::{path}', pageEntries: ['Page']));
        $owners = ['' => 'resources/js/pages', 'Inventory::' => 'app/Modules/Inventory/resources/js/pages', 'UI::' => 'app/UI/pages', 'billing::' => 'vendor/acme/billing/pages'];
        foreach ($owners as $prefix => $directory) {
            foreach (['Page', 'Modal', 'Layout', 'Partials/Card', 'Page/Helper'] as $name) {
                $w->write($directory.'/Widget/Edit/'.$name.'.vue', '<template />');
            }
        }
        Mod::pages('billing', $w->root->path($owners['billing::']), entries: ['Modal']);
        app()->register(ServiceProvider::class);
        foreach ($owners as $prefix => $directory) {
            $entry = $prefix === 'billing::' ? 'Modal' : 'Page';
            expect(Mod::pageEntry($prefix.'Widget/Edit/'.$entry, 'vue'))->toBe($directory.'/Widget/Edit/'.$entry.'.vue');
            foreach (['Layout', 'Partials/Card', 'Page/Helper'] as $name) {
                expect(fn () => Mod::pageEntry($prefix.'Widget/Edit/'.$name, 'vue'))->toThrow(InvalidArgumentException::class, $directory.'/Widget/Edit/'.$name.'.vue');
            }
            if ($prefix !== '') {
                foreach (['inertia.view-finder', 'inertia.testing.view-finder'] as $binding) {
                    if (app()->bound($binding)) {
                        expect(app($binding)->find($prefix.'Widget/Edit/Layout'))->toEqualPath($w->root->path($directory.'/Widget/Edit/Layout.vue'));
                    }
                }
            }
        }
        expect(fn () => Mod::pageEntry('UI::Widget/Edit/Modal', 'vue'))->toThrow(InvalidArgumentException::class)
            ->and(fn () => Mod::pageEntry('billing::Widget/Edit/Page', 'vue'))->toThrow(InvalidArgumentException::class);
    });
});

it('keeps verbatim render names only on included stems and preserves component paths and imports', function (array $entries, string $name, bool $included) {
    Workspace::run(null, function (Workspace $w) use ($entries, $name, $included) {
        Kit::setup($w);
        Mod::layout('modules')->frontend(pageEntries: $entries);
        $plan = json_decode($w->artisan('mod:page', ['name' => 'Inventory:Widget/Edit/'.$name, '--dry-run' => true, '--json' => true])->assertSuccessful()->output, true, flags: JSON_THROW_ON_ERROR);
        $forms = $plan['files'][0]['identity'];
        expect($forms['path'])->toBe('app/Modules/Inventory/resources/js/pages/Widget/Edit/'.$name.'.vue')
            ->and($forms['import'])->toBe('@modules/Inventory/resources/js/pages/Widget/Edit/'.$name.'.vue');
        if ($included) {
            expect($forms['name'])->toBe('Inventory::Widget/Edit/'.$name);
        } else {
            expect($forms)->not->toHaveKey('name');
        }
        $result = $w->artisan('mod:page', ['name' => 'Inventory:Widget/Edit/'.$name])->assertSuccessful();
        expect($w->read($forms['path']))->toContain('<template>');
        if ($included) {
            $result->expectsOutputToContain('Page ['.$forms['path']."] created successfully. Render it with Inertia::render('Inventory::Widget/Edit/".$name."').");
            expect($result->output)->not->toContain('Not a page entry');
        } else {
            $result->expectsOutputToContain('Page ['.$forms['path'].'] created successfully. Not a page entry; import it from a page.');
            expect($result->output)->not->toContain('Inertia::render');
        }
    });
})->with([
    [['Page', 'Modal'], 'Page', true],
    [['Page', 'Modal'], 'Modal', true],
    [['Page', 'Modal'], 'Layout', false],
    [['Page', 'Modal'], 'Partials/Card', false],
    [['Page*'], 'PageDetail', true],
    [['Page*'], 'NotPage', false],
    [['*'], 'Layout', true],
    [[], 'Page', false],
]);

it('generates non-entry page scaffold members without rolling back earlier files', function (bool $tree) {
    Workspace::run(null, function (Workspace $w) use ($tree) {
        Kit::setup($w);
        Mod::layout('modules')->frontend(pageEntries: ['Page', 'Modal']);
        $w->write('stubs/mod.page.vue.stub', '<template><p>{{ name.studly }}</p></template>');
        Mod::scaffold('pages', function (Scaffold $s) use ($tree): void {
            $s->makes('page', name: '{name}/Edit/Page', as: 'entry')
                ->makes('page', name: '{name}/Edit/Layout', as: 'layout')
                ->makes('page', name: '{name}/Edit/Modal', as: 'modal');
            if ($tree) {
                $s->part('tab');
            }
        });
        $before = $w->files();
        $plan = json_decode($w->artisan('mod:pages', ['name' => 'Inventory:Widget', '--dry-run' => true, '--json' => true])->assertSuccessful()->output, true, flags: JSON_THROW_ON_ERROR);
        $identities = array_column($plan['files'], 'identity', 'alias');
        expect($plan['would_write'])->toBeTrue()->and($plan['warnings'])->toBe([])
            ->and($identities['entry']['name'])->toBe('Inventory::Widget/Edit/Page')
            ->and($identities['modal']['name'])->toBe('Inventory::Widget/Edit/Modal')
            ->and($identities['layout'])->not->toHaveKey('name')
            ->and($identities['layout']['import'])->toBe('@modules/Inventory/resources/js/pages/Widget/Edit/Layout.vue')
            ->and($w->files())->toBe($before);

        $result = $w->artisan('mod:pages', ['name' => 'Inventory:Widget'])->assertSuccessful();
        foreach (['Page', 'Layout', 'Modal'] as $stem) {
            expect($w->read('app/Modules/Inventory/resources/js/pages/Widget/Edit/'.$stem.'.vue'))->toBe('<template><p>Widget</p></template>');
        }
        $result->expectsOutputToContain('Not a page entry; import it from a page.')
            ->expectsOutputToContain("Render it with Inertia::render('Inventory::Widget/Edit/Page').")
            ->expectsOutputToContain("Render it with Inertia::render('Inventory::Widget/Edit/Modal').");
        expect($result->output)->not->toContain("Inertia::render('Inventory::Widget/Edit/Layout')");
    });
})->with(['flat' => false, 'tree' => true]);

it('uses a mounted page owners entry list for generated identities', function (?array $entries) {
    Workspace::run(null, function (Workspace $w) use ($entries) {
        Kit::setup($w);
        Mod::layout('modules')->frontend(pageEntries: ['Modal'])
            ->mounts('ui', null, 'app/UI', fn (Root $root) => $root
                ->frontend(pages: 'pages', pageName: 'UI::{path}', pageEntries: $entries)
                ->generates('page', in: 'pages', nested: true));
        foreach (['Page' => 'UI::Widget/Page', 'Modal' => null, 'Layout' => null] as $stem => $name) {
            $plan = json_decode($w->artisan('mod:page', ['name' => 'Widget/'.$stem, '--dry-run' => true, '--json' => true])->assertSuccessful()->output, true, flags: JSON_THROW_ON_ERROR);
            expect($plan['warnings'])->toBe([]);
            expect($plan['files'][0]['identity']['path'])->toBe('app/UI/pages/Widget/'.$stem.'.vue')
                ->and($plan['files'][0]['identity']['name'] ?? null)->toBe($entries === null ? 'UI::Widget/'.$stem : $name);
        }
    });
})->with([[['Page']], [null]]);

it('installs wildcard and empty entry lists for mirrored app pages', function (array $entries, string $glob) {
    Workspace::run(null, function (Workspace $w) use ($entries, $glob) {
        Kit::setup($w);
        Mod::layout('modules')->frontend(pages: 'resources/js/pages/{module}', pageName: '{module}/{path}', pageEntries: $entries);
        $w->artisan('mod:install', ['stack' => 'inertia'])->assertSuccessful();
        expect($w->read('resources/js/app.ts'))->toContain($glob);
        expect($w->read('resources/js/app.ts'))->not->toContain('pages/**/*.vue');
        $w->artisan('mod:install', ['stack' => 'inertia'])->assertSuccessful()->expectsOutputToContain('Already wired.');
    });
})->with([[['Page*'], '["./pages/**/Page*.vue"]'], [[], 'glob<DefineComponent>([])']]);

it('preserves hand-written exclusions and supplies exact inclusion instructions without writes', function () {
    Workspace::run(null, function (Workspace $w) {
        Kit::setup($w);
        Mod::layout('modules')->frontend(pageEntries: ['Page', 'Modal']);
        $custom = <<<'JS'
import { resolveModulePage } from '../../vendor/tey/mod/resources/js/inertia';
createInertiaApp({
    resolve: (name) => resolveModulePage(name,
        import.meta.glob(['./pages/**/*.vue', '!**/Layout.vue', '!**/Partials/*.vue']),
        import.meta.glob(['../../app/Modules/*/resources/js/pages/**/*.vue', '!**/Layout.vue', '!**/Partials/*.vue'])),
});
JS;
        $w->write('resources/js/app.ts', $custom);
        $before = [];
        foreach ($w->files() as $file) {
            $before[$file] = $w->read($file);
        }
        $w->artisan('mod:install', ['stack' => 'inertia'])->assertSuccessful()
            ->expectsOutputToContain("couldn't confirm [resources/js/app.ts]")
            ->expectsOutputToContain('./pages/**/Page.vue')
            ->expectsOutputToContain('../../app/Modules/*/resources/js/pages/**/Modal.vue');
        foreach ($before as $file => $contents) {
            expect($w->read($file))->toBe($contents);
        }
    });
});

it('inherits entry declarations and preserves them through compilation and serialization', function () {
    $registry = new LayoutRegistry;
    $registry->layout('parent')->extends('modules')->frontend(pageEntries: ['Page*'])
        ->mounts('ui', null, 'app/UI', fn (Root $root) => $root->frontend(pages: 'pages', pageName: 'UI::{path}', pageEntries: ['Modal']));
    $registry->layout('children')->extends('parent')->frontend(components: '@child/ui/components');
    $compiled = unserialize(serialize($registry->compile('children')));
    expect($compiled->frontend()['page_entries'])->toBe(['Page*'])
        ->and($compiled->frontendOwners()[0]['page_entries'])->toBe(['Modal']);
});

it('refuses entry paths and unsupported glob syntax at their declaration', function (string $stem) {
    expect(fn () => Mod::layout('invalid-entries')->frontend(pageEntries: [$stem]))->toThrow(InvalidArgumentException::class, 'invalid-entries');
})->with(['../Page', 'Widget/Page', 'Page.vue', '!Layout', 'Page?', '[Page]', '', "Page\n"]);

it('reports malformed package entry lists without preventing valid registrations', function () {
    Workspace::run(null, function (Workspace $w) {
        Kit::setup($w);
        $w->write('vendor/acme/billing/pages/Page.vue', '<template />');
        Mod::pages('invalid', $w->root->path('vendor/acme/billing/pages'), entries: ['!Layout']);
        Mod::pages('billing', $w->root->path('vendor/acme/billing/pages'), entries: ['Page']);
        $data = json_decode($w->artisan('mod:list', ['--json' => true])->assertSuccessful()->output, true, flags: JSON_THROW_ON_ERROR);
        expect($data['package_pages'])->toBe([['namespace' => 'billing', 'path' => 'vendor/acme/billing/pages', 'page_entries' => ['Page']]])
            ->and(array_column($data['frontend_notices'], 'code'))->toContain('page_entries_invalid');
        expect($data['frontend_notices'][0]['path'])->toEqualPath($w->root->path('vendor/acme/billing/pages'));
        expect(Mod::pageEntry('billing::Page', 'vue'))->toBe('vendor/acme/billing/pages/Page.vue');
        expect(fn () => Mod::pageEntry('invalid::Page', 'vue'))->toThrow(InvalidArgumentException::class, 'invalid');
    });
});
