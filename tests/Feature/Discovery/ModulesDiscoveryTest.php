<?php

use Tey\Mod\Discovery\DiscoveryOptions;
use Tey\Mod\Discovery\DiscoveryRegistrar;
use Tey\Mod\Discovery\DiscoveryType;
use Tey\Mod\Discovery\RejectionReason;
use Tey\Mod\Preset\Preset;
use Tey\Mod\Tests\Feature\Discovery\Support\DiscoveryFixture;
use Tey\Mod\Tests\Feature\Discovery\Support\Sources;
use Tey\Mod\Tests\Fixtures\Layouts;

/*
 * The cookbook's app/Modules/<Module>, extended with two module-owned kinds
 * whose ids are not the default type names: discovery follows host settings.
 */
function modulesTree(DiscoveryFixture $fx): Preset
{
    $definition = Layouts::definition('modules');
    $definition['kinds']['subscriber'] = ['shape' => 'class', 'name' => 'as-given', 'root' => 'app', 'segments' => ['Modules', '{module}', 'Listeners']];
    $definition['kinds']['console'] = ['shape' => 'class', 'name' => 'as-given', 'root' => 'app', 'segments' => ['Modules', '{module}', 'Console']];

    $fx
        ->write('app/Modules/Billing/Providers/BillingServiceProvider.php', Sources::provider('App\\Modules\\Billing\\Providers', 'BillingServiceProvider', 'fixture.billing'))
        ->write('app/Modules/Shipping/Providers/ShippingServiceProvider.php', Sources::provider('App\\Modules\\Shipping\\Providers', 'ShippingServiceProvider', 'fixture.shipping'))
        ->write('app/Modules/Billing/Events/InvoicePaid.php', Sources::event('App\\Modules\\Billing\\Events', 'InvoicePaid'))
        ->write('app/Modules/Shipping/Listeners/ShipOnPayment.php', Sources::listener('App\\Modules\\Shipping\\Listeners', 'ShipOnPayment', 'handle', '\\{{ns}}\\App\\Modules\\Billing\\Events\\InvoicePaid'))
        ->write('app/Modules/Billing/Console/CloseBooks.php', Sources::command('App\\Modules\\Billing\\Console', 'CloseBooks', 'fixture:close-books'))
        ->write('app/Modules/Billing/Models/Invoice.php', Sources::plain('App\\Modules\\Billing\\Models', 'Invoice'))
        ->write('app/Support/SupportServiceProvider.php', Sources::provider('App\\Support', 'SupportServiceProvider', 'fixture.support'))
        ->write('app/UI/Components/Card.php', Sources::plain('App\\UI\\Components', 'Card'));

    return $fx->preset($definition);
}

function modulesOptions(): DiscoveryOptions
{
    return DiscoveryOptions::fromConfig(['kinds' => ['subscriber' => 'listener', 'console' => 'command']]);
}

it('discovers module-owned classes with their module as provenance', DiscoveryFixture::around(function (DiscoveryFixture $fx) {
    $preset = modulesTree($fx);
    $this->app->setBasePath($fx->path());

    $inventory = DiscoveryRegistrar::register($this->app, $preset, modulesOptions())->inventory();

    expect(array_map(fn ($entry) => [$entry->kindId, $entry->context, $entry->path], $inventory->entries))->toEqualCanonicalizing([
        ['console', ['module' => 'Billing'], 'app/Modules/Billing/Console/CloseBooks.php'],
        ['provider', ['module' => 'Billing'], 'app/Modules/Billing/Providers/BillingServiceProvider.php'],
        ['provider', ['module' => 'Shipping'], 'app/Modules/Shipping/Providers/ShippingServiceProvider.php'],
        ['subscriber', ['module' => 'Shipping'], 'app/Modules/Shipping/Listeners/ShipOnPayment.php'],
    ]);

    expect(DiscoveryFixture::binding($this->app, 'fixture.billing'))->toBe($fx->class('App\\Modules\\Billing\\Providers\\BillingServiceProvider'))
        ->and(DiscoveryFixture::binding($this->app, 'fixture.shipping'))->toBe($fx->class('App\\Modules\\Shipping\\Providers\\ShippingServiceProvider'));

    $paid = $fx->class('App\\Modules\\Billing\\Events\\InvoicePaid');
    event(new $paid);

    expect(DiscoveryFixture::handled($this->app))->toBe([$fx->class('App\\Modules\\Shipping\\Listeners\\ShipOnPayment').'@handle:'.$paid]);
}));

it('reports sibling roots as not owned and never registers their providers', DiscoveryFixture::around(function (DiscoveryFixture $fx) {
    $preset = modulesTree($fx);
    $this->app->setBasePath($fx->path());

    $inventory = DiscoveryRegistrar::register($this->app, $preset, modulesOptions())->inventory();

    expect($inventory->rejection('app/Support/SupportServiceProvider.php')?->reason)->toBe(RejectionReason::NotOwned)
        ->and($inventory->rejection('app/Support/SupportServiceProvider.php')?->detail)->toContain('excluded root')
        ->and($inventory->rejection('app/UI/Components/Card.php')?->reason)->toBe(RejectionReason::NotOwned)
        ->and($inventory->rejection('app/Modules/Billing/Models/Invoice.php'))->toBeNull()
        ->and($this->app->bound('fixture.support'))->toBeFalse();
}));

it('discovers nothing for kinds the host has not mapped', DiscoveryFixture::around(function (DiscoveryFixture $fx) {
    $preset = modulesTree($fx);
    $this->app->setBasePath($fx->path());

    $inventory = DiscoveryRegistrar::register($this->app, $preset)->inventory();

    expect($inventory->classes(DiscoveryType::Listener))->toBe([])
        ->and($inventory->classes(DiscoveryType::Command))->toBe([])
        ->and($inventory->classes(DiscoveryType::Provider))->toHaveCount(2);
}));
