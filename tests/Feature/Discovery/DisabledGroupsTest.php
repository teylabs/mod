<?php

use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\View;
use Illuminate\View\FileViewFinder;
use Pest\TestSuite;
use Tey\Mod\Discovery\DisabledGroups;
use Tey\Mod\Discovery\DiscoveredArtifact;
use Tey\Mod\Discovery\DiscoveryType;
use Tey\Mod\Discovery\Inventory;
use Tey\Mod\Facades\Mod;
use Tey\Mod\Layout\CompiledLayout;
use Tey\Mod\Pages\PageDirectories;
use Tey\Mod\Scaffolds\ScaffoldRegistry;
use Tey\Mod\Tests\Feature\Acceptance\Support\AcceptanceApp;
use Tey\Mod\Tests\Feature\Acceptance\Support\LayoutUnderTest;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;
use Tey\Mod\Tests\Support\OwnedAppRoot;
use Tey\Mod\Tests\TestCase;

/*
 * mod.disabled: a disabled group leaves no runtime trace, through every path
 * its parts load by, while its migrations still run.
 */

function disabledGroupsCase(): TestCase
{
    $case = TestSuite::getInstance()->test;
    if (! $case instanceof TestCase) {
        throw new RuntimeException('Expected the package Testbench case.');
    }

    return $case;
}

it('matches a group, the groups nested below it, and a comma-separated string', function () {
    $disabled = new DisabledGroups(['Billing', ' Shop/Cart ', '', 3]);

    expect($disabled->names)->toBe(['Billing', 'Shop/Cart'])
        ->and($disabled->contains('Billing'))->toBeTrue()
        ->and($disabled->contains('Billing/Invoices'))->toBeTrue()
        ->and($disabled->contains('BillingExtra'))->toBeFalse()
        ->and($disabled->contains('Shop'))->toBeFalse()
        ->and($disabled->contains(null))->toBeFalse()
        ->and($disabled->unknown(['Billing/Invoices', 'Shop']))->toBe(['Shop/Cart']);

    config()->set('mod.disabled', 'Billing, Shipping');
    expect(DisabledGroups::of(app())->names)->toBe(['Billing', 'Shipping']);
    config()->set('mod.disabled', null);
    expect(DisabledGroups::of(app())->isEmpty())->toBeTrue();
});

it('filters every class entry of a disabled group and keeps its directories', function () {
    config()->set('mod.layout', 'modules');
    $layout = app(CompiledLayout::class);
    $inventory = new Inventory([
        new DiscoveredArtifact('provider', DiscoveryType::Provider, 'A\\BillingProvider', 'a.php', ['module' => 'Billing']),
        new DiscoveredArtifact('migration', DiscoveryType::Directory, '', 'app/Modules/Billing/Database/Migrations', ['module' => 'Billing']),
        new DiscoveredArtifact('provider', DiscoveryType::Provider, 'A\\ShippingProvider', 'b.php', ['module' => 'Shipping']),
        new DiscoveredArtifact('provider', DiscoveryType::Provider, 'A\\AppProvider', 'c.php'),
    ]);

    $active = (new DisabledGroups(['Billing']))->filter($inventory, $layout);

    expect($active->classes(DiscoveryType::Provider))->toBe(['A\\ShippingProvider', 'A\\AppProvider'])
        ->and($active->directories('migration'))->toBe(['app/Modules/Billing/Database/Migrations'])
        ->and((new DisabledGroups)->filter($inventory, $layout))->toBe($inventory);
});

/** @param list<string> $disabled */
function disabledModulesLayout(array $disabled): LayoutUnderTest
{
    return new LayoutUnderTest('modules', function () use ($disabled) {
        config()->set('mod.disabled', $disabled);
        Mod::layout('modules')
            ->generates('listener', in: 'Modules/{module}/Listeners')
            ->generates('command', in: 'Modules/{module}/Console');
    });
}

it('skips the providers, commands, listeners and policies of a disabled group but keeps its migrations, from a scan and from the cache', function () {
    AcceptanceApp::run(disabledModulesLayout(['Billing']), function (AcceptanceApp $app) {
        $t = $app->tag;
        $app->boot();
        foreach (['Billing', 'Shipping'] as $group) {
            $in = ['--in' => $group];
            $app->artisan('mod:model', ['name' => "{$group}{$t}Item", ...$in])->assertSuccessful();
            $app->artisan('mod:policy', ['name' => "{$group}{$t}ItemPolicy", '--model' => "{$group}{$t}Item", ...$in])->assertSuccessful();
            $app->artisan('mod:provider', ['name' => "{$group}{$t}", ...$in])->assertSuccessful();
            $app->artisan('mod:event', ['name' => "{$group}{$t}Happened", ...$in])->assertSuccessful();
            $app->artisan('mod:listener', ['name' => "On{$group}{$t}", '--event' => "{$group}{$t}Happened", ...$in])->assertSuccessful();
            $app->artisan('mod:command', ['name' => "Prune{$group}{$t}", ...$in])->assertSuccessful();
            $app->artisan('mod:migration', ['name' => 'create_'.strtolower($group).'_table', ...$in])->assertSuccessful();
        }

        $assert = function (bool $billing) use ($app, $t) {
            foreach (['Billing' => $billing, 'Shipping' => true] as $group => $on) {
                $ns = "App\\Modules\\{$group}";
                $listeners = $app->app()->make('events')->getRawListeners()["{$ns}\\Events\\{$group}{$t}Happened"] ?? [];
                expect($app->app()->getProvider("{$ns}\\Providers\\{$group}{$t}ServiceProvider") !== null)->toBe($on)
                    ->and($app->hasArtisanCommand("{$ns}\\Console\\Prune{$group}{$t}"))->toBe($on)
                    ->and($listeners !== [])->toBe($on)
                    ->and(($app->app()->make(Gate::class)->policies()["{$ns}\\Models\\{$group}{$t}Item"] ?? null) !== null)->toBe($on);
            }
            $paths = array_map(fn (string $path): string => str_replace('\\', '/', $path), $app->app()->make(Migrator::class)->paths());
            expect(array_filter($paths, fn (string $path): bool => str_ends_with($path, 'app/Modules/Billing/Database/Migrations')))->not->toBe([])
                ->and(array_filter($paths, fn (string $path): bool => str_ends_with($path, 'app/Modules/Shipping/Database/Migrations')))->not->toBe([]);
        };

        $app->boot();
        expect($app->discovery()->source())->toBe('scan');
        $assert(false);

        // The cache holds every group, so changing the list needs no rebuild.
        expect($app->artisan('mod:cache')->assertSuccessful()->output)->not->toContain('mod.disabled');
        $app->boot();
        expect($app->discovery()->source())->toBe('cache')
            ->and($app->discovery()->inventory()->classes(DiscoveryType::Provider))->toContain("App\\Modules\\Billing\\Providers\\Billing{$t}ServiceProvider");
        $assert(false);
    });
});

it('warns about unknown names in mod:cache and registers a group again once it leaves the list, with the cache built while it was disabled', function () {
    $disabled = ['Billing', 'Billng'];
    $layout = new LayoutUnderTest('modules', function () use (&$disabled) {
        config()->set('mod.disabled', $disabled);
    });
    AcceptanceApp::run($layout, function (AcceptanceApp $app) use (&$disabled) {
        $t = $app->tag;
        $app->boot();
        $app->artisan('mod:provider', ['name' => "Billing{$t}", '--in' => 'Billing'])->assertSuccessful();
        $provider = "App\\Modules\\Billing\\Providers\\Billing{$t}ServiceProvider";
        $app->boot();
        expect($app->artisan('mod:cache')->assertSuccessful()->normalisedOutput())->toContain('mod.disabled names no existing group: Billng.');
        $app->boot();
        expect($app->app()->getProvider($provider))->toBeNull();

        $disabled = [];
        $app->boot();
        expect($app->discovery()->source())->toBe('cache')
            ->and($app->app()->getProvider($provider))->not->toBeNull();
    });
});

it('loads no routes of a disabled group, console routes and so its scheduled tasks included', function (bool $console) {
    Workspace::run(null, function (Workspace $w) use ($console) {
        config()->set('mod.layout', 'modules');
        config()->set('mod.disabled', ['Billing']);
        config()->set('route_trace', []);
        foreach (['Billing', 'Shipping'] as $group) {
            $w->write("app/Modules/{$group}/routes/web.php", "<?php config()->push('route_trace', '{$group} web');");
            $w->write("app/Modules/{$group}/routes/console.php", "<?php config()->push('route_trace', '{$group} console');");
        }
        (new ReflectionProperty(app(), 'isRunningInConsole'))->setValue(app(), $console);
        Mod::routes();
        expect(config('route_trace'))->toBe($console ? ['Shipping web', 'Shipping console'] : ['Shipping web']);
    });
})->with([true, false]);

it('registers no views, components or translations of a disabled group', function () {
    OwnedAppRoot::using(function (OwnedAppRoot $root) {
        foreach (['Billing', 'Shipping'] as $group) {
            mkdir($root->path("app/Modules/{$group}/resources/views/components"), 0700, true);
            file_put_contents($root->path("app/Modules/{$group}/resources/views/show.blade.php"), $group);
            mkdir($root->path("app/Modules/{$group}/lang/en"), 0700, true);
            file_put_contents($root->path("app/Modules/{$group}/lang/en/messages.php"), "<?php return ['saved' => '{$group} saved'];");
        }
        disabledGroupsCase()->bootApplicationUsing(function (Application $app) use ($root) {
            $app->setBasePath($root->path);
            $app->make('config')->set('mod.layout', 'modules');
            $app->make('config')->set('mod.disabled', ['Billing']);
        });
        $finder = View::getFinder();
        expect($finder)->toBeInstanceOf(FileViewFinder::class);
        /** @var FileViewFinder $finder */
        expect(array_keys($finder->getHints()))->toContain('shipping');
        expect(array_keys($finder->getHints()))->not->toContain('billing')
            ->and(__('shipping::messages.saved'))->toBe('Shipping saved')
            ->and(__('billing::messages.saved'))->toBe('billing::messages.saved');
    });
});

it('registers no page namespace of a disabled group', function () {
    OwnedAppRoot::using(function (OwnedAppRoot $root) {
        foreach (['Billing', 'Shipping'] as $group) {
            mkdir($root->path("app/Modules/{$group}/resources/js/pages"), 0700, true);
        }
        file_put_contents($root->path('package.json'), '{"dependencies":{"@inertiajs/vue3":"^2"}}');
        disabledGroupsCase()->bootApplicationUsing(function (Application $app) use ($root) {
            $app->setBasePath($root->path);
            $app->make('config')->set('mod.layout', 'modules');
            $app->make('config')->set('mod.disabled', ['Billing']);
        });
        $directories = app(PageDirectories::class);
        expect(array_keys($directories->namespaces()))->toBe(['Billing', 'Shipping'])
            ->and($directories->group('Billing'))->toBe('Billing');

        // The registrar decorates each Inertia finder as it resolves.
        app()->bind('inertia.view-finder', fn () => new FileViewFinder(new Filesystem, []));
        $finder = app('inertia.view-finder');
        expect(array_keys($finder->getHints()))->toContain('Shipping');
        expect(array_keys($finder->getHints()))->not->toContain('Billing');
    });
});

it('registers no scaffold of a disabled group', function () {
    Workspace::run(null, function (Workspace $w) {
        config()->set('mod.layout', 'modules');
        foreach (['Billing', 'Shipping'] as $group) {
            $w->write("app/Modules/{$group}/Scaffolds/{$group}Report.php", <<<PHP
<?php
namespace App\\Modules\\{$group}\\Scaffolds;
use Tey\\Mod\\Scaffolds\\Scaffold;
final class {$group}Report
{
    public string \$name = '{$group}-report';
    public function __invoke(Scaffold \$s): void { \$s->makes('model', name: '{name}'); }
}
PHP);
        }
        config()->set('mod.disabled', ['Billing']);
        $w->artisan('mod:list')->assertSuccessful();
        expect(app(ScaffoldRegistry::class)->sources())->toHaveKey('Shipping-report');
        expect(app(ScaffoldRegistry::class)->sources())->not->toHaveKey('Billing-report');
    });
});

it('shows disabled and unknown names in mod:list', function () {
    Workspace::run(null, function (Workspace $w) {
        config()->set('mod.layout', 'modules');
        config()->set('mod.disabled', ['Billing', 'Billng']);
        foreach (['Billing', 'Shipping'] as $group) {
            $w->write("app/Modules/{$group}/Models/{$group}Item.php", "<?php\nnamespace App\\Modules\\{$group}\\Models;\nclass {$group}Item {}\n");
        }
        $json = json_decode($w->artisan('mod:list', ['--json' => true])->assertSuccessful()->output, true, flags: JSON_THROW_ON_ERROR);
        expect($json['groups'])->toBe(['Billing', 'Shipping'])
            ->and($json['disabled'])->toBe(['Billing'])
            ->and($json['unknown_disabled'])->toBe(['Billng']);

        expect($w->artisan('mod:list')->assertSuccessful()->normalisedOutput())
            ->toContain('Billing (disabled), Shipping')
            ->toContain('mod.disabled names no existing group: Billng.');
    });
});
