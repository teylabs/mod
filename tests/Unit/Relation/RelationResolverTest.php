<?php

use Tey\Mod\Exceptions\UnknownRelation;
use Tey\Mod\Placement\PlacementContext;
use Tey\Mod\Placement\PlacementResolver;
use Tey\Mod\Preset\Preset;
use Tey\Mod\Relation\NameDerivation;
use Tey\Mod\Relation\RelationPolicy;
use Tey\Mod\Relation\RelationResolver;
use Tey\Mod\Relation\RelationStatus;
use Tey\Mod\Relation\ScopeMap;
use Tey\Mod\Tests\Fixtures\Layouts;

it('throws for an undeclared relation and reports an inapplicable one', function () {
    $preset = Layouts::ordinary();
    $relations = new RelationResolver($preset, new PlacementResolver($preset));
    $model = place($preset, 'model', 'Invoice');

    expect(fn () => $relations->resolve($model, 'seeder'))->toThrow(UnknownRelation::class, '[seeder]');

    $wrong = $relations->resolve($model, 'store-request');

    expect($wrong->status)->toBe(RelationStatus::Unresolved)
        ->and($wrong->target)->toBeNull()
        ->and($wrong->reason)->toContain('starts from [controller], not [model]');
});

it('reports a target that cannot be placed instead of guessing', function () {
    $preset = Layouts::featureFirst();
    $relations = new RelationResolver($preset, new PlacementResolver($preset));

    // A command has no feature; relating it to a feature kind cannot resolve.
    $definition = Layouts::definition('feature-first');
    $definition['relations']['owner'] = ['from' => 'command', 'to' => 'model', 'scope' => 'same', 'policy' => 'reference'];
    $preset = Preset::fromArray($definition);
    $relations = new RelationResolver($preset, new PlacementResolver($preset));

    $resolution = $relations->resolve(place($preset, 'command', 'PruneInvoices'), 'owner');

    expect($resolution->isResolved())->toBeFalse()
        ->and($resolution->reason)->toContain('requires a [feature]');
});

it('derives names from stems and honours explicit names', function () {
    $derive = NameDerivation::fromSource(prefix: 'Store');

    expect($derive->derive('Invoice', null))->toBe('StoreInvoice')
        ->and($derive->derive('Invoice', 'Custom'))->toBe('Custom')
        ->and(NameDerivation::explicit()->derive('Invoice', null))->toBeNull()
        ->and(NameDerivation::explicit()->derive('Invoice', 'Invoice'))->toBe('Invoice')
        ->and(NameDerivation::fromSource(stripSuffix: 'Handler')->derive('CreateInvoiceHandler', null))->toBe('CreateInvoice')
        ->and(NameDerivation::fromSource(stripSuffix: 'Handler')->derive('Handler', null))->toBeNull()
        ->and(NameDerivation::fromSource(stripSuffix: 'Handler')->derive('Invoice', null))->toBeNull();
});

it('maps scope explicitly', function () {
    $context = PlacementContext::of(['feature' => 'Billing', 'slice' => 'CreateInvoice']);

    expect(ScopeMap::same()->apply($context)->toArray())->toBe(['feature' => 'Billing', 'slice' => 'CreateInvoice'])
        ->and(ScopeMap::keep(['feature'])->apply($context)->toArray())->toBe(['feature' => 'Billing'])
        ->and(ScopeMap::keep([])->apply($context)->isEmpty())->toBeTrue();
});

it('exposes the declared policy on every resolution', function () {
    $preset = Layouts::ordinary();
    $relations = new RelationResolver($preset, new PlacementResolver($preset));
    $model = place($preset, 'model', 'Invoice');

    $policies = array_map(fn ($resolution) => [$resolution->relation->id, $resolution->policy()], $relations->resolveAll($model));

    expect($policies)->toBe([['factory', RelationPolicy::Generate], ['policy', RelationPolicy::Reference]]);
});

it('maps a missing target dimension from the source stem without replacing an explicit value', function () {
    $scope = ScopeMap::same(nameDimension: 'operation');
    expect($scope->apply(PlacementContext::of(['area' => 'Billing']), 'CreateInvoice')->toArray())
        ->toBe(['area' => 'Billing', 'operation' => 'CreateInvoice'])
        ->and($scope->apply(PlacementContext::of(['operation' => 'UpdateInvoice']), 'CreateInvoice')->toArray())
        ->toBe(['operation' => 'UpdateInvoice']);
});
