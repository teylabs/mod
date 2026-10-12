<?php

use Composer\Autoload\ClassLoader;
use Laravel\Mcp\Request;
use Symfony\Component\Process\Process;
use Tey\Mod\Boost\FitTool;
use Tey\Mod\Boost\SurveyTool;
use Tey\Mod\Exceptions\ModException;
use Tey\Mod\Support\Path;
use Tey\Mod\Survey\Scope;
use Tey\Mod\Tests\Feature\Boost\Scenario;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;
use Tey\Mod\Tests\Support\OpenedFiles;
use Tey\Mod\Tests\Support\OwnedAppRoot;

/**
 * Install the #116 path-package fixture into a workspace through Composer, so
 * vendor/acme/kit is a real symlink (or Windows junction) to a package outside
 * the app, then run the callback with the loader Composer generated.
 *
 * @param  callable(Workspace, OwnedAppRoot, ClassLoader): void  $callback
 */
function withPathPackage(callable $callback): void
{
    OwnedAppRoot::using(function (OwnedAppRoot $package) use ($callback) {
        app('files')->copyDirectory(__DIR__.'/../../Fixtures/path-package', $package->path);
        file_put_contents($package->path('src/Widget.php'), "<?php\nnamespace Acme\\Kit;\nclass Widget {}\nthrow new \\RuntimeException('OUTSIDE-BODY');\n");
        Workspace::run(null, function (Workspace $w) use ($package, $callback) {
            config()->set('mod.layout', 'laravel');
            $w->write('composer.json', json_encode([
                'name' => 'acme/app',
                'require' => ['acme/kit' => '1.0.0'],
                'repositories' => [
                    ['type' => 'path', 'url' => $package->path, 'options' => ['symlink' => true]],
                    ['packagist.org' => false],
                ],
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            (new Process(['composer', 'install', '--no-interaction', '--no-plugins', '--no-scripts'], $w->root->path, timeout: 120))->mustRun();
            expect(Path::isLink($w->root->path('vendor/acme/kit')))->toBeTrue();
            $w->write('app/Models/Post.php', '<?php namespace App\Models; use Acme\Kit\Widget; use Acme\Kit\KitServiceProvider; class Post { public Widget $w; }');
            $loader = new ClassLoader;
            foreach (require $w->root->path('vendor/composer/autoload_psr4.php') as $prefix => $paths) {
                $loader->addPsr4($prefix, $paths);
            }
            $loader->register();
            try {
                $callback($w, $package, $loader);
            } finally {
                $loader->unregister();
            }
        });
    });
}

it('classifies path-repository package classes as vendor-owned in the survey without opening them', function () {
    withPathPackage(function (Workspace $w, OwnedAppRoot $package) {
        $tool = app(SurveyTool::class);
        $data = [];
        $opened = OpenedFiles::during(function () use ($tool, &$data) {
            $data = Scenario::data($tool->handle(new Request), $tool);
        });
        expect(array_column($data['vendor'], 'path', 'class'))->toBe([
            'Acme\Kit\KitServiceProvider' => 'vendor/acme/kit/src/KitServiceProvider.php',
            'Acme\Kit\Widget' => 'vendor/acme/kit/src/Widget.php',
        ])->and($data['skipped'])->toBe([])
            ->and(json_encode($data))->not->toContain($package->path, 'OUTSIDE-BODY');
        $real = Path::normalize(realpath($package->path));
        foreach ($opened as $path) {
            expect(Path::relative($real, Path::normalize($path)))->toBeNull()
                ->and(Path::relative($package->path, Path::normalize($path)))->toBeNull()
                ->and(str_contains(Path::normalize($path), '/vendor/acme/kit/'))->toBeFalse();
        }
    });
});

it('classifies path-repository package classes as vendor-owned in the fit check without opening them', function () {
    withPathPackage(function (Workspace $w, OwnedAppRoot $package) {
        $tool = app(FitTool::class);
        $data = [];
        $opened = OpenedFiles::during(function () use ($tool, &$data) {
            $data = Scenario::data($tool->handle(new Request(['recipe' => ['name' => 'post', 'members' => [
                ['id' => 'model', 'path' => 'app/Models/Post.php', 'class' => 'App\Models\Post'],
            ]]])), $tool);
        });
        expect($data['owned'])->toBe([['member' => 'model', 'path' => 'app/Models/Post.php']])
            ->and(array_column($data['vendor'], 'path', 'class'))->toBe([
                'Acme\Kit\KitServiceProvider' => 'vendor/acme/kit/src/KitServiceProvider.php',
                'Acme\Kit\Widget' => 'vendor/acme/kit/src/Widget.php',
            ])->and(json_encode($data))->not->toContain($package->path, 'OUTSIDE-BODY');
        foreach ($opened as $path) {
            expect(str_contains(Path::normalize($path), '/vendor/acme/kit/'))->toBeFalse()
                ->and(Path::relative(Path::normalize(realpath($package->path)), Path::normalize($path)))->toBeNull();
        }
    });
});

it('does not fail on unusable vendor candidates and still refuses explicit outside reads', function () {
    withPathPackage(function (Workspace $w, OwnedAppRoot $package, ClassLoader $loader) {
        $loader->addClassMap(['Acme\Kit\Missing' => $w->root->path('vendor/acme/kit/src/Missing.php'), 'Acme\Kit\Hidden' => $w->root->path('vendor/acme/kit/.git/Hidden.php')]);
        $scope = new Scope($w->root->path);
        expect($scope->vendorPath('Acme\Kit\Widget'))->toBe('vendor/acme/kit/src/Widget.php')
            ->and($scope->vendorPath('Acme\Kit\Missing'))->toBeNull()
            ->and($scope->vendorPath('Acme\Kit\Hidden'))->toBeNull()
            ->and($scope->skipped)->toBe([['path' => 'vendor/acme/kit/.git/Hidden.php', 'reason' => 'protected']])
            ->and(fn () => $scope->read('vendor/acme/kit/src/Widget.php'))->toThrow(ModException::class, 'base_path()');
    });
});
