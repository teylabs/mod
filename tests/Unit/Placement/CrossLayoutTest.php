<?php

use Tey\Mod\Artifact\ArtifactRequest;
use Tey\Mod\Placement\PlacementContext;
use Tey\Mod\Placement\PlacementResolver;
use Tey\Mod\Reverse\ReverseMapper;
use Tey\Mod\Tests\Fixtures\Layouts;

/*
 * The engine is data-driven: the same request yields five identities from five
 * presets, every forward placement reverse-maps to itself, and presets coexist.
 */

it('places the same request differently under each layout with no engine branches', function () {
    $fqcns = [];

    foreach (Layouts::NAMES as $name) {
        $preset = Layouts::named($name);
        $in = $preset->dimensionNames() === [] ? '' : 'Billing';
        $fqcns[$name] = place($preset, 'model', 'Invoice', $in)->fqcn();
    }

    expect($fqcns)->toBe([
        'ordinary' => 'App\Models\Invoice',
        'feature-first' => 'App\Features\Billing\Models\Invoice',
        'vertical-slices' => 'App\Billing\Models\Invoice',
        'type-first' => 'App\Models\Billing\Invoice',
        'modules' => 'App\Modules\Billing\Models\Invoice',
    ]);
});

it('round-trips every class kind through placement and reverse mapping', function (string $layout) {
    $preset = Layouts::named($layout);
    $resolver = new PlacementResolver($preset);
    $mapper = new ReverseMapper($preset);
    $dimensions = $preset->dimensionNames();

    foreach ($preset->kinds() as $kind) {
        $context = PlacementContext::none();

        foreach ($preset->rule($kind->id)->dimensions() as $index => $dimension) {
            $context = $context->with($dimension, ['Billing', 'CreateInvoice'][array_search($dimension, $dimensions, true)]);
        }

        $name = $kind->isClass() ? 'Invoice' : ($kind->id === 'routes' ? 'web' : 'create_invoices_table');
        $forward = $resolver->resolve(ArtifactRequest::for($kind->id, $name, $context, ['timestamp' => MIGRATION_TIMESTAMP]));

        $byPath = $mapper->fromPath($forward->path());
        expect($byPath->isMatched())->toBeTrue("{$layout}: {$kind->id} by path {$forward->path()} -> {$byPath->outcome->value}")
            ->and($byPath->artifact?->equals($forward))->toBeTrue("{$layout}: {$kind->id} by path");

        if ($forward->fqcn() !== null) {
            $byClass = $mapper->fromClass($forward->fqcn());
            expect($byClass->isMatched())->toBeTrue("{$layout}: {$kind->id} by class {$forward->fqcn()} -> {$byClass->outcome->value}")
                ->and($byClass->artifact?->equals($forward))->toBeTrue("{$layout}: {$kind->id} by class");
        }
    }
})->with(Layouts::NAMES);

it('holds two presets side by side without shared state', function () {
    $ordinary = Layouts::ordinary();
    $modules = Layouts::modules();

    $a = place($ordinary, 'model', 'Invoice');
    $b = place($modules, 'model', 'Invoice', 'Billing');
    $c = place($ordinary, 'model', 'Invoice');

    expect($a->fqcn())->toBe('App\Models\Invoice')
        ->and($b->fqcn())->toBe('App\Modules\Billing\Models\Invoice')
        ->and($c->equals($a))->toBeTrue()
        ->and((new ReverseMapper($ordinary))->fromClass($b->fqcn())->isMatched())->toBeFalse()
        ->and((new ReverseMapper($modules))->fromClass($a->fqcn())->isMatched())->toBeFalse();
});
