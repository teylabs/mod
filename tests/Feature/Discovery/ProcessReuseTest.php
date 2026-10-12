<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Foundation\Application;
use Tey\Mod\Discovery\Discovery;
use Tey\Mod\Discovery\DiscoveryOptions;
use Tey\Mod\Discovery\DiscoveryType;
use Tey\Mod\Discovery\InProcessInventory;
use Tey\Mod\Facades\Mod;
use Tey\Mod\Layout\CompiledLayout;
use Tey\Mod\Tests\Feature\Discovery\Support\DiscoveryFixture;
use Tey\Mod\Tests\Feature\Discovery\Support\Sources;
use Tey\Mod\Tests\Fixtures\Layouts;
use Tey\Mod\Tests\TestCase;

/** @param array<string, mixed> $config */
function reuseBoot(TestCase $case, DiscoveryFixture $fx, CompiledLayout $layout, array $config = []): Application
{
    return $case->bootApplicationUsing(function (Application $app) use ($fx, $layout, $config): void {
        $app->setBasePath($fx->path());
        $app->make('config')->set('mod.discovery', ['enabled' => true, ...$config]);
        $app->booting(fn () => $app->instance(CompiledLayout::class, $layout));
    }, reuseDiscovery: true);
}

function reuseTree(DiscoveryFixture $fx): CompiledLayout
{
    $fx->write('app/Providers/BillingServiceProvider.php', Sources::provider('App\\Providers', 'BillingServiceProvider', 'fixture.billing'))
        ->write('app/Console/Commands/Report.php', Sources::command('App\\Console\\Commands', 'Report', 'fixture:report'))
        ->write('app/Events/InvoicePaid.php', Sources::event('App\\Events', 'InvoicePaid'))
        ->write('app/Listeners/SendReceipt.php', Sources::listener('App\\Listeners', 'SendReceipt', 'handle', '\\{{ns}}\\App\\Events\\InvoicePaid'));

    return $fx->layout('ordinary');
}

final class ReuseScanCounter
{
    public int $count = 0;

    public function __construct()
    {
        InProcessInventory::instance()->onScan = function (): void {
            $this->count++;
        };
    }
}

it('scans once across many fresh application boots and registers on every boot', DiscoveryFixture::around(function (DiscoveryFixture $fx) {
    $scans = new ReuseScanCounter;
    $layout = reuseTree($fx);
    $paid = $fx->class('App\\Events\\InvoicePaid');

    for ($boot = 0; $boot < 25; $boot++) {
        $app = reuseBoot($this, $fx, $layout);
        $app->make('events')->dispatch(new $paid);

        expect($app->bound('fixture.billing'))->toBeTrue()
            ->and($app->make(Kernel::class)->all())->toHaveKey('fixture:report')
            ->and(DiscoveryFixture::handled($app))->toHaveCount(1);
    }

    expect($scans->count)->toBe(1)
        ->and(is_file($fx->path('bootstrap/cache/mod-discovery.php')))->toBeFalse();
}));

it('discovers added and removed listeners in nested directories on the next boot', DiscoveryFixture::around(function (DiscoveryFixture $fx) {
    $scans = new ReuseScanCounter;
    reuseTree($fx);
    $definition = Layouts::definition('ordinary');
    $definition['dimensions'] = ['folder'];
    $definition['kinds']['listener']['segments'] = ['Listeners', '{folder?}'];
    $layout = $fx->preset($definition);
    reuseBoot($this, $fx, $layout);
    $fx->write('app/Listeners/Nested/LateReceipt.php', Sources::listener('App\\Listeners\\Nested', 'LateReceipt', 'handle', '\\{{ns}}\\App\\Events\\InvoicePaid'));
    // Directory mtimes have one-second resolution; advance the changed parent deterministically.
    touch($fx->path('app/Listeners'), time() + 2);
    $app = reuseBoot($this, $fx, $layout);
    $paid = $fx->class('App\\Events\\InvoicePaid');
    $app->make('events')->dispatch(new $paid);
    expect($scans->count)->toBe(2)
        ->and(DiscoveryFixture::handled($app))->toHaveCount(2);

    unlink($fx->path('app/Listeners/Nested/LateReceipt.php'));
    touch($fx->path('app/Listeners/Nested'), time() + 4);
    $app = reuseBoot($this, $fx, $layout);
    $app->make('events')->dispatch(new $paid);
    expect($scans->count)->toBe(3)
        ->and(DiscoveryFixture::handled($app))->toHaveCount(1);
}));

it('rescans when the layout or discovery settings change', DiscoveryFixture::around(function (DiscoveryFixture $fx) {
    $scans = new ReuseScanCounter;
    $layout = reuseTree($fx);
    reuseBoot($this, $fx, $layout);
    $app = reuseBoot($this, $fx, $layout, ['file_types' => ['listener' => false]]);
    expect($scans->count)->toBe(2)
        ->and($app->make(Discovery::class)->inventory()->ofType(DiscoveryType::Listener))->toBe([]);

    $definition = Layouts::definition('ordinary');
    $definition['kinds']['listener']['segments'] = ['Handlers'];
    $app = reuseBoot($this, $fx, $fx->preset($definition));
    expect($scans->count)->toBe(3)
        ->and($app->make(Discovery::class)->inventory()->ofType(DiscoveryType::Listener))->toBe([]);
}));

it('flushes the process inventory before the next boot', DiscoveryFixture::around(function (DiscoveryFixture $fx) {
    $scans = new ReuseScanCounter;
    $layout = reuseTree($fx);
    reuseBoot($this, $fx, $layout);
    $mtime = filemtime($fx->path('app/Listeners'));
    $fx->write('app/Listeners/LateReceipt.php', Sources::listener('App\\Listeners', 'LateReceipt', 'handle', '\\{{ns}}\\App\\Events\\InvoicePaid'));
    touch($fx->path('app/Listeners'), $mtime);
    Mod::flushDiscovery();
    $app = reuseBoot($this, $fx, $layout);
    expect($scans->count)->toBe(2)
        ->and($app->make(Discovery::class)->inventory()->ofType(DiscoveryType::Listener))->toHaveCount(2);
}));

it('scans on every boot when process reuse is disabled', DiscoveryFixture::around(function (DiscoveryFixture $fx) {
    $scans = new ReuseScanCounter;
    $layout = reuseTree($fx);
    for ($boot = 0; $boot < 3; $boot++) {
        reuseBoot($this, $fx, $layout, ['reuse_in_process' => false]);
    }
    expect($scans->count)->toBe(3);
}));

it('detects a newly created discovery root', DiscoveryFixture::around(function (DiscoveryFixture $fx) {
    $scans = new ReuseScanCounter;
    $definition = Layouts::definition('ordinary');
    $definition['roots']['app']['path'] = 'src';
    $layout = $fx->preset($definition);
    reuseBoot($this, $fx, $layout);
    $fx->write('src/Providers/LateServiceProvider.php', Sources::provider('App\\Providers', 'LateServiceProvider', 'fixture.late'));
    $app = reuseBoot($this, $fx, $layout);
    expect($scans->count)->toBe(2)
        ->and($app->bound('fixture.late'))->toBeTrue();
}));

it('invalidates factory pairs when a separate factory root changes', DiscoveryFixture::around(function (DiscoveryFixture $fx) {
    $scans = new ReuseScanCounter;
    $layout = reuseTree($fx);
    $fx->write('app/Models/Invoice.php', <<<'PHP'
    <?php
    namespace {{ns}}\App\Models;
    class Invoice extends \Illuminate\Database\Eloquent\Model {}
    PHP);
    reuseBoot($this, $fx, $layout);
    $fx->write('database/factories/InvoiceFactory.php', <<<'PHP'
    <?php
    namespace {{ns}}\Database\Factories;
    class InvoiceFactory extends \Illuminate\Database\Eloquent\Factories\Factory
    {
        public function definition(): array { return []; }
    }
    PHP);
    touch($fx->path('database/factories'), time() + 2);
    $app = reuseBoot($this, $fx, $layout);
    expect($scans->count)->toBe(2)
        ->and($app->make(Discovery::class)->inventory()->pairs(DiscoveryType::Factory))
        ->toBe([$fx->class('App\\Models\\Invoice') => $fx->class('Database\\Factories\\InvoiceFactory')]);
}));

it('does not reuse an inventory from a different base path', DiscoveryFixture::around(function (DiscoveryFixture $fx) {
    $scans = new ReuseScanCounter;
    $layout = reuseTree($fx);
    reuseBoot($this, $fx, $layout);
    $other = DiscoveryFixture::create();
    try {
        $inventory = (new Discovery($layout, new DiscoveryOptions, $other->path()))->inventory();
        expect($scans->count)->toBe(2)
            ->and($inventory->ofType(DiscoveryType::Provider))->toBe([]);
    } finally {
        $other->destroy();
    }
}));

it('does not memoize a custom candidate source across boots', DiscoveryFixture::around(function (DiscoveryFixture $fx) {
    $scans = new ReuseScanCounter;
    $layout = reuseTree($fx);
    $options = DiscoveryOptions::fromConfig([])->withCandidates(fn () => []);
    expect((new Discovery($layout, $options, $fx->path()))->inventory()->ofType(DiscoveryType::Provider))->toBe([]);
    $options = $options->withCandidates(fn () => ['app/Providers/BillingServiceProvider.php']);
    expect((new Discovery($layout, $options, $fx->path()))->inventory()->ofType(DiscoveryType::Provider))->toHaveCount(1)
        ->and($scans->count)->toBe(2);
}));

it('keeps valid and stale cache files ahead of process reuse', DiscoveryFixture::around(function (DiscoveryFixture $fx) {
    $scans = new ReuseScanCounter;
    $layout = reuseTree($fx);
    $app = reuseBoot($this, $fx, $layout);
    $app->make(Discovery::class)->cacheInventory();
    $scans->count = 0;
    $fx->write('app/Providers/LateServiceProvider.php', Sources::provider('App\\Providers', 'LateServiceProvider', 'fixture.late'));
    touch($fx->path('app/Providers'), time() + 2);
    for ($boot = 0; $boot < 3; $boot++) {
        Mod::flushDiscovery();
        $app = reuseBoot($this, $fx, $layout, ['reuse_in_process' => false]);
        expect($app->make(Discovery::class)->source())->toBe('cache')
            ->and($app->bound('fixture.late'))->toBeFalse();
    }
    expect($scans->count)->toBe(0);

    $cache = $fx->path('bootstrap/cache/mod-discovery.php');
    file_put_contents($cache, "<?php return ['schema' => 0];");
    for ($boot = 0; $boot < 2; $boot++) {
        $app = reuseBoot($this, $fx, $layout);
        expect($app->bound('fixture.late'))->toBeTrue()
            ->and($app->make(Discovery::class)->source())->toBe('scan');
    }
    expect($scans->count)->toBe(2)
        ->and(file_get_contents($cache))->toBe("<?php return ['schema' => 0];");
}));
