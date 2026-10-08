<?php

use Tey\Mod\Facades\Mod;
use Tey\Mod\Layout\Root;
use Tey\Mod\Tests\Feature\Acceptance\Support\AcceptanceApp;
use Tey\Mod\Tests\Feature\Acceptance\Support\LayoutUnderTest;

it('generates, reverse maps and discovers both fallback and placed commands', function () {
    $layout = new LayoutUnderTest('fallback', fn () => Mod::layout('fallback')
        ->root('app', 'App\\', 'app', fn (Root $root) => $root
            ->kind('command', in: 'Areas/{area}/Console/Commands', fallback: 'Console/Commands')));
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
