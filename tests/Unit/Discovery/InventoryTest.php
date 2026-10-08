<?php

use Tey\Mod\Discovery\DiscoveredArtifact;
use Tey\Mod\Discovery\DiscoveryType;
use Tey\Mod\Discovery\Inventory;
use Tey\Mod\Discovery\Rejection;
use Tey\Mod\Discovery\RejectionReason;

function sampleInventory(): Inventory
{
    return new Inventory(
        [
            new DiscoveredArtifact('provider', DiscoveryType::Provider, 'App\\Modules\\Billing\\Providers\\BillingServiceProvider', 'app/Modules/Billing/Providers/BillingServiceProvider.php', ['module' => 'Billing']),
            new DiscoveredArtifact('subscriber', DiscoveryType::Listener, 'App\\Listeners\\SendReceipt', 'app/Listeners/SendReceipt.php', [], [['event' => 'App\\Events\\InvoicePaid', 'method' => 'handle']]),
        ],
        [
            new Rejection('app/Support/Thing.php', RejectionReason::NotOwned, 'inside excluded root [App\\Support\\]'),
            new Rejection('app/X/Y.php', RejectionReason::Ambiguous, 'more than one rule', candidates: ['provider A', 'area B']),
            new Rejection('app/Console/Commands/Formatter.php', RejectionReason::Ineligible, 'does not extend', 'command', 'App\\Console\\Commands\\Formatter'),
        ],
    );
}

it('round-trips through its array form', function () {
    $inventory = sampleInventory();
    $copy = Inventory::fromArray($inventory->toArray());

    expect($copy->equals($inventory))->toBeTrue()
        ->and($copy->toArray())->toBe($inventory->toArray())
        ->and(var_export($copy->toArray(), true))->toBe(var_export($inventory->toArray(), true));
});

it('filters by type, kind and rejection reason', function () {
    $inventory = sampleInventory();

    expect($inventory->classes(DiscoveryType::Listener))->toBe(['App\\Listeners\\SendReceipt'])
        ->and($inventory->ofKind('provider')[0]->context)->toBe(['module' => 'Billing'])
        ->and($inventory->ofType(DiscoveryType::Command))->toBe([])
        ->and($inventory->rejectedFor(RejectionReason::Ambiguous)[0]->candidates)->toBe(['provider A', 'area B'])
        ->and($inventory->rejection('app/Support/Thing.php')?->reason)->toBe(RejectionReason::NotOwned)
        ->and($inventory->rejection('app/Nope.php'))->toBeNull()
        ->and((new Inventory)->isEmpty())->toBeTrue();
});

it('refuses malformed data', function (mixed $data) {
    expect(fn () => Inventory::fromArray($data))->toThrow(UnexpectedValueException::class);
})->with([
    'not an array' => ['inventory'],
    'entries not a list' => [['entries' => ['a' => []], 'rejections' => []]],
    'entry missing class' => [['entries' => [['kind' => 'p', 'type' => 'provider', 'path' => 'x', 'context' => [], 'events' => []]], 'rejections' => []]],
    'unknown type' => [['entries' => [['kind' => 'p', 'type' => 'job', 'class' => 'X', 'path' => 'x', 'context' => [], 'events' => []]], 'rejections' => []]],
    'bad context' => [['entries' => [['kind' => 'p', 'type' => 'provider', 'class' => 'X', 'path' => 'x', 'context' => [1 => 2], 'events' => []]], 'rejections' => []]],
    'bad event' => [['entries' => [['kind' => 'p', 'type' => 'listener', 'class' => 'X', 'path' => 'x', 'context' => [], 'events' => [['event' => 'E']]]], 'rejections' => []]],
    'unknown reason' => [['entries' => [], 'rejections' => [['path' => 'x', 'reason' => 'lost', 'detail' => '', 'kind' => null, 'class' => null, 'candidates' => []]]]],
    'rejection missing kind' => [['entries' => [], 'rejections' => [['path' => 'x', 'reason' => 'not-owned', 'detail' => '', 'class' => null, 'candidates' => []]]]],
]);

it('pairs a relation type with its target and keeps other entries in their shape', function () {
    $pair = new DiscoveredArtifact('model', DiscoveryType::Factory, 'App\\Models\\Post', 'app/Models/Post.php', target: 'Database\\Factories\\PostFactory');
    $policy = new DiscoveredArtifact('model', DiscoveryType::Policy, 'App\\Models\\Post', 'app/Models/Post.php', target: 'App\\Policies\\PostPolicy');
    $provider = new DiscoveredArtifact('provider', DiscoveryType::Provider, 'App\\Providers\\AppServiceProvider', 'app/Providers/AppServiceProvider.php');
    $inventory = new Inventory([$provider, $pair, $policy]);

    expect($inventory->pairs(DiscoveryType::Factory))->toBe(['App\\Models\\Post' => 'Database\\Factories\\PostFactory'])
        ->and($inventory->pairs(DiscoveryType::Policy))->toBe(['App\\Models\\Post' => 'App\\Policies\\PostPolicy'])
        ->and($inventory->pairs(DiscoveryType::Provider))->toBe([])
        ->and($provider->toArray())->not->toHaveKey('target')
        ->and($pair->toArray()['target'])->toBe('Database\\Factories\\PostFactory')
        ->and(Inventory::fromArray($inventory->toArray())->equals($inventory))->toBeTrue()
        ->and(fn () => DiscoveredArtifact::fromArray([...$pair->toArray(), 'target' => 7]))->toThrow(UnexpectedValueException::class);
});
