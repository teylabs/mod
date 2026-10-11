<?php

use Acme\Kit\KitServiceProvider;
use Illuminate\Foundation\Application;
use Inertia\ServiceProvider;
use Pest\TestSuite;
use Symfony\Component\Process\Process;
use Tey\Mod\Facades\Mod;
use Tey\Mod\Pages\PageDirectories;
use Tey\Mod\Support\Path;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;
use Tey\Mod\Tests\Support\OwnedAppRoot;
use Tey\Mod\Tests\TestCase;

it('boots a Composer symlinked package and keeps installed paths in every plan', function () {
    OwnedAppRoot::using(function (OwnedAppRoot $package) {
        app('files')->copyDirectory(__DIR__.'/../../Fixtures/path-package', $package->path);
        Workspace::run(null, function (Workspace $w) use ($package) {
            $w->write('composer.json', json_encode([
                'name' => 'acme/app',
                'require' => ['acme/kit' => '1.0.0'],
                'repositories' => [
                    ['type' => 'path', 'url' => $package->path, 'options' => ['symlink' => true]],
                    ['packagist.org' => false],
                ],
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            $process = new Process(['composer', 'install', '--no-interaction', '--no-plugins', '--no-scripts'], $w->root->path, timeout: 120);
            $process->mustRun();
            expect(Path::isLink($w->root->path('vendor/acme/kit')))->toBeTrue()
                ->and(realpath($w->root->path('vendor/acme/kit')))->toBe(realpath($package->path));
            require_once $w->root->path('vendor/acme/kit/src/KitServiceProvider.php');
            $w->write('package.json', '{"dependencies":{"@inertiajs/vue3":"^2"}}');
            $w->write('resources/js/app.ts', "createInertiaApp({\n});\n");
            $w->write('vite.config.ts', "export default defineConfig({\n    plugins: [laravel({ refresh: true })],\n});\n");
            $w->write('tsconfig.json', "{\n    \"compilerOptions\": {\n        \"paths\": {}\n    },\n    \"include\": []\n}\n");
            $w->write('resources/css/app.css', '@import "tailwindcss";');
            $case = TestSuite::getInstance()->test;
            if (! $case instanceof TestCase) {
                throw new RuntimeException('Expected the package Testbench case.');
            }
            $case->bootApplicationUsing(function ($app) use ($w) {
                if (! $app instanceof Application) {
                    throw new RuntimeException('Expected a Laravel application.');
                }
                $app->setBasePath($w->root->path);
                $app->make('config')->set('mod.layout', 'laravel');
                $app->register(KitServiceProvider::class);
                $app->register(ServiceProvider::class);
            });
            expect(app()->isBooted())->toBeTrue()
                ->and(app(PageDirectories::class)->notices())->toBe([])
                ->and(Mod::pageEntry('acme::Index', 'vue'))->toBe('vendor/acme/kit/resources/js/pages/Index.vue');
            foreach (['inertia.view-finder', 'inertia.testing.view-finder'] as $binding) {
                if (app()->bound($binding)) {
                    expect(app($binding)->find('acme::Index'))->toEqualPath($w->root->path('vendor/acme/kit/resources/js/pages/Index.vue'));
                }
            }
            $plan = json_decode($w->artisan('mod:install', ['stack' => 'inertia', '--dry-run' => true, '--json' => true])->assertSuccessful()->output, true, flags: JSON_THROW_ON_ERROR);
            expect($plan['warnings'])->toBe([]);
            $after = implode("\n", array_column(array_column($plan['files'], 'identity'), 'after'));
            expect($after)->toContain('../../vendor/acme/kit/resources/js/pages/**/*.vue', './vendor/acme/kit/resources/js', '../../vendor/acme/kit/resources/kit.css');
            expect(str_contains($after, $package->path))->toBeFalse();
            $w->artisan('mod:install', ['stack' => 'inertia'])->assertSuccessful();
            expect($w->artisan('mod:install', ['stack' => 'inertia'])->assertSuccessful()->output)->toContain('Already wired.');
        });
        expect($package->exists())->toBeTrue()
            ->and(is_file($package->path('resources/js/index.ts')))->toBeTrue();
    });
});

it('boots with unusable declarations and reports stable notices without planning their paths', function () {
    Workspace::run(null, function (Workspace $w) {
        $case = TestSuite::getInstance()->test;
        if (! $case instanceof TestCase) {
            throw new RuntimeException('Expected the package Testbench case.');
        }
        $case->bootApplicationUsing(function ($app) use ($w) {
            if (! $app instanceof Application) {
                throw new RuntimeException('Expected a Laravel application.');
            }
            $app->setBasePath($w->root->path);
            $app->make('config')->set('mod.layout', 'laravel');
            $app->booting(function () {
                Mod::pages('outside', '/outside/pages')->pages('missing', 'missing/pages')->pages('bad::name', 'pages');
                Mod::wiring()->alias('@outside', '/outside/js')->source('../../elsewhere')->css('/outside/style.css')->alias('bad*alias', 'resources/js');
            });
        });
        expect(app()->isBooted())->toBeTrue()->and(app(PageDirectories::class)->namespaces())->toBe([]);
        $list = json_decode($w->artisan('mod:list', ['--json' => true])->assertSuccessful()->output, true, flags: JSON_THROW_ON_ERROR);
        expect($list['package_pages'])->toBe([])->and($list['frontend_wiring'])->toBe([])
            ->and(array_column($list['frontend_notices'], 'code'))->toContain('frontend_path_invalid', 'frontend_path_unavailable', 'page_namespace_invalid', 'frontend_alias_invalid')
            ->and(array_column($list['frontend_notices'], 'path'))->toContain('/outside/pages', 'missing/pages', '../../elsewhere');
        $plan = json_decode($w->artisan('mod:install', ['stack' => 'inertia', '--dry-run' => true, '--json' => true])->assertSuccessful()->output, true, flags: JSON_THROW_ON_ERROR);
        expect($plan['files'])->toBe([])->and(array_column($plan['warnings'], 'code'))->toBe(array_column([...Mod::wiring()->notices(), ...app(PageDirectories::class)->notices()], 'code'));
        expect($w->artisan('mod:install', ['stack' => 'inertia', '--dry-run' => true])->assertSuccessful()->output)->toContain('Skipped frontend declaration [/outside/pages]');
    });
});

it('skips non-vendor symlink escapes and dangling links while installing valid wiring', function () {
    OwnedAppRoot::using(function (OwnedAppRoot $outside) {
        Workspace::run(null, function (Workspace $w) use ($outside) {
            config()->set('mod.layout', 'laravel');
            symlink($outside->path, $w->root->path('linked'));
            mkdir($outside->path('missing'));
            symlink($outside->path('missing'), $w->root->path('broken'));
            rmdir($outside->path('missing'));
            clearstatcache();
            $w->write('vite.config.js', "export default defineConfig({\n});\n");
            Mod::pages('escape', $w->root->path('linked'))->pages('broken', $w->root->path('broken'));
            Mod::wiring()->alias('@escape', 'linked')->source('broken')->alias('@valid', 'resources/js')->alias('@valid', 'other/js');
            $list = json_decode($w->artisan('mod:list', ['--json' => true])->assertSuccessful()->output, true, flags: JSON_THROW_ON_ERROR);
            expect($list['package_pages'])->toBe([])->and(array_column($list['frontend_notices'], 'code'))->toContain('frontend_path_escape', 'frontend_path_unavailable', 'frontend_alias_conflict');
            $plan = Mod::wiring()->plan();
            expect($plan->toArray()['would_write'])->toBeTrue()->and($plan->apply())->toBeTrue()
                ->and($w->read('vite.config.js'))->toContain("'@valid':");
            expect(str_contains($w->read('vite.config.js'), '@escape'))->toBeFalse();
        });
    });
});
