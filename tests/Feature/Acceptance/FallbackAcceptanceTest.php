<?php

use Tey\Mod\Facades\Mod;
use Tey\Mod\Layout\Root;
use Tey\Mod\Tests\Feature\Acceptance\Support\AcceptanceApp;
use Tey\Mod\Tests\Feature\Acceptance\Support\LayoutUnderTest;

it('generates, reverse maps and discovers both fallback and placed commands', function () {
    $layout = new LayoutUnderTest('fallback', fn () => Mod::layout('fallback')
        ->mounts('app', 'App\\', 'app', fn (Root $root) => $root
            ->generates('command', in: 'Areas/{area}/Console/Commands', ungrouped: 'Console/Commands')));
    AcceptanceApp::run($layout, function (AcceptanceApp $app) {
        $tag = $app->tag;
        $app->artisan('mod:command', ['name' => 'Global'.$tag])->assertSuccessful();
        $app->artisan('mod:command', ['name' => 'Billing:Placed'.$tag])->assertSuccessful();
        $global = "app/Console/Commands/Global{$tag}.php";
        $placed = "app/Areas/Billing/Console/Commands/Placed{$tag}.php";
        foreach ([$global, $placed] as $index => $path) {
            $app->write($path, str_replace('command:name', "fallback:{$tag}:{$index}", $app->read($path)));
        }
        $app->assertOwned($global, 'command', [], 'App\\Console\\Commands\\Global'.$tag);
        $app->assertOwned($placed, 'command', ['area' => 'Billing'], 'App\\Areas\\Billing\\Console\\Commands\\Placed'.$tag);
        $app->boot();
        expect($app->hasArtisanCommand('App\\Console\\Commands\\Global'.$tag))->toBeTrue()
            ->and($app->hasArtisanCommand('App\\Areas\\Billing\\Console\\Commands\\Placed'.$tag))->toBeTrue();
    });
});

it('discovers global and feature commands in the built-in layouts while other kinds stay strict', function (string $layout, string $prefix) {
    AcceptanceApp::run($layout, function (AcceptanceApp $app) use ($prefix) {
        $tag = $app->tag;
        $app->artisan('mod:command', ['name' => 'Global'.$tag])->assertSuccessful();
        $app->artisan('mod:command', ['name' => 'Billing:Placed'.$tag])->assertSuccessful();
        $paths = ["app/Console/Commands/Global{$tag}.php", "app/{$prefix}Billing/Console/Commands/Placed{$tag}.php"];
        foreach ($paths as $index => $path) {
            $app->write($path, str_replace('command:name', "builtin:{$tag}:{$index}", $app->read($path)));
        }
        $globalClass = 'App\\Console\\Commands\\Global'.$tag;
        $placedClass = 'App\\'.str_replace('/', '\\', $prefix).'Billing\\Console\\Commands\\Placed'.$tag;
        $app->assertOwned($paths[0], 'command', [], $globalClass);
        $app->assertOwned($paths[1], 'command', ['feature' => 'Billing'], $placedClass);
        foreach (['provider', 'middleware'] as $kind) {
            $app->artisan('mod:'.$kind, ['name' => 'Strict'.$tag])->expectsOutputToContain("mod:{$kind} needs a feature.")->assertFailed();
        }
        $app->boot();
        expect($app->hasArtisanCommand($globalClass))->toBeTrue()
            ->and($app->hasArtisanCommand($placedClass))->toBeTrue();
        $app->artisan('mod:cache')->assertSuccessful();
        $app->boot();
        expect($app->discovery()->source())->toBe('cache')
            ->and($app->hasArtisanCommand($globalClass))->toBeTrue()
            ->and($app->hasArtisanCommand($placedClass))->toBeTrue();
    });
})->with([['features', 'Features/'], ['slices', '']]);
