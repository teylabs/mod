<?php

use Illuminate\Foundation\Vite;
use Illuminate\Support\Facades\Blade;
use Tey\Mod\Facades\Mod;
use Tey\Mod\Pages\PageDirectories;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;

it('renders a full-page Vite entry for a qualified page from the manifest', function () {
    Workspace::run(null, function (Workspace $workspace) {
        config()->set('mod.layout', 'modules');
        $workspace->write('package.json', '{"dependencies":{"@inertiajs/vue3":"^2"}}');
        $workspace->write('resources/js/Pages/.gitkeep', '');
        $path = 'app/Modules/Showroom/resources/js/Pages/Recipe/Edit.vue';
        $workspace->write($path, '<template />');
        $workspace->write('public/build/manifest.json', json_encode([
            'resources/js/app.ts' => ['file' => 'assets/app.js', 'src' => 'resources/js/app.ts', 'isEntry' => true],
            $path => ['file' => 'assets/Edit.js', 'src' => $path, 'isEntry' => true],
        ], JSON_THROW_ON_ERROR));
        app(Vite::class)->useHotFile($workspace->root->path('public/missing-hot'));
        $html = Blade::render("@vite(['resources/js/app.ts', \\Tey\\Mod\\Facades\\Mod::pageEntry(\$page['component'], 'vue')])", [
            'page' => ['component' => 'Showroom::Recipe/Edit'],
        ]);
        expect($html)->toContain('assets/Edit.js', 'assets/app.js')
            ->and(Mod::pageEntry('Welcome', 'vue'))->toBe('resources/js/Pages/Welcome.vue');
        expect(fn () => Mod::pageEntry('Showroom::../Private', 'vue'))->toThrow(InvalidArgumentException::class);
        expect(fn () => Mod::pageEntry('Unknown::Recipe/Edit', 'vue'))->toThrow(InvalidArgumentException::class);
    });
});

it('maps React pages from an extended layout without hard-coded module paths', function () {
    Workspace::run(null, function (Workspace $workspace) {
        config()->set('mod.layout', 'areas');
        Mod::layout('areas')->extends('modules')->path('src/Areas/{area}')
            ->frontend(pages: '@area/ui/screens');
        $workspace->write('src/Areas/Billing/ui/screens/Invoice/Edit.tsx', 'export default () => null;');
        expect(Mod::pageEntry('Billing::Invoice/Edit', 'tsx'))->toBe('src/Areas/Billing/ui/screens/Invoice/Edit.tsx');
    });
});

it('reuses the request namespace cache for repeated full-page entries', function () {
    Workspace::run(null, function (Workspace $workspace) {
        config()->set('mod.layout', 'modules');
        $workspace->write('app/Modules/Inventory/resources/js/pages/Index.vue', '<template />');
        $directories = app(PageDirectories::class);
        expect($directories->namespaces())->toHaveKey('Inventory');
        // A new directory cannot enter the memoized result halfway through a request.
        $workspace->write('app/Modules/Later/resources/js/pages/Index.vue', '<template />');
        foreach (range(1, 3) as $iteration) {
            expect(Mod::pageEntry('Inventory::Index', 'vue'))->toBe('app/Modules/Inventory/resources/js/pages/Index.vue');
            expect(fn () => Mod::pageEntry('Later::Index', 'vue'))->toThrow(InvalidArgumentException::class);
        }
        app()->forgetScopedInstances();
        expect(Mod::pageEntry('Later::Index', 'vue'))->toBe('app/Modules/Later/resources/js/pages/Index.vue');
    });
});
