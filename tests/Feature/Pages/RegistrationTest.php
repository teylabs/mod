<?php

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Route;
use Illuminate\View\FileViewFinder;
use Inertia\Inertia;
use Inertia\ServiceProvider;
use Inertia\Testing\AssertableInertia;
use Pest\TestSuite;
use Tey\Mod\Facades\Mod;
use Tey\Mod\Pages\PageDirectories;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;
use Tey\Mod\Tests\Support\OwnedAppRoot;
use Tey\Mod\Tests\TestCase;

it('registers page namespaces on every Inertia finder without class discovery', function () {
    OwnedAppRoot::using(function (OwnedAppRoot $root) {
        mkdir($root->path('app/Modules/Showroom/resources/js/Pages/Recipe'), 0700, true);
        mkdir($root->path('resources/js/Pages'), 0700, true);
        mkdir($root->path('resources/views'), 0700, true);
        mkdir($root->path('storage/framework/views'), 0700, true);
        file_put_contents($root->path('resources/views/app.blade.php'), '@inertia');
        file_put_contents($root->path('package.json'), '{"dependencies":{"@inertiajs/vue3":"^2"}}');
        file_put_contents($root->path('app/Modules/Showroom/resources/js/Pages/Recipe/Edit.vue'), '<template />');
        $case = TestSuite::getInstance()->test;
        if (! $case instanceof TestCase) {
            throw new RuntimeException('Expected the package Testbench case.');
        }
        $case->bootApplicationUsing(function (Application $app) use ($root) {
            $app->setBasePath($root->path);
            $app->make('config')->set('mod.layout', 'modules');
            $app->make('config')->set('view.paths', [$root->path('resources/views')]);
            $app->make('config')->set('view.compiled', $root->path('storage/framework/views'));
        });
        app()->register(ServiceProvider::class);
        $checksPages = config()->has('inertia.ensure_pages_exist') || config()->has('inertia.pages.ensure_pages_exist');
        config()->set('inertia.pages.ensure_pages_exist', true);
        config()->set('inertia.ensure_pages_exist', true);
        config()->set('inertia.testing.ensure_pages_exist', true);
        config()->set('inertia.ssr.enabled', false);
        $bindings = array_filter(['inertia.view-finder', 'inertia.testing.view-finder'], fn (string $binding): bool => app()->bound($binding));
        foreach ($bindings as $binding) {
            foreach (range(1, 2) as $iteration) {
                expect(app($binding)->find('Showroom::Recipe/Edit'))
                    ->toEqualPath($root->path('app/Modules/Showroom/resources/js/Pages/Recipe/Edit.vue'));
            }
            expect(fn () => app($binding)->find('Showroom::Recipe/Missing'))->toThrow(InvalidArgumentException::class);
        }
        if ($checksPages) {
            expect(fn () => Inertia::render('Showroom::Recipe/Missing'))->toThrow(Exception::class);
        }
        Route::get('/page', fn () => Inertia::render('Showroom::Recipe/Edit'));
        $case->get('/page')->assertSuccessful()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Showroom::Recipe/Edit'));
    });
});

it('uses custom frontend paths and qualified page names for nested groups', function () {
    Workspace::run(null, function (Workspace $workspace) {
        config()->set('mod.layout', 'web-ddd');
        Mod::layout('web-ddd')->extends('ddd')
            ->frontend(pages: 'app/Modules/{domain}/ui/screens', pageName: '{domain}::{path}');
        $workspace->write('app/Modules/Knowledge/Search/ui/screens/Index.tsx', 'export default () => null;');
        $workspace->write('app/Modules/Empty/Models/Document.php', '<?php');
        app()->register(ServiceProvider::class);
        $binding = app()->bound('inertia.view-finder') ? 'inertia.view-finder' : 'inertia.testing.view-finder';
        expect(app($binding)->find('Knowledge/Search::Index'))
            ->toEqualPath($workspace->root->path('app/Modules/Knowledge/Search/ui/screens/Index.tsx'));
        expect(app($binding)->getHints())->not->toHaveKey('Empty');
    });
});

it('preserves an application-owned page namespace and warns on a clash', function () {
    Workspace::run(null, function (Workspace $workspace) {
        config()->set('mod.layout', 'modules');
        $workspace->write('app/Modules/Inventory/resources/js/pages/Index.vue', '<template />');
        $workspace->write('original/Index.vue', '<template />');
        app()->bind('inertia.view-finder', function () use ($workspace) {
            $finder = new FileViewFinder(app('files'), [], ['vue']);
            $finder->addNamespace('Inventory', $workspace->root->path('original'));

            return $finder;
        });
        $warnings = [];
        app('log')->listen(function ($event) use (&$warnings) {
            $warnings[] = $event->message;
        });
        expect(app('inertia.view-finder')->find('Inventory::Index'))->toEqualPath($workspace->root->path('original/Index.vue'));
        expect(implode("\n", $warnings))->toContain('Inventory', 'already registered');
    });
});

it('expands only template candidates and shares their cache across transient finders', function () {
    Workspace::run(null, function (Workspace $workspace) {
        config()->set('mod.layout', 'modules');
        $workspace->write('app/Modules/Inventory/resources/js/pages/Index.vue', '<template />');
        foreach (['Models', 'Http'] as $folder) {
            $workspace->write('app/Modules/Inventory/'.$folder.'/Deep/Unrelated/Tree/File.php', '<?php');
        }
        $workspace->write('app/Modules/Empty/Models/Deep/Tree/File.php', '<?php');
        $files = new class extends Filesystem
        {
            /** @var list<string> */
            public array $listed = [];

            /**
             * @param  array<int|string>|int|string  $depth
             * @return list<string>
             */
            public function directories($directory, array|string|int $depth = 0)
            {
                $this->listed[] = $directory;

                return parent::directories($directory, $depth);
            }
        };
        app()->instance('files', $files);
        app()->bind('inertia.view-finder', fn () => new FileViewFinder($files, [], ['vue']));
        app()->bind('inertia.testing.view-finder', fn () => new FileViewFinder($files, [], ['vue']));
        $directories = app(PageDirectories::class);
        foreach (['inertia.view-finder', 'inertia.testing.view-finder', 'inertia.view-finder'] as $binding) {
            expect(app($binding)->find('Inventory::Index'))
                ->toEqualPath($workspace->root->path('app/Modules/Inventory/resources/js/pages/Index.vue'));
        }
        expect($directories->namespaces())->toHaveKeys(['Inventory'])->toHaveCount(1)
            ->and($files->listed)->toHaveCount(1)
            ->and($files->listed[0])->toEqualPath($workspace->root->path('app/Modules'))
            ->and(app(PageDirectories::class))->toBe($directories);
        app()->forgetScopedInstances();
        expect(app(PageDirectories::class))->not->toBe($directories);
    });
});
