<?php

use Tey\Mod\Discovery\Discovery;
use Tey\Mod\Discovery\DiscoveryDefinition;
use Tey\Mod\Discovery\DiscoveryOptions;
use Tey\Mod\Discovery\DiscoveryType;
use Tey\Mod\Discovery\RejectionReason;
use Tey\Mod\Placement\Root;
use Tey\Mod\Preset\Preset;
use Tey\Mod\Support\Path;
use Tey\Mod\Tests\Feature\Discovery\Support\DiscoveryFixture;
use Tey\Mod\Tests\Feature\Discovery\Support\Sources;

/*
 * Discover-anywhere with except, directories, subscribers and a
 * host-supplied candidate-file source, end to end on a grouped layout.
 */
function groupedTree(DiscoveryFixture $fx): Preset
{
    $definition = [
        'roots' => ['src' => ['namespace' => 'Src\\', 'path' => 'src']],
        'dimensions' => ['group'],
        'kinds' => [
            'provider' => ['shape' => 'class', 'name' => 'as-given', 'root' => 'src', 'segments' => ['{group+}', 'Providers'], 'discover' => 'anywhere', 'except' => ['Tests', 'Database/Migrations']],
            'command' => ['shape' => 'class', 'name' => 'as-given', 'root' => 'src', 'segments' => ['{group+}', 'Commands'], 'discover' => 'anywhere', 'except' => ['Tests', 'Database/Migrations']],
            'listener' => ['shape' => 'class', 'name' => 'as-given', 'root' => 'src', 'segments' => ['{group+}', 'Listeners'], 'discover' => 'anywhere', 'except' => ['Tests', 'Database/Migrations']],
            'subscriber' => ['shape' => 'class', 'name' => 'as-given', 'root' => 'src', 'segments' => ['{group+}', 'Listeners'], 'priority' => 1, 'discover' => 'anywhere', 'except' => ['Tests', 'Database/Migrations']],
            'model' => ['shape' => 'class', 'name' => 'as-given', 'root' => 'src', 'segments' => ['{group+}', 'Models']],
            'migration' => ['shape' => 'file', 'name' => 'timestamped', 'root' => 'src', 'segments' => ['{group+}', 'Database', 'Migrations']],
        ],
    ];

    $fx
        ->write('src/Billing/Providers/BillingProvider.php', Sources::provider('Src\\Billing\\Providers', 'BillingProvider', 'fixture.billing'))
        ->write('src/Billing/Support/Nested/OddProvider.php', Sources::provider('Src\\Billing\\Support\\Nested', 'OddProvider', 'fixture.odd'))
        ->write('src/Billing/Tests/TestProvider.php', Sources::provider('Src\\Billing\\Tests', 'TestProvider', 'fixture.tests'))
        ->write('src/Billing/Models/Invoice.php', Sources::plain('Src\\Billing\\Models', 'Invoice'))
        ->write('src/Billing/Models/ModelProvider.php', Sources::provider('Src\\Billing\\Models', 'ModelProvider', 'fixture.model-provider'))
        ->write('src/Billing/Invoicing/Console/CloseBooks.php', Sources::command('Src\\Billing\\Invoicing\\Console', 'CloseBooks', 'fixture:close-books'))
        ->write('src/Billing/Events/InvoicePaid.php', Sources::event('Src\\Billing\\Events', 'InvoicePaid'))
        ->write('src/Billing/Listeners/ShipOnPayment.php', Sources::listener('Src\\Billing\\Listeners', 'ShipOnPayment', 'handle', '\\{{ns}}\\Src\\Billing\\Events\\InvoicePaid'))
        ->write('src/Billing/Listeners/InvoiceSubscriber.php', <<<'PHP'
            <?php

            namespace {{ns}}\Src\Billing\Listeners;

            use Illuminate\Events\Dispatcher;

            class InvoiceSubscriber
            {
                public function onPaid($event): void
                {
                    $handled = app()->bound('fixture.handled') ? app('fixture.handled') : [];
                    $handled[] = static::class.'@onPaid:'.get_class($event);
                    app()->instance('fixture.handled', $handled);
                }

                public function subscribe(Dispatcher $events): void
                {
                    $events->listen(\{{ns}}\Src\Billing\Events\InvoicePaid::class, [$this, 'onPaid']);
                }
            }
            PHP)
        ->write('src/Billing/Database/Migrations/2026_01_01_000000_create_invoices_table.php', "<?php\n// migration\n")
        ->write('src/Billing/Invoicing/Database/Migrations/.gitkeep', '')
        ->write('src/Shipping/Database/Migrations/readme.txt', 'no php here');

    return $fx->preset($definition);
}

/**
 * @param  array<string, mixed>  $extra
 */
function groupedOptions(array $extra = []): DiscoveryOptions
{
    return DiscoveryOptions::fromConfig(['kinds' => ['migration' => 'directory'] + $extra]);
}

it('discovers providers and commands anywhere below the group, skipping excluded folders', DiscoveryFixture::around(function (DiscoveryFixture $fx) {
    $preset = groupedTree($fx);
    $discovery = new Discovery($preset, groupedOptions(), $fx->path());
    $inventory = $discovery->inventory();

    expect(array_map(fn ($e) => [$e->kindId, $e->type->value, $e->path, $e->context], $inventory->ofType(DiscoveryType::Provider)))->toBe([
        ['provider', 'provider', 'src/Billing/Models/ModelProvider.php', ['group' => 'Billing']],
        ['provider', 'provider', 'src/Billing/Providers/BillingProvider.php', ['group' => 'Billing']],
        ['provider', 'provider', 'src/Billing/Support/Nested/OddProvider.php', ['group' => 'Billing']],
    ]);

    expect(array_map(fn ($e) => $e->path, $inventory->ofType(DiscoveryType::Command)))->toBe(['src/Billing/Invoicing/Console/CloseBooks.php']);

    // Excluded folder: not registered, and not reported either (anywhere candidates are not owned).
    expect($inventory->rejection('src/Billing/Tests/TestProvider.php'))->toBeNull()
        ->and(array_map(fn ($e) => $e->path, $inventory->entries))->not->toContain('src/Billing/Tests/TestProvider.php');

    // A plain class owned by a template kind that is not discovered stays out, silently.
    expect($inventory->rejection('src/Billing/Models/Invoice.php'))->toBeNull();
}));

it('registers a class that listens and subscribes once, as a subscriber, and collects migration directories', DiscoveryFixture::around(function (DiscoveryFixture $fx) {
    $preset = groupedTree($fx);
    $this->app->setBasePath($fx->path());
    $discovery = new Discovery($preset, groupedOptions(), $fx->path());
    $inventory = $discovery->inventory();

    expect(array_map(fn ($e) => $e->path, $inventory->ofType(DiscoveryType::Listener)))->toBe(['src/Billing/Listeners/ShipOnPayment.php'])
        ->and(array_map(fn ($e) => $e->path, $inventory->ofType(DiscoveryType::Subscriber)))->toBe(['src/Billing/Listeners/InvoiceSubscriber.php'])
        ->and($inventory->directories('migration'))->toBe(['src/Billing/Database/Migrations', 'src/Billing/Invoicing/Database/Migrations', 'src/Shipping/Database/Migrations']);

    expect($discovery->registerListeners($this->app->make('events')))->toBe(2)
        ->and($discovery->registerListeners($this->app->make('events')))->toBe(0);

    $paid = $fx->class('Src\\Billing\\Events\\InvoicePaid');
    event(new $paid);

    expect(DiscoveryFixture::handled($this->app))->toBe([
        $fx->class('Src\\Billing\\Listeners\\ShipOnPayment').'@handle:'.$paid,
        $fx->class('Src\\Billing\\Listeners\\InvoiceSubscriber').'@onPaid:'.$paid,
    ]);
}));

it('replays widened inventories from the cache byte for byte', DiscoveryFixture::around(function (DiscoveryFixture $fx) {
    $preset = groupedTree($fx);
    $discovery = new Discovery($preset, groupedOptions(), $fx->path());
    $cold = $discovery->writeCache();

    $replay = (new Discovery($preset, groupedOptions(), $fx->path()))->inventory();

    expect($replay->equals($cold))->toBeTrue()
        ->and($replay->directories('migration'))->toBe($cold->directories('migration'))
        ->and($replay->ofType(DiscoveryType::Subscriber))->toHaveCount(1);
}));

it('takes candidate files from a host source and keeps ownership, eligibility and order', DiscoveryFixture::around(function (DiscoveryFixture $fx) {
    $preset = groupedTree($fx);
    $seen = [];
    $options = groupedOptions()->withCandidates(function (Root $root, string $basePath, DiscoveryDefinition $definition) use (&$seen): iterable {
        $seen[] = $root->path.'|'.$definition->kindId;
        $base = Path::join($basePath, $root->path);

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS)) as $file) {
            $pathname = Path::normalize($file->getPathname());

            if (! $file->isFile() || str_contains($pathname, '/Support/')) {
                continue; // the host skips its Support subtrees
            }

            if ($definition->kindId === 'command' && str_contains($pathname, '/Console/')) {
                continue; // and scopes candidates per discovered kind: no console files for the command kind
            }

            yield Path::join($root->path, (string) Path::relative($base, $pathname));
        }

        yield 'src/Billing/Providers/BillingProvider.php'; // duplicates collapse
        yield 'src/Billing/Models/notes.txt'; // non-php ignored
        yield 'app/Elsewhere/Provider.php'; // outside the root ignored
    });

    $discovery = new Discovery($preset, $options, $fx->path());
    $inventory = $discovery->inventory();

    expect(array_map(fn ($e) => $e->path, $inventory->ofType(DiscoveryType::Provider)))->toBe([
        'src/Billing/Models/ModelProvider.php',
        'src/Billing/Providers/BillingProvider.php',
    ])
        ->and(array_map(fn ($e) => $e->path, $inventory->ofType(DiscoveryType::Command)))->toBe([])
        ->and(array_values(array_unique($seen)))->toBe(['src|command', 'src|listener', 'src|provider', 'src|subscriber'])
        ->and($discovery->definitionsFingerprint())->not->toBe((new Discovery($preset, groupedOptions(), $fx->path()))->definitionsFingerprint());

    // Directories are mod's own walk, not the candidate source.
    expect($inventory->directories('migration'))->toHaveCount(3);
}));

it('still reports ineligible classes owned by a kind folder, not anywhere candidates', DiscoveryFixture::around(function (DiscoveryFixture $fx) {
    $preset = groupedTree($fx);
    $fx->write('src/Billing/Providers/NotAProvider.php', Sources::plain('Src\\Billing\\Providers', 'NotAProvider'))
        ->write('src/Billing/Support/Plain.php', Sources::plain('Src\\Billing\\Support', 'Plain'));

    $inventory = (new Discovery($preset, groupedOptions(), $fx->path()))->inventory();

    expect($inventory->rejection('src/Billing/Providers/NotAProvider.php')?->reason)->toBe(RejectionReason::Ineligible)
        ->and($inventory->rejection('src/Billing/Support/Plain.php'))->toBeNull();
}));
