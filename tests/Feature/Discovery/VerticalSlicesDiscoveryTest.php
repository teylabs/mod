<?php

use Illuminate\Contracts\Console\Kernel;
use Tey\Mod\Discovery\DiscoveryOptions;
use Tey\Mod\Discovery\DiscoveryRegistrar;
use Tey\Mod\Discovery\DiscoveryType;
use Tey\Mod\Discovery\RejectionReason;
use Tey\Mod\Layout\CompiledLayout;
use Tey\Mod\Tests\Feature\Discovery\Support\DiscoveryFixture;
use Tey\Mod\Tests\Feature\Discovery\Support\Sources;

/*
 * The Command-message trap: a slice's application message is a class named
 * Command. It must never become an Artisan command.
 */
function slicesTree(DiscoveryFixture $fx): CompiledLayout
{
    $message = 'public function __construct(public string $customer = "") {}';

    $fx
        ->write('app/Billing/CreateInvoice/Command.php', Sources::plain('App\\Billing\\CreateInvoice', 'Command', $message))
        ->write('app/Billing/CreateInvoice/Handler.php', Sources::plain('App\\Billing\\CreateInvoice', 'Handler'))
        ->write('app/Billing/VoidInvoice/Command.php', Sources::plain('App\\Billing\\VoidInvoice', 'Command', $message))
        ->write('app/Console/Commands/PruneInvoices.php', Sources::command('App\\Console\\Commands', 'PruneInvoices', 'fixture:prune-invoices'))
        ->write('app/Providers/AppServiceProvider.php', Sources::provider('App\\Providers', 'AppServiceProvider', 'fixture.app'));

    return $fx->layout('vertical-slices');
}

it('registers the real Artisan command and never the slice messages named Command', DiscoveryFixture::around(function (DiscoveryFixture $fx) {
    $preset = slicesTree($fx);
    $this->app->setBasePath($fx->path());

    $inventory = DiscoveryRegistrar::register($this->app, $preset)->inventory();
    $artisan = $this->app->make(Kernel::class)->all();

    expect($inventory->classes(DiscoveryType::Command))->toBe([$fx->class('App\\Console\\Commands\\PruneInvoices')])
        ->and($artisan)->toHaveKey('fixture:prune-invoices');

    $registered = array_map(fn ($command) => $command::class, $artisan);

    expect($registered)->not->toContain($fx->class('App\\Billing\\CreateInvoice\\Command'))
        ->and($registered)->not->toContain($fx->class('App\\Billing\\VoidInvoice\\Command'));
}));

it('scans only the declared command root, so messages are not even candidates', DiscoveryFixture::around(function (DiscoveryFixture $fx) {
    $preset = slicesTree($fx);
    $this->app->setBasePath($fx->path());

    $inventory = DiscoveryRegistrar::register($this->app, $preset)->inventory();

    expect($inventory->rejection('app/Billing/CreateInvoice/Command.php'))->toBeNull()
        ->and($inventory->rejections)->toBe([]);
}));

it('rejects every message by eligibility even when a host maps the message kind to commands', DiscoveryFixture::around(function (DiscoveryFixture $fx) {
    $preset = slicesTree($fx);
    $this->app->setBasePath($fx->path());

    $inventory = DiscoveryRegistrar::register($this->app, $preset, DiscoveryOptions::fromConfig([
        'kinds' => ['message' => 'command'],
    ]))->inventory();

    foreach (['app/Billing/CreateInvoice/Command.php', 'app/Billing/VoidInvoice/Command.php'] as $path) {
        expect($inventory->rejection($path)?->reason)->toBe(RejectionReason::Ineligible)
            ->and($inventory->rejection($path)?->detail)->toContain('does not extend Illuminate\\Console\\Command');
    }

    expect($inventory->classes(DiscoveryType::Command))->toBe([$fx->class('App\\Console\\Commands\\PruneInvoices')])
        ->and($inventory->rejection('app/Billing/CreateInvoice/Handler.php'))->toBeNull();
}));

it('never registers a provider in an excluded root or for a kind the preset does not declare', DiscoveryFixture::around(function (DiscoveryFixture $fx) {
    $preset = slicesTree($fx);
    $this->app->setBasePath($fx->path());

    $inventory = DiscoveryRegistrar::register($this->app, $preset)->inventory();

    expect($inventory->classes(DiscoveryType::Provider))->toBe([])
        ->and($this->app->bound('fixture.app'))->toBeFalse();
}));
