<?php

use Tey\Mod\Discovery\DiscoveryType;
use Tey\Mod\Facades\Mod;
use Tey\Mod\Generation\Stub;
use Tey\Mod\Layout\LayoutRegistry;
use Tey\Mod\Layout\Root;
use Tey\Mod\Templates\ClassLookup;
use Tey\Mod\Tests\Feature\Acceptance\Support\AcceptanceApp;
use Tey\Mod\Tests\Feature\Acceptance\Support\LayoutUnderTest;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;

it('generates locates discovers and lists an explicit mount inside an inherited exclusion', function (string $namespace, string $path) {
    $layout = new LayoutUnderTest('teylabs', fn () => Mod::layout('teylabs')
        ->extends('modules')
        ->mounts('kit', $namespace, $path, fn (Root $r) => $r
            ->generates('kit-provider', in: 'Providers', suffix: 'ServiceProvider', command: 'mod:kit-provider', stub: Stub::file(__DIR__.'/../../../vendor/laravel/framework/src/Illuminate/Foundation/Console/stubs/provider.stub'))));

    AcceptanceApp::run($layout, function (AcceptanceApp $app) use ($namespace, $path) {
        $app->boot();
        $name = 'Kit'.$app->tag;
        $file = $path.'/Providers/'.$name.'ServiceProvider.php';
        $class = $namespace.'Providers\\'.$name.'ServiceProvider';
        $app->artisan('mod:kit-provider', ['name' => $name, '--dry-run' => true])->assertSuccessful()->expectsOutputToContain($file);
        expect(is_file($app->root->path($file)))->toBeFalse();
        $app->artisan('mod:kit-provider', ['name' => $name])->assertSuccessful();
        $app->assertOwned($file, 'kit-provider', [], $class);
        expect($app->preset->locate($class)?->path())->toBe($file);
        $sibling = 'Outside'.$app->tag;
        $app->handWrite('app/Support/'.$sibling.'.php', 'App\\Support', 'class '.$sibling.' extends \\Illuminate\\Support\\ServiceProvider {}');
        $app->boot();
        expect($app->discovery()->inventory()->classes(DiscoveryType::Provider))->toBe([$class])
            ->and($app->app()->getProvider($class))->not->toBeNull()
            ->and($app->app()->getProvider('App\\Support\\'.$sibling))->toBeNull()
            ->and($app->mapPath('app/Support/'.$sibling.'.php')->reason)->toContain('excluded')
            ->and($app->mapClass('App\\Support\\'.$sibling)->reason)->toContain('excluded');
        $records = (new ClassLookup($app->root->path, $app->preset))->all();
        expect(array_column($records, 'class'))->toContain($class);
        expect(array_column($records, 'class'))->not->toContain('App\\Support\\'.$sibling);
        $data = json_decode($app->artisan('mod:list', ['--json' => true])->assertSuccessful()->output, true, flags: JSON_THROW_ON_ERROR);
        expect($data['discovery']['counts']['provider'])->toBe(1)
            ->and(array_column($data['discovery']['entries'], 'class'))->toContain($class);
    }, ['file_types' => ['kit-provider' => 'provider']]);
})->with([
    'equal namespace' => ['App\\UI\\', 'app/UI'],
    'broader namespace preserves siblings' => ['App\\Support\\Kit\\', 'app/Support/Kit'],
]);

it('indexes carved subtrees with relative and absolute root paths', function (bool $absolute) {
    Workspace::run(null, function (Workspace $w) use ($absolute) {
        $w->write('app/Support/Kit/Thing.php', '<?php namespace App\\Support\\Kit; class Thing {}');
        $w->write('app/Support/Other/Hidden.php', '<?php namespace App\\Support\\Other; class Hidden {}');
        $appPath = $absolute ? $w->root->path('app') : 'app';
        $registry = new LayoutRegistry;
        $registry->layout('parent')->path('app')->mounts('app', 'App\\', $appPath)->excludes($appPath.'/Support');
        $compiled = $registry->layout('child')->extends('parent')->mounts('kit', 'App\\Support\\Kit\\', $appPath.'/Support/Kit')->compile();
        expect(array_column((new ClassLookup($w->root->path, $compiled))->all(), 'class'))->toBe(['App\\Support\\Kit\\Thing']);
    });
})->with([false, true]);
