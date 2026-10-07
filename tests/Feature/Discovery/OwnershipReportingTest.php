<?php

use Tey\Mod\Discovery\DiscoveryRegistrar;
use Tey\Mod\Discovery\DiscoveryType;
use Tey\Mod\Discovery\RejectionReason;
use Tey\Mod\Tests\Feature\Discovery\Support\DiscoveryFixture;
use Tey\Mod\Tests\Feature\Discovery\Support\Sources;

/**
 * Two rules that both recognise app/Billing/Providers/BillingServiceProvider.php.
 *
 * @return array<string, mixed>
 */
function overlappingDefinition(int $providerPriority = 0): array
{
    return [
        'roots' => ['app' => ['namespace' => 'App\\', 'path' => 'app']],
        'dimensions' => ['feature'],
        'kinds' => [
            'provider' => ['shape' => 'class', 'name' => ['suffix' => 'ServiceProvider'], 'root' => 'app', 'segments' => ['{feature}', 'Providers'], 'priority' => $providerPriority],
            'area' => ['shape' => 'class', 'name' => 'as-given', 'root' => 'app', 'segments' => ['Billing', '{feature}']],
        ],
    ];
}

it('reports an ambiguous file with its candidates and does not register it', DiscoveryFixture::around(function (DiscoveryFixture $fx) {
    $fx->write('app/Billing/Providers/BillingServiceProvider.php', Sources::provider('App\\Billing\\Providers', 'BillingServiceProvider', 'fixture.billing'));
    $preset = $fx->preset(overlappingDefinition());
    $this->app->setBasePath($fx->path());

    $inventory = DiscoveryRegistrar::register($this->app, $preset)->inventory();
    $rejection = $inventory->rejection('app/Billing/Providers/BillingServiceProvider.php');

    expect($rejection?->reason)->toBe(RejectionReason::Ambiguous)
        ->and($rejection?->candidates)->toHaveCount(2)
        ->and($rejection?->candidates[0])->toStartWith('provider ')
        ->and($rejection?->candidates[1])->toStartWith('area ')
        ->and($inventory->classes(DiscoveryType::Provider))->toBe([])
        ->and($this->app->bound('fixture.billing'))->toBeFalse();
}));

it('registers the file once a declared priority settles ownership', DiscoveryFixture::around(function (DiscoveryFixture $fx) {
    $fx->write('app/Billing/Providers/BillingServiceProvider.php', Sources::provider('App\\Billing\\Providers', 'BillingServiceProvider', 'fixture.billing'));
    $preset = $fx->preset(overlappingDefinition(providerPriority: 10));
    $this->app->setBasePath($fx->path());

    $inventory = DiscoveryRegistrar::register($this->app, $preset)->inventory();

    expect($inventory->ofKind('provider')[0]->context)->toBe(['feature' => 'Billing'])
        ->and($inventory->rejections)->toBe([])
        ->and($this->app->bound('fixture.billing'))->toBeTrue();
}));

it('reports files under a callback-placed root as unsupported and never registers them', DiscoveryFixture::around(function (DiscoveryFixture $fx) {
    $fx->write('app/Providers/BillingServiceProvider.php', Sources::provider('App\\Providers', 'BillingServiceProvider', 'fixture.billing'))
        ->write('app/Legacy/OldServiceProvider.php', Sources::provider('App\\Legacy', 'OldServiceProvider', 'fixture.old'));

    $preset = $fx->preset([
        'roots' => [
            'app' => ['namespace' => 'App\\', 'path' => 'app'],
            'legacy' => ['namespace' => 'App\\Legacy\\', 'path' => 'app/Legacy'],
        ],
        'kinds' => [
            'provider' => ['shape' => 'class', 'name' => ['suffix' => 'ServiceProvider'], 'root' => 'app', 'segments' => ['Providers']],
            'legacy' => ['shape' => 'class', 'name' => 'as-given', 'root' => 'legacy', 'place' => fn (string $name): string => ''],
        ],
    ]);
    $this->app->setBasePath($fx->path());

    $inventory = DiscoveryRegistrar::register($this->app, $preset)->inventory();

    expect($inventory->rejection('app/Legacy/OldServiceProvider.php')?->reason)->toBe(RejectionReason::Unsupported)
        ->and($inventory->classes(DiscoveryType::Provider))->toBe([$fx->class('App\\Providers\\BillingServiceProvider')])
        ->and($this->app->bound('fixture.old'))->toBeFalse();
}));

it('rejects a file whose class name does not match where the autoloader finds it', DiscoveryFixture::around(function (DiscoveryFixture $fx) {
    $fx->write('app/Providers/BillingServiceProvider.php', Sources::plain('App\\Providers', 'SomethingElse'));
    $preset = $fx->layout('ordinary');
    $this->app->setBasePath($fx->path());

    $inventory = DiscoveryRegistrar::register($this->app, $preset)->inventory();

    expect($inventory->rejection('app/Providers/BillingServiceProvider.php')?->reason)->toBe(RejectionReason::Ineligible)
        ->and($inventory->rejection('app/Providers/BillingServiceProvider.php')?->detail)->toContain('autoloader does not find');
}));
