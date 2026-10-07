<?php

use Tey\Mod\Discovery\DiscoveryType;
use Tey\Mod\Facades\Mod;
use Tey\Mod\Layout\InvalidLayout;
use Tey\Mod\Layout\Root;
use Tey\Mod\Tests\Feature\Acceptance\Support\AcceptanceApp;
use Tey\Mod\Tests\Feature\Acceptance\Support\LayoutUnderTest;

/*
 * The public layout API end to end. A whole DDD-like layout is defined
 * as ONE chain with nested closures in the application's
 * AppServiceProvider::boot(), selected with `'layout' => 'ddd'`, and both
 * mod:* generation and discovery honour it: the active layout compiles only
 * after every provider has booted.
 */

function dddLayoutUnderTest(): LayoutUnderTest
{
    return new LayoutUnderTest('ddd', function () {
        Mod::layout('ddd')
            ->root('domain', 'Domain\\', 'src/Domain', fn (Root $r) => $r
                ->kind('model', in: '{domain}/Models')
                ->kind('action', in: '{domain}/Actions'))
            ->root('app', 'App\\', 'app', fn (Root $r) => $r
                ->kind('controller', in: 'Modules/{domain}/Controllers', suffix: 'Controller'))
            ->kind('factory', in: 'domain:{domain}/Database/Factories', suffix: 'Factory')
            ->relation('factory', from: 'model', to: 'factory')
            ->exclude('App\\Support\\');

        // A later call (another provider, say) extends the same layout.
        Mod::layout('ddd')->kind('provider', in: 'domain:{domain}/Providers', suffix: 'ServiceProvider');
    });
}

it('generates and discovers with a DDD-like layout defined as one chain in AppServiceProvider::boot()', function () {
    AcceptanceApp::run(dddLayoutUnderTest(), function (AcceptanceApp $app) {
        $t = $app->tag;
        $in = ['--in' => 'Billing'];
        $ctx = ['domain' => 'Billing'];
        $app->boot();

        expect($app->modCommands())->toContain('mod:model', 'mod:action', 'mod:controller', 'mod:factory', 'mod:provider')
            ->and($app->modCommands())->not->toContain('mod:request');

        $app->artisan('mod:model', ['name' => "Invoice{$t}", '--factory' => true, ...$in])->assertSuccessful();
        $app->artisan('mod:action', ['name' => "Pay{$t}Invoice", ...$in])->assertSuccessful();
        $app->artisan('mod:controller', ['name' => "Invoice{$t}Controller", ...$in])->assertSuccessful();
        $app->artisan('mod:provider', ['name' => "Billing{$t}", ...$in])->assertSuccessful();

        $generated = [
            "app/Modules/Billing/Controllers/Invoice{$t}Controller.php" => ['controller', "App\\Modules\\Billing\\Controllers\\Invoice{$t}Controller"],
            "src/Domain/Billing/Actions/Pay{$t}Invoice.php" => ['action', "Domain\\Billing\\Actions\\Pay{$t}Invoice"],
            "src/Domain/Billing/Database/Factories/Invoice{$t}Factory.php" => ['factory', "Domain\\Billing\\Database\\Factories\\Invoice{$t}Factory"],
            "src/Domain/Billing/Models/Invoice{$t}.php" => ['model', "Domain\\Billing\\Models\\Invoice{$t}"],
            "src/Domain/Billing/Providers/Billing{$t}ServiceProvider.php" => ['provider', "Domain\\Billing\\Providers\\Billing{$t}ServiceProvider"],
        ];

        expect($app->files())->toBe(array_keys($generated))
            ->and($app->read("src/Domain/Billing/Models/Invoice{$t}.php"))
            ->toContain('namespace Domain\\Billing\\Models;')
            ->toContain("return \\Domain\\Billing\\Database\\Factories\\Invoice{$t}Factory::new();");

        foreach ($generated as $path => [$kind, $fqcn]) {
            $app->assertOwned($path, $kind, $ctx, $fqcn);
        }

        expect($app->mapClass("App\\Support\\Modules\\Billing\\Controllers\\Helper{$t}Controller")->reason)->toContain('excluded');

        // A fresh boot: discovery registers from the booted callback, after AppServiceProvider defined the layout.
        $app->boot();
        $inventory = $app->discovery()->inventory();

        expect($inventory->classes(DiscoveryType::Provider))->toBe(["Domain\\Billing\\Providers\\Billing{$t}ServiceProvider"])
            ->and($app->app()->getProvider("Domain\\Billing\\Providers\\Billing{$t}ServiceProvider"))->not->toBeNull();
    });
});

it('extends the configured built-in layout from AppServiceProvider::boot()', function () {
    $layout = new LayoutUnderTest('laravel', fn () => Mod::layout('laravel')
        ->kind('provider', in: 'Support/Providers')
        ->kind('job', in: 'Jobs'));

    AcceptanceApp::run($layout, function (AcceptanceApp $app) {
        $t = $app->tag;
        $app->boot();

        $app->artisan('mod:provider', ['name' => "Billing{$t}"])->assertSuccessful();
        $app->artisan('mod:job', ['name' => "Send{$t}Invoice"])->assertSuccessful();

        expect($app->files())->toBe([
            "app/Jobs/Send{$t}Invoice.php",
            "app/Support/Providers/Billing{$t}ServiceProvider.php",
        ]);

        $app->boot();

        expect($app->discovery()->inventory()->classes(DiscoveryType::Provider))->toBe(["App\\Support\\Providers\\Billing{$t}ServiceProvider"]);
    });
});

it('reports an invalid layout with the call that caused it when mod:* starts', function () {
    $layout = new LayoutUnderTest('laravel', fn () => Mod::layout('laravel')->kind('job', in: 'jobs:Jobs'));

    expect(fn () => AcceptanceApp::run($layout, fn () => null))
        ->toThrow(InvalidLayout::class, "Layout [laravel] is invalid:\n - ->kind('job'): root [jobs] is not declared (declared: app, factories, seeders, migrations) [unknown-root]");
});
