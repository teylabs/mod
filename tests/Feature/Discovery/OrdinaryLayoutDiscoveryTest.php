<?php

use Illuminate\Contracts\Console\Kernel;
use Tey\Mod\Discovery\Discovery;
use Tey\Mod\Discovery\DiscoveryOptions;
use Tey\Mod\Discovery\DiscoveryRegistrar;
use Tey\Mod\Discovery\DiscoveryType;
use Tey\Mod\Discovery\RejectionReason;
use Tey\Mod\Layout\CompiledLayout;
use Tey\Mod\Tests\Feature\Discovery\Support\DiscoveryFixture;
use Tey\Mod\Tests\Feature\Discovery\Support\Sources;

function ordinaryTree(DiscoveryFixture $fx): CompiledLayout
{

    $events = '\\{{ns}}\\App\\Events\\';

    $fx
        ->write('app/Providers/BillingServiceProvider.php', Sources::provider('App\\Providers', 'BillingServiceProvider', 'fixture.billing'))
        ->write('app/Providers/LegacyServiceProvider.php', Sources::plain('App\\Providers', 'LegacyServiceProvider'))
        ->write('app/Providers/BaseServiceProvider.php', "<?php\n\nnamespace {{ns}}\\App\\Providers;\n\nabstract class BaseServiceProvider extends \\Illuminate\\Support\\ServiceProvider {}\n")
        ->write('app/Console/Commands/SendInvoices.php', Sources::command('App\\Console\\Commands', 'SendInvoices', 'fixture:send-invoices'))
        ->write('app/Console/Commands/InvoiceFormatter.php', Sources::plain('App\\Console\\Commands', 'InvoiceFormatter'))
        ->write('app/Events/InvoicePaid.php', Sources::event('App\\Events', 'InvoicePaid'))
        ->write('app/Events/InvoiceVoided.php', Sources::event('App\\Events', 'InvoiceVoided'))
        ->write('app/Listeners/SendReceipt.php', Sources::listener('App\\Listeners', 'SendReceipt', 'handle', $events.'InvoicePaid'))
        ->write('app/Listeners/AuditInvoice.php', Sources::listener('App\\Listeners', 'AuditInvoice', '__invoke', $events.'InvoicePaid|'.$events.'InvoiceVoided'))
        ->write('app/Listeners/Untyped.php', Sources::plain('App\\Listeners', 'Untyped', 'public function handle($event): void {}'))
        ->write('app/Models/Invoice.php', Sources::plain('App\\Models', 'Invoice'))
        ->write('app/helpers.php', "<?php\n\nfunction fixture_helper(): void {}\n");

    return $fx->layout('ordinary');
}

it('discovers providers, commands and listeners with provenance', DiscoveryFixture::around(function (DiscoveryFixture $fx) {
    $preset = ordinaryTree($fx);
    $this->app->setBasePath($fx->path());

    $inventory = DiscoveryRegistrar::register($this->app, $preset)->inventory();

    expect($inventory->classes(DiscoveryType::Provider))->toBe([$fx->class('App\\Providers\\BillingServiceProvider')])
        ->and($inventory->classes(DiscoveryType::Command))->toBe([$fx->class('App\\Console\\Commands\\SendInvoices')])
        ->and($inventory->classes(DiscoveryType::Listener))->toBe([
            $fx->class('App\\Listeners\\AuditInvoice'),
            $fx->class('App\\Listeners\\SendReceipt'),
        ]);

    $provider = $inventory->ofKind('provider')[0];
    expect($provider->path)->toBe('app/Providers/BillingServiceProvider.php')
        ->and($provider->context)->toBe([])
        ->and($inventory->ofKind('listener')[0]->events)->toBe([
            ['event' => $fx->class('App\\Events\\InvoicePaid'), 'method' => '__invoke'],
            ['event' => $fx->class('App\\Events\\InvoiceVoided'), 'method' => '__invoke'],
        ]);
}));

it('registers what it discovers into the running application', DiscoveryFixture::around(function (DiscoveryFixture $fx) {
    $preset = ordinaryTree($fx);
    $this->app->setBasePath($fx->path());

    DiscoveryRegistrar::register($this->app, $preset);

    expect(DiscoveryFixture::binding($this->app, 'fixture.billing'))->toBe($fx->class('App\\Providers\\BillingServiceProvider'))
        ->and($this->app->make(Kernel::class)->all())->toHaveKey('fixture:send-invoices');

    $paid = $fx->class('App\\Events\\InvoicePaid');
    event(new $paid);

    expect(DiscoveryFixture::handled($this->app))->toEqualCanonicalizing([
        $fx->class('App\\Listeners\\SendReceipt').'@handle:'.$paid,
        $fx->class('App\\Listeners\\AuditInvoice').'@__invoke:'.$paid,
    ]);
}));

it('rejects owned classes that are not semantically eligible', DiscoveryFixture::around(function (DiscoveryFixture $fx) {
    $preset = ordinaryTree($fx);
    $this->app->setBasePath($fx->path());

    $inventory = DiscoveryRegistrar::register($this->app, $preset)->inventory();

    foreach ([
        'app/Providers/LegacyServiceProvider.php' => 'does not extend',
        'app/Providers/BaseServiceProvider.php' => 'not a concrete class',
        'app/Console/Commands/InvoiceFormatter.php' => 'does not extend Illuminate\\Console\\Command',
        'app/Listeners/Untyped.php' => 'no public handle or __invoke method',
    ] as $path => $detail) {
        $rejection = $inventory->rejection($path);

        expect($rejection?->reason)->toBe(RejectionReason::Ineligible, $path)
            ->and($rejection?->detail)->toContain($detail);
    }

    expect($this->app->bound($fx->class('App\\Providers\\LegacyServiceProvider')))->toBeFalse()
        ->and($this->app->make(Kernel::class)->all())->not->toHaveKey('invoice-formatter');
}));

it('reports files no rule owns and stays silent about files other kinds own', DiscoveryFixture::around(function (DiscoveryFixture $fx) {
    $preset = ordinaryTree($fx);
    $this->app->setBasePath($fx->path());

    $inventory = DiscoveryRegistrar::register($this->app, $preset)->inventory();

    expect($inventory->rejection('app/helpers.php')?->reason)->toBe(RejectionReason::NotOwned)
        ->and($inventory->rejection('app/Models/Invoice.php'))->toBeNull()
        ->and($inventory->rejection('app/Events/InvoicePaid.php'))->toBeNull();
}));

it('disables discovery per kind and globally', DiscoveryFixture::around(function (DiscoveryFixture $fx) {
    $preset = ordinaryTree($fx);
    $this->app->setBasePath($fx->path());

    $inventory = DiscoveryRegistrar::register($this->app, $preset, DiscoveryOptions::fromConfig([
        'kinds' => ['provider' => false, 'listener' => false],
    ]))->inventory();

    expect($inventory->classes(DiscoveryType::Provider))->toBe([])
        ->and($inventory->classes(DiscoveryType::Listener))->toBe([])
        ->and($inventory->classes(DiscoveryType::Command))->toBe([$fx->class('App\\Console\\Commands\\SendInvoices')])
        ->and($this->app->bound('fixture.billing'))->toBeFalse();

    $nothing = new Discovery($preset, DiscoveryOptions::fromConfig(['enabled' => false]), $fx->path());

    expect($nothing->inventory()->isEmpty())->toBeTrue()
        ->and($nothing->inventory()->rejections)->toBe([])
        ->and(array_map(fn ($definition) => $definition->enabled, $nothing->definitions()))->each->toBeFalse();
}));
