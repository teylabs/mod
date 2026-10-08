<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Log;
use Tey\Mod\Discovery\CacheMismatchPolicy;
use Tey\Mod\Discovery\Console\DiscoveryCacheCommand;
use Tey\Mod\Discovery\Console\DiscoveryClearCommand;
use Tey\Mod\Discovery\Discovery;
use Tey\Mod\Discovery\DiscoveryCache;
use Tey\Mod\Discovery\DiscoveryOptions;
use Tey\Mod\Discovery\DiscoveryRegistrar;
use Tey\Mod\Discovery\DiscoveryType;
use Tey\Mod\Exceptions\InvalidDiscoveryCache;
use Tey\Mod\Layout\CompiledLayout;
use Tey\Mod\Tests\Feature\Discovery\Support\DiscoveryFixture;
use Tey\Mod\Tests\Feature\Discovery\Support\Sources;
use Tey\Mod\Tests\Fixtures\Layouts;

function cacheTree(DiscoveryFixture $fx): CompiledLayout
{
    $fx->write('app/Providers/BillingServiceProvider.php', Sources::provider('App\\Providers', 'BillingServiceProvider', 'fixture.billing'))
        ->write('app/Console/Commands/SendInvoices.php', Sources::command('App\\Console\\Commands', 'SendInvoices', 'fixture:send-invoices'))
        ->write('app/Events/InvoicePaid.php', Sources::event('App\\Events', 'InvoicePaid'))
        ->write('app/Listeners/SendReceipt.php', Sources::listener('App\\Listeners', 'SendReceipt', 'handle', '\\{{ns}}\\App\\Events\\InvoicePaid'))
        ->write('app/Listeners/Untyped.php', Sources::plain('App\\Listeners', 'Untyped'))
        ->write('app/helpers.php', "<?php\n");

    return $fx->layout('ordinary');
}

/**
 * @param  array<string, mixed>  $config
 */
function discoveryFor(DiscoveryFixture $fx, CompiledLayout $preset, array $config = []): Discovery
{
    return new Discovery($preset, DiscoveryOptions::fromConfig($config), $fx->path());
}

it('replays exactly the inventory a cold scan produces', DiscoveryFixture::around(function (DiscoveryFixture $fx) {
    $preset = cacheTree($fx);
    $cold = discoveryFor($fx, $preset);

    $written = $cold->writeCache();
    $replayed = discoveryFor($fx, $preset);

    expect($cold->cache()->path)->toEqualPath($fx->path('bootstrap/cache/mod-discovery.php'))
        ->and($replayed->inventory()->toArray())->toBe($written->toArray())
        ->and($replayed->inventory()->equals($cold->scan()))->toBeTrue()
        ->and($replayed->source())->toBe('cache')
        ->and($written->entries)->toHaveCount(3)
        ->and($written->rejections)->toHaveCount(2);
}));

it('registers from the cache without scanning', DiscoveryFixture::around(function (DiscoveryFixture $fx) {
    $preset = cacheTree($fx);
    discoveryFor($fx, $preset)->writeCache();

    // Added after caching: a replay must not see it, a cold scan must.
    $fx->write('app/Providers/LateServiceProvider.php', Sources::provider('App\\Providers', 'LateServiceProvider', 'fixture.late'));
    $this->app->setBasePath($fx->path());

    $discovery = DiscoveryRegistrar::register($this->app, $preset);

    expect($discovery->source())->toBe('cache')
        ->and($this->app->bound('fixture.billing'))->toBeTrue()
        ->and($this->app->bound('fixture.late'))->toBeFalse()
        ->and($this->app->make(Kernel::class)->all())->toHaveKey('fixture:send-invoices')
        ->and($discovery->scan()->classes(DiscoveryType::Provider))->toContain($fx->class('App\\Providers\\LateServiceProvider'));
}));

it('refuses a cache built for another preset, naming the fix', DiscoveryFixture::around(function (DiscoveryFixture $fx) {
    $preset = cacheTree($fx);
    discoveryFor($fx, $preset, ['on_stale_cache' => 'fail'])->writeCache();

    $definition = Layouts::definition('ordinary');
    $definition['kinds']['provider']['segments'] = ['Providers', 'Registered'];
    $other = $fx->preset($definition);

    expect(fn () => discoveryFor($fx, $other, ['on_stale_cache' => 'fail'])->inventory())
        ->toThrow(InvalidDiscoveryCache::class, 'it was built for a different layout. Rebuild it with `php artisan mod:discovery-cache`');
}));

it('refuses a cache built with other discovery settings', DiscoveryFixture::around(function (DiscoveryFixture $fx) {
    $preset = cacheTree($fx);
    discoveryFor($fx, $preset, ['on_stale_cache' => 'fail'])->writeCache();

    expect(fn () => discoveryFor($fx, $preset, ['kinds' => ['listener' => false], 'on_stale_cache' => 'fail'])->inventory())
        ->toThrow(InvalidDiscoveryCache::class, 'different discovery settings');
}));

it('refuses unknown schema versions and malformed files', DiscoveryFixture::around(function (DiscoveryFixture $fx) {
    $preset = cacheTree($fx);
    $discovery = discoveryFor($fx, $preset, ['on_stale_cache' => 'fail']);
    $file = $discovery->cache()->path;
    $header = "'preset' => '{$discovery->presetFingerprint()}', 'definitions' => '{$discovery->definitionsFingerprint()}'";

    foreach ([
        "<?php return ['schema' => 999, {$header}, 'inventory' => ['entries' => [], 'rejections' => []]];" => 'schema version [999] is not the supported version [1]',
        "<?php return 'inventory';" => 'does not return an array',
        '<?php return [' => 'is not valid PHP',
        "<?php return ['schema' => 1, {$header}, 'inventory' => ['entries' => 'none', 'rejections' => []]];" => 'malformed inventory',
        "<?php return ['schema' => 1, {$header}, 'inventory' => ['entries' => [['kind' => 'provider', 'type' => 'widget', 'class' => 'X', 'path' => 'x.php', 'context' => [], 'events' => []]], 'rejections' => []]];" => 'unknown discovery type [widget]',
    ] as $contents => $problem) {
        file_put_contents($file, $contents);

        expect(fn () => discoveryFor($fx, $preset, ['on_stale_cache' => 'fail'])->inventory())->toThrow(InvalidDiscoveryCache::class, $problem);
    }
}));

it('scans cold without rewriting the file under the scan policy', DiscoveryFixture::around(function (DiscoveryFixture $fx) {
    $preset = cacheTree($fx);
    $file = $fx->path('bootstrap/cache/mod-discovery.php');
    file_put_contents($file, "<?php return ['schema' => 0];");

    $discovery = discoveryFor($fx, $preset, ['on_stale_cache' => CacheMismatchPolicy::Scan->value]);

    expect($discovery->inventory()->equals($discovery->scan()))->toBeTrue()
        ->and($discovery->source())->toBe('scan')
        ->and($discovery->staleCacheReason())->toContain('schema')
        ->and(file_get_contents($file))->toBe("<?php return ['schema' => 0];");
}));

it('scans and warns instead of failing under the default policy', DiscoveryFixture::around(function (DiscoveryFixture $fx) {
    $preset = cacheTree($fx);
    file_put_contents($fx->path('bootstrap/cache/mod-discovery.php'), "<?php return ['schema' => 0];");
    $this->app->setBasePath($fx->path());
    $log = Log::spy();

    $discovery = DiscoveryRegistrar::register($this->app, $preset);

    expect($discovery->source())->toBe('scan')
        ->and($discovery->staleCacheReason())->not->toBeNull();
    $log->shouldHaveReceived('warning')->once()->withArgs(fn (string $message) => str_contains($message, 'mod:discovery-cache'));
}));

it('fails registration loudly under the fail policy', DiscoveryFixture::around(function (DiscoveryFixture $fx) {
    $preset = cacheTree($fx);
    file_put_contents($fx->path('bootstrap/cache/mod-discovery.php'), "<?php return ['schema' => 0];");
    $this->app->setBasePath($fx->path());

    expect(fn () => DiscoveryRegistrar::register($this->app, $preset, DiscoveryOptions::fromConfig(['on_stale_cache' => 'fail'])))->toThrow(InvalidDiscoveryCache::class);
}));

it('honours a configured cache path and clears it', DiscoveryFixture::around(function (DiscoveryFixture $fx) {
    $preset = cacheTree($fx);
    $discovery = discoveryFor($fx, $preset, ['cache' => 'storage/framework/discovery.php']);

    $discovery->writeCache();

    expect(is_file($fx->path('storage/framework/discovery.php')))->toBeTrue()
        ->and($discovery->clearCache())->toBeTrue()
        ->and($discovery->clearCache())->toBeFalse()
        ->and((new DiscoveryCache($fx->path('storage/framework/discovery.php')))->exists())->toBeFalse();
}));

it('builds and clears the cache through the console commands', DiscoveryFixture::around(function (DiscoveryFixture $fx) {
    $preset = cacheTree($fx);
    $this->app->setBasePath($fx->path());
    DiscoveryRegistrar::register($this->app, $preset);

    $kernel = $this->app->make(Kernel::class);
    $kernel->registerCommand($this->app->make(DiscoveryCacheCommand::class));
    $kernel->registerCommand($this->app->make(DiscoveryClearCommand::class));

    $this->artisan('mod:discovery-cache')
        ->expectsOutputToContain('1 providers, 1 commands, 1 listeners, 0 subscribers, 0 directories, 2 rejected')
        // What "rejected" means, and which ones are worth a look.
        ->expectsOutputToContain('Rejected files were found but not registered: 1 placed by no file type (helpers and plain classes; nothing to do), 1 in a discovered folder but not a provider, command, listener or subscriber (check it). Run with -v to list them.')
        ->doesntExpectOutputToContain('app/helpers.php')
        ->assertSuccessful();

    $this->artisan('mod:discovery-cache', ['-v' => true])
        ->expectsOutputToContain('app/helpers.php: placed by no file type')
        ->expectsOutputToContain('app/Listeners/Untyped.php: in a discovered folder but not a provider, command, listener or subscriber')
        ->doesntExpectOutputToContain('Run with -v')
        ->assertSuccessful();

    expect(is_file($fx->path('bootstrap/cache/mod-discovery.php')))->toBeTrue();

    $this->artisan('mod:discovery-clear')->expectsOutputToContain('Discovery cache cleared.')->assertSuccessful();

    expect(is_file($fx->path('bootstrap/cache/mod-discovery.php')))->toBeFalse();
}));
