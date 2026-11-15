<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Log\Events\MessageLogged;
use Tey\Mod\Discovery\Console\DiscoveryClearCommand;
use Tey\Mod\Discovery\Discovery;
use Tey\Mod\Discovery\DiscoveryOptions;
use Tey\Mod\Discovery\DiscoveryRegistrar;
use Tey\Mod\Discovery\DiscoveryType;
use Tey\Mod\Discovery\InProcessInventory;
use Tey\Mod\Exceptions\InvalidDiscoveryCache;
use Tey\Mod\Layout\CompiledLayout;
use Tey\Mod\Tests\Feature\Discovery\Support\DiscoveryFixture;
use Tey\Mod\Tests\Feature\Discovery\Support\Sources;
use Tey\Mod\Tests\Fixtures\Layouts;
use Tey\Mod\Tests\TestCase;

/*
 * Each "request" is a fresh Discovery, as each PHP request builds one. Fixture
 * files are written in the second the test runs, and a change in the same
 * second as a scan is never cached, so autoSettle() moves the tree into the past.
 */

function autoTree(DiscoveryFixture $fx): CompiledLayout
{
    $fx->write('app/Modules/Billing/Providers/BillingServiceProvider.php', Sources::provider('App\\Modules\\Billing\\Providers', 'BillingServiceProvider', 'fixture.billing'))
        ->write('app/Modules/Billing/Events/InvoicePaid.php', Sources::event('App\\Modules\\Billing\\Events', 'InvoicePaid'))
        ->write('app/Modules/Billing/Listeners/SendReceipt.php', Sources::listener('App\\Modules\\Billing\\Listeners', 'SendReceipt', 'handle', '\\{{ns}}\\App\\Modules\\Billing\\Events\\InvoicePaid'))
        ->write('app/Modules/Shipping/Providers/ShippingServiceProvider.php', Sources::provider('App\\Modules\\Shipping\\Providers', 'ShippingServiceProvider', 'fixture.shipping'));

    $definition = Layouts::definition('modules');
    $definition['kinds']['listener'] = ['shape' => 'class', 'name' => 'as-given', 'command' => 'mod:listener', 'root' => 'app', 'segments' => ['Modules', '{module}', 'Listeners']];

    return $fx->preset($definition);
}

/** Every path in the fixture in the past, a second later than the previous call, as real edits are. */
function autoSettle(DiscoveryFixture $fx): void
{
    static $calls = 0;
    $past = time() - 100 + $calls++;
    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fx->path(), FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);

    foreach ($items as $item) {
        /** @var SplFileInfo $item */
        touch($item->getPathname(), $past);
    }

    touch($fx->path(), $past);
}

/** @param array<string, mixed> $config */
function autoRequest(DiscoveryFixture $fx, CompiledLayout $layout, array $config = []): Discovery
{
    $discovery = new Discovery($layout, DiscoveryOptions::fromConfig(['auto_cache' => true, ...$config]), $fx->path());
    $discovery->inventory();

    return $discovery;
}

function autoScans(): stdClass
{
    $scans = new stdClass;
    $scans->count = 0;
    InProcessInventory::instance()->onScan = static function () use ($scans): void {
        $scans->count++;
    };

    return $scans;
}

it('scans and writes the automatic cache on the first request, then reads it without scanning', DiscoveryFixture::around(function (DiscoveryFixture $fx) {
    $layout = autoTree($fx);
    autoSettle($fx);
    $scans = autoScans();

    $first = autoRequest($fx, $layout);

    expect($first->source())->toBe('scan')
        ->and($scans->count)->toBe(1)
        ->and(is_file($fx->path('bootstrap/cache/mod-discovery.auto.php')))->toBeTrue()
        ->and(is_file($fx->path('bootstrap/cache/mod-discovery.php')))->toBeFalse();

    for ($request = 0; $request < 3; $request++) {
        $warm = autoRequest($fx, $layout);

        expect($warm->source())->toBe('auto-cache')
            ->and($warm->inventory()->toArray())->toBe($first->inventory()->toArray());
    }

    expect($scans->count)->toBe(1)
        ->and($first->inventory()->classes(DiscoveryType::Provider))->toHaveCount(2)
        ->and($first->inventory()->ofType(DiscoveryType::Listener))->toHaveCount(1);
}));

it('rescans after a provider file is added', DiscoveryFixture::around(function (DiscoveryFixture $fx) {
    $layout = autoTree($fx);
    autoSettle($fx);
    autoRequest($fx, $layout);
    $scans = autoScans();

    $fx->write('app/Modules/Billing/Providers/LateServiceProvider.php', Sources::provider('App\\Modules\\Billing\\Providers', 'LateServiceProvider', 'fixture.late'));
    $next = autoRequest($fx, $layout);

    expect($scans->count)->toBe(1)
        ->and($next->source())->toBe('scan')
        ->and($next->inventory()->classes(DiscoveryType::Provider))->toContain($fx->class('App\\Modules\\Billing\\Providers\\LateServiceProvider'));
}));

it('rescans after a module is added or removed', DiscoveryFixture::around(function (DiscoveryFixture $fx) {
    $layout = autoTree($fx);
    autoSettle($fx);
    autoRequest($fx, $layout);
    $scans = autoScans();

    $fx->write('app/Modules/Orders/Providers/OrdersServiceProvider.php', Sources::provider('App\\Modules\\Orders\\Providers', 'OrdersServiceProvider', 'fixture.orders'));
    $added = autoRequest($fx, $layout);

    expect($scans->count)->toBe(1)
        ->and($added->inventory()->classes(DiscoveryType::Provider))->toContain($fx->class('App\\Modules\\Orders\\Providers\\OrdersServiceProvider'));

    autoSettle($fx);
    autoRequest($fx, $layout);
    expect(autoRequest($fx, $layout)->source())->toBe('auto-cache');

    unlink($fx->path('app/Modules/Shipping/Providers/ShippingServiceProvider.php'));
    rmdir($fx->path('app/Modules/Shipping/Providers'));
    rmdir($fx->path('app/Modules/Shipping'));
    $removed = autoRequest($fx, $layout);

    expect($scans->count)->toBe(3)
        ->and($removed->source())->toBe('scan')
        ->and($removed->inventory()->classes(DiscoveryType::Provider))->not->toContain($fx->class('App\\Modules\\Shipping\\Providers\\ShippingServiceProvider'));
}));

it('rescans after a scanned class is edited, though its directory is unchanged', DiscoveryFixture::around(function (DiscoveryFixture $fx) {
    $layout = autoTree($fx);
    autoSettle($fx);
    autoRequest($fx, $layout);
    $scans = autoScans();
    $directory = $fx->path('app/Modules/Billing/Listeners');
    $mtime = filemtime($directory);

    // The event a listener handles comes from its type hint: an edit, not a new file.
    $fx->write('app/Modules/Billing/Listeners/SendReceipt.php', Sources::listener('App\\Modules\\Billing\\Listeners', 'SendReceipt', 'handle', '\\{{ns}}\\App\\Modules\\Billing\\Events\\InvoiceRefunded'));
    touch($directory, $mtime);
    clearstatcache();

    expect(filemtime($directory))->toBe($mtime)
        ->and(autoRequest($fx, $layout)->source())->toBe('scan')
        ->and($scans->count)->toBe(1);
}));

it('rescans after a parent class outside the discovered folders is edited', DiscoveryFixture::around(function (DiscoveryFixture $fx) {
    $definition = Layouts::definition('modules');
    $definition['roots']['lib'] = ['namespace' => 'Lib\\', 'path' => 'lib'];
    $layout = $fx->preset($definition);
    $fx->write('lib/ModuleServiceProvider.php', <<<'PHP'
    <?php
    namespace {{ns}}\Lib;
    abstract class ModuleServiceProvider extends \Illuminate\Support\ServiceProvider {}
    PHP)
        ->write('app/Modules/Billing/Providers/BillingServiceProvider.php', <<<'PHP'
    <?php
    namespace {{ns}}\App\Modules\Billing\Providers;
    class BillingServiceProvider extends \{{ns}}\Lib\ModuleServiceProvider {}
    PHP);
    autoSettle($fx);
    autoRequest($fx, $layout);
    expect(autoRequest($fx, $layout)->source())->toBe('auto-cache');
    $scans = autoScans();

    $fx->write('lib/ModuleServiceProvider.php', <<<'PHP'
    <?php
    namespace {{ns}}\Lib;
    abstract class ModuleServiceProvider {}
    PHP);

    expect(autoRequest($fx, $layout)->source())->toBe('scan')
        ->and($scans->count)->toBe(1);
}));

it('rescans after composer.lock changes', DiscoveryFixture::around(function (DiscoveryFixture $fx) {
    $layout = autoTree($fx);
    file_put_contents($fx->path('composer.lock'), '{"content-hash": "a"}');
    autoSettle($fx);
    autoRequest($fx, $layout);
    expect(autoRequest($fx, $layout)->source())->toBe('auto-cache');
    $scans = autoScans();

    file_put_contents($fx->path('composer.lock'), '{"content-hash": "b2"}');

    expect(autoRequest($fx, $layout)->source())->toBe('scan')
        ->and($scans->count)->toBe(1);
}));

it('does not cache a scan that saw a change in the same second', DiscoveryFixture::around(function (DiscoveryFixture $fx) {
    $layout = autoTree($fx);
    $scans = autoScans();

    autoRequest($fx, $layout);
    autoRequest($fx, $layout);

    expect($scans->count)->toBe(2)
        ->and(is_file($fx->path('bootstrap/cache/mod-discovery.auto.php')))->toBeFalse();
}));

it('does not cache a scan that could not load a class', DiscoveryFixture::around(function (DiscoveryFixture $fx) {
    $layout = autoTree($fx);
    // Creating the missing parent, anywhere, would make this a provider: no path to watch covers it.
    $fx->write('app/Modules/Billing/Providers/BrokenServiceProvider.php', <<<'PHP'
    <?php
    namespace {{ns}}\App\Modules\Billing\Providers;
    class BrokenServiceProvider extends \{{ns}}\Lib\MissingServiceProvider {}
    PHP);
    autoSettle($fx);

    $discovery = autoRequest($fx, $layout);

    expect($discovery->inventory()->rejections)->toHaveCount(1)
        ->and(is_file($fx->path('bootstrap/cache/mod-discovery.auto.php')))->toBeFalse();
}));

it('scans without an error when the automatic cache cannot be written, and logs it once', function (string $setup) {
    /** @var TestCase $this */
    if ($setup === 'read-only' && (PHP_OS_FAMILY === 'Windows' || (function_exists('posix_geteuid') && posix_geteuid() === 0))) {
        $this->markTestSkipped('Directory permissions do not stop this user from writing.');
    }

    $fx = DiscoveryFixture::create();
    // Laravel writes its own manifests to bootstrap/cache, so the discovery cache goes elsewhere.
    $cache = $fx->path('locked');

    try {
        $layout = autoTree($fx);

        if ($setup === 'read-only') {
            mkdir($cache, 0555);
        } else {
            file_put_contents($cache, '');
        }
        autoSettle($fx);
        $scans = autoScans();
        $logged = [];

        for ($request = 0; $request < 2; $request++) {
            $app = $this->bootApplicationUsing(function (Application $app) use ($fx, $layout, &$logged): void {
                $app->setBasePath($fx->path());
                $app->make('config')->set('mod.discovery', ['enabled' => true, 'auto_cache' => true, 'cache' => 'locked/mod-discovery.php']);
                $app->booting(function () use ($app, $layout, &$logged): void {
                    $app->instance(CompiledLayout::class, $layout);
                    $app->make('events')->listen(MessageLogged::class, function (MessageLogged $event) use (&$logged): void {
                        $logged[] = $event->message;
                    });
                });
            });

            expect($app->bound('fixture.billing'))->toBeTrue()
                ->and($app->make(Discovery::class)->source())->toBe('scan');
        }
        expect($scans->count)->toBe(2)
            ->and(glob($fx->path('locked/*')) ?: [])->toBe([])
            ->and($logged)->toHaveCount(1)
            ->and($logged[0] ?? '')->toContain('cannot write the automatic discovery cache')->toContain('mod-discovery.auto.php');
    } finally {
        if ($setup === 'read-only') {
            chmod($cache, 0755);
        }
        $fx->destroy();
    }
})->with(['read-only', 'not a directory']);

it('keeps an explicit mod:cache file ahead of the automatic cache, exactly as before', DiscoveryFixture::around(function (DiscoveryFixture $fx) {
    $layout = autoTree($fx);
    autoSettle($fx);
    autoRequest($fx, $layout, ['auto_cache' => false])->cacheInventory();
    $explicit = $fx->path('bootstrap/cache/mod-discovery.php');
    $written = file_get_contents($explicit);
    $scans = autoScans();

    // A replay does not see new classes, with or without the automatic cache.
    $fx->write('app/Modules/Billing/Providers/LateServiceProvider.php', Sources::provider('App\\Modules\\Billing\\Providers', 'LateServiceProvider', 'fixture.late'));
    $cached = autoRequest($fx, $layout);

    expect($cached->source())->toBe('cache')
        ->and($cached->inventory()->classes(DiscoveryType::Provider))->not->toContain($fx->class('App\\Modules\\Billing\\Providers\\LateServiceProvider'))
        ->and($written)->not->toContain("'watched'")
        ->and($scans->count)->toBe(0);

    // Stale under the scan policy: scanned, never rewritten, no automatic cache.
    $other = Layouts::definition('modules');
    $other['kinds']['provider']['segments'] = ['Modules', '{module}', 'Registered'];
    $stale = autoRequest($fx, $fx->preset($other));

    expect($stale->source())->toBe('scan')
        ->and($stale->staleCacheReason())->toContain('different layout')
        ->and(file_get_contents($explicit))->toBe($written)
        ->and(is_file($fx->path('bootstrap/cache/mod-discovery.auto.php')))->toBeFalse()
        ->and(fn () => autoRequest($fx, $fx->preset($other), ['on_stale_cache' => 'fail']))->toThrow(InvalidDiscoveryCache::class);
}));

it('removes the automatic cache with mod:clear', DiscoveryFixture::around(function (DiscoveryFixture $fx) {
    $layout = autoTree($fx);
    autoSettle($fx);
    autoRequest($fx, $layout);
    $this->app->setBasePath($fx->path());
    DiscoveryRegistrar::register($this->app, $layout, DiscoveryOptions::fromConfig(['auto_cache' => true]));
    $this->app->make(Kernel::class)->registerCommand($this->app->make(DiscoveryClearCommand::class));

    expect(is_file($fx->path('bootstrap/cache/mod-discovery.auto.php')))->toBeTrue();

    $this->artisan('mod:clear')->expectsOutputToContain('Discovery cache cleared.')->assertSuccessful();
    $this->artisan('mod:clear')->expectsOutputToContain('No discovery cache to clear.')->assertSuccessful();

    expect(is_file($fx->path('bootstrap/cache/mod-discovery.auto.php')))->toBeFalse();
}));

it('turns the automatic cache on outside production unless configured', function (string $environment, ?bool $configured, bool $writes) {
    /** @var TestCase $this */
    $fx = DiscoveryFixture::create();

    try {
        $layout = autoTree($fx);
        autoSettle($fx);

        $app = $this->bootApplicationUsing(function (Application $app) use ($fx, $layout, $environment, $configured): void {
            $app->setBasePath($fx->path());
            $app->detectEnvironment(fn (): string => $environment);
            $app->make('config')->set('mod.discovery', ['enabled' => true]);
            $app->booting(function () use ($app, $layout, $configured): void {
                $app->instance(CompiledLayout::class, $layout);
                // After the test case's own default, as an app's config/mod.php would read.
                $app->make('config')->set('mod.discovery.auto_cache', $configured);
            });
        });

        expect($app->bound('fixture.billing'))->toBeTrue()
            ->and(is_file($fx->path('bootstrap/cache/mod-discovery.auto.php')))->toBe($writes);
    } finally {
        $fx->destroy();
    }
})->with([
    'local' => ['local', null, true],
    'production' => ['production', null, false],
    'local, switched off' => ['local', false, false],
    'production, switched on' => ['production', true, true],
]);

it('reports the automatic cache in mod:list', DiscoveryFixture::around(function (DiscoveryFixture $fx) {
    $layout = autoTree($fx);
    autoSettle($fx);

    for ($request = 0; $request < 2; $request++) {
        $this->bootApplicationUsing(function (Application $app) use ($fx, $layout): void {
            $app->setBasePath($fx->path());
            $app->make('config')->set('mod.discovery', ['enabled' => true, 'auto_cache' => true]);
            $app->booting(fn () => $app->instance(CompiledLayout::class, $layout));
        });
    }

    $this->artisan('mod:list')->expectsOutputToContain('Discovery (automatic cache): 2 providers')->assertSuccessful();
}));
