<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Foundation\Application;
use Pest\TestSuite;
use Tey\Mod\Discovery\Discovery;
use Tey\Mod\Discovery\DiscoveryType;
use Tey\Mod\Tests\Fixtures\Layouts;
use Tey\Mod\Tests\Support\OwnedAppRoot;
use Tey\Mod\Tests\TestCase;

/*
 * The whole loop on the cookbook modules layout, through the service
 * provider only: generate with mod:*, boot a fresh app that discovers and
 * registers what was generated, cache it, and replay the identical inventory.
 */

it('generates into a module, discovers it on a fresh boot and replays it from the cache', function () {
    $case = TestSuite::getInstance()->test;

    if (! $case instanceof TestCase) {
        throw new RuntimeException('This test needs the Testbench test case.');
    }

    OwnedAppRoot::using(function (OwnedAppRoot $root) use ($case) {
        // Unique class names: generated classes are loaded into this PHP process.
        $tag = 'E'.bin2hex(random_bytes(4));
        $provider = "App\\Modules\\Billing\\Providers\\{$tag}ServiceProvider";
        $event = "App\\Modules\\Billing\\Events\\{$tag}Paid";
        $listener = "App\\Modules\\Billing\\Listeners\\Send{$tag}Receipt";

        // The cookbook layout plus a listener kind, declared in data only.
        $definition = Layouts::definition('modules');
        $definition['kinds']['listener'] = [
            'shape' => 'class', 'name' => 'as-given', 'command' => 'mod:listener',
            'root' => 'app', 'segments' => ['Modules', '{module}', 'Listeners'],
        ];

        $boot = fn (): Application => $case->bootApplicationUsing(function (Application $app) use ($root, $definition): void {
            $app->setBasePath($root->path);
            $app->make('config')->set('mod.preset', $definition);
            $app->make('config')->set('mod.discovery.enabled', true);
        });

        $artisan = function (Application $app, string $command, array $parameters = []): int {
            return $app->make(Kernel::class)->call($command, [...$parameters, '--no-interaction' => true]);
        };

        $autoload = static function (string $class) use ($root): void {
            if (str_starts_with($class, 'App\\') && is_file($file = $root->path('app/'.str_replace('\\', '/', substr($class, 4)).'.php'))) {
                require $file;
            }
        };

        spl_autoload_register($autoload);

        try {
            // 1. Generate into the Billing module.
            $app = $boot();

            expect($app->make(Discovery::class)->inventory()->isEmpty())->toBeTrue()
                ->and($artisan($app, 'mod:provider', ['name' => $tag, '--in' => 'Billing']))->toBe(0)
                ->and($artisan($app, 'mod:event', ['name' => "{$tag}Paid", '--in' => 'Billing']))->toBe(0)
                ->and($artisan($app, 'mod:listener', ['name' => "Send{$tag}Receipt", '--in' => 'Billing', '--event' => "{$tag}Paid"]))->toBe(0);

            // 2. A fresh boot discovers and registers them.
            $app = $boot();
            $discovery = $app->make(Discovery::class);
            $cold = $discovery->inventory();

            expect($discovery->source())->toBe('scan')
                ->and($cold->classes(DiscoveryType::Provider))->toBe([$provider])
                ->and($cold->classes(DiscoveryType::Listener))->toBe([$listener])
                ->and($cold->ofType(DiscoveryType::Listener)[0]->context)->toBe(['module' => 'Billing'])
                ->and($cold->ofType(DiscoveryType::Listener)[0]->path)->toBe("app/Modules/Billing/Listeners/Send{$tag}Receipt.php")
                ->and($app->getProvider($provider))->toBeInstanceOf($provider)
                ->and($app->make('events')->hasListeners($event))->toBeTrue();

            // 3. Cache it.
            expect($artisan($app, 'mod:discovery-cache'))->toBe(0)
                ->and(is_file($root->path('bootstrap/cache/mod-discovery.php')))->toBeTrue();

            // 4. Another fresh boot replays the identical inventory from the cache.
            $app = $boot();
            $replayed = $app->make(Discovery::class);

            expect($replayed->source())->toBe('cache')
                ->and($replayed->inventory()->toArray())->toBe($cold->toArray())
                ->and($app->getProvider($provider))->toBeInstanceOf($provider)
                ->and($app->make('events')->hasListeners($event))->toBeTrue();

            // 5. optimize:clear removes it again through the hook.
            expect($artisan($app, 'optimize:clear'))->toBe(0)
                ->and(is_file($root->path('bootstrap/cache/mod-discovery.php')))->toBeFalse();
        } finally {
            spl_autoload_unregister($autoload);
        }
    });
});
