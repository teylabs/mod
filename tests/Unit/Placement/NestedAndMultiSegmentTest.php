<?php

use Tey\Mod\Artifact\ArtifactRequest;
use Tey\Mod\Exceptions\InvalidArtifactName;
use Tey\Mod\Exceptions\InvalidLayout;
use Tey\Mod\Exceptions\InvalidPlacementOption;
use Tey\Mod\Layout\Layout;
use Tey\Mod\Layout\Root;
use Tey\Mod\Placement\PlacementContext;
use Tey\Mod\Placement\PlacementResolver;
use Tey\Mod\Placement\Segment;
use Tey\Mod\Preset\Preset;
use Tey\Mod\Relation\RelationResolver;
use Tey\Mod\Reverse\ReverseMapper;
use Tey\Mod\Reverse\ReverseOutcome;

/*
 * Nested names (`nested: true`) and multi-segment placeholders
 * (`{group+}`), on a layout with a nested group folder chain.
 */
function groupedLayout(): Preset
{
    return (new Layout('grouped'))
        ->root('app', 'App\\', 'app', fn (Root $r) => $r
            ->kind('model', in: '{group+}/Models', nested: true)
            ->kind('policy', in: '{group+}/Policies', suffix: 'Policy', nested: true)
            ->kind('request', in: '{group+}/Requests', suffix: 'Request')
            ->kind('base', in: '{group+}', nested: true, priority: -1)
            ->kind('report', in: 'Reports/{area?}', suffix: 'Report', nested: true))
        ->relation('policy', from: 'model', to: 'policy')
        ->relation('flat-request', from: 'model', to: 'request', name: ['prefix' => 'Store'], scope: ['nested' => 'drop'])
        ->compile();
}

it('parses the four placeholder forms', function () {
    expect(Segment::parse('{x}')->multi)->toBeFalse()
        ->and(Segment::parse('{x}')->required)->toBeTrue()
        ->and(Segment::parse('{x?}')->required)->toBeFalse()
        ->and(Segment::parse('{x+}')->multi)->toBeTrue()
        ->and(Segment::parse('{x+}')->required)->toBeTrue()
        ->and(Segment::parse('{x+?}')->multi)->toBeTrue()
        ->and(Segment::parse('{x+?}')->required)->toBeFalse()
        ->and(Segment::parse('{x+?}')->describe())->toBe('{x+?}');
});

it('places a nested name below the kind folder and keeps the folders as nested', function () {
    $preset = groupedLayout();
    $model = place($preset, 'model', 'Archived/Invoice', 'Billing');

    expect($model->fqcn())->toBe('App\Billing\Models\Archived\Invoice')
        ->and($model->path())->toBe('app/Billing/Models/Archived/Invoice.php')
        ->and($model->name)->toBe('Invoice')
        ->and($model->nested)->toBe(['Archived'])
        ->and($model->nestedName())->toBe('Archived/Invoice')
        ->and(place($preset, 'model', 'Archived\\Deep\\Invoice', 'Billing')->fqcn())->toBe('App\Billing\Models\Archived\Deep\Invoice');
});

it('still refuses nested names on kinds that do not accept them', function () {
    expect(fn () => place(groupedLayout(), 'request', 'Archived/StoreInvoice', 'Billing'))
        ->toThrow(InvalidArtifactName::class, '--in=<group>');
});

it('rejects a nested folder that is not a class segment', function () {
    expect(fn () => place(groupedLayout(), 'model', 'bad-folder/Invoice', 'Billing'))
        ->toThrow(InvalidArtifactName::class, 'folder name inside a nested artifact name');
});

it('places a multi-segment dimension as a chain of folders', function () {
    $preset = groupedLayout();
    $model = (new PlacementResolver($preset))->resolve(
        ArtifactRequest::for('model', 'Invoice', PlacementContext::of(['group' => 'Billing/Invoicing'])),
    );

    expect($model->fqcn())->toBe('App\Billing\Invoicing\Models\Invoice')
        ->and($model->path())->toBe('app/Billing/Invoicing/Models/Invoice.php')
        ->and($model->context->toArray())->toBe(['group' => 'Billing/Invoicing']);
});

it('reads a multi-segment value from --in with dots between its folders', function () {
    $preset = groupedLayout();

    expect($preset->dimensions()[0]->multi)->toBeTrue()
        ->and(PlacementContext::fromOption('Billing.Invoicing', $preset)->toArray())->toBe(['group' => 'Billing/Invoicing'])
        ->and(PlacementContext::fromOption('Billing', $preset)->toArray())->toBe(['group' => 'Billing'])
        ->and(fn () => PlacementContext::fromOption('Billing..Invoicing', $preset))->toThrow(InvalidPlacementOption::class)
        ->and(PlacementContext::fromOption('Billing.Invoicing/Sales', $preset)->toArray())->toBe(['group' => 'Billing/Invoicing', 'area' => 'Sales'])
        ->and(fn () => PlacementContext::fromOption('Billing/Sales/Extra', $preset))->toThrow(InvalidPlacementOption::class, 'too many values');
});

it('rejects a multi-segment value that is not a folder chain', function () {
    expect(fn () => (new PlacementResolver(groupedLayout()))->resolve(
        ArtifactRequest::for('model', 'Invoice', PlacementContext::of(['group' => 'Billing\\Invoicing'])),
    ))->toThrow(InvalidArtifactName::class, 'chain of folder names');
});

it('maps nested and multi-segment artifacts back exactly', function () {
    $mapper = new ReverseMapper(groupedLayout());

    $deep = $mapper->fromClass('App\Billing\Invoicing\Models\Archived\Invoice');
    expect($deep->outcome)->toBe(ReverseOutcome::Matched)
        ->and($deep->artifact?->kind->id)->toBe('model')
        ->and($deep->artifact?->context->toArray())->toBe(['group' => 'Billing/Invoicing'])
        ->and($deep->artifact?->nested)->toBe(['Archived'])
        ->and($deep->artifact?->name)->toBe('Invoice');

    $flat = $mapper->fromPath('app/Billing/Models/Invoice.php');
    expect($flat->artifact?->nested)->toBe([])
        ->and($flat->artifact?->context->toArray())->toBe(['group' => 'Billing']);

    // A non-nested kind never claims leftover folders; the catch-all binds its multi dimension minimally.
    $fallback = $mapper->fromClass('App\Billing\Requests\Archived\StoreInvoiceRequest');
    expect($fallback->artifact?->kind->id)->toBe('base')
        ->and($fallback->artifact?->context->toArray())->toBe(['group' => 'Billing'])
        ->and($fallback->artifact?->nested)->toBe(['Requests', 'Archived']);
});

it('lets priority settle the catch-all root kind against the typed kinds', function () {
    $mapper = new ReverseMapper(groupedLayout());

    expect($mapper->fromClass('App\Billing\Models\Invoice')->artifact?->kind->id)->toBe('model')
        ->and($mapper->fromClass('App\Billing\Helper')->artifact?->kind->id)->toBe('base')
        ->and($mapper->fromClass('App\Billing\Support\Helper')->artifact?->nested)->toBe(['Support']);
});

it('keeps nested folders across relations unless the scope drops them', function () {
    $preset = groupedLayout();
    $relations = new RelationResolver($preset, new PlacementResolver($preset));
    $model = place($preset, 'model', 'Archived/Invoice', 'Billing');

    expect($relations->resolve($model, 'policy')->target?->fqcn())->toBe('App\Billing\Policies\Archived\InvoicePolicy')
        ->and($relations->resolve($model, 'policy')->target?->nested)->toBe(['Archived'])
        ->and($relations->resolve($model, 'flat-request')->target?->fqcn())->toBe('App\Billing\Requests\StoreInvoiceRequest');
});

it('handles an optional dimension before nested folders by priority or ambiguity', function () {
    $mapper = new ReverseMapper(groupedLayout());

    // Reports/{area?} with nested: Reports/Sales/RevenueReport reads as area=Sales or as nested [Sales];
    // the lower-priority catch-all also binds it (group=Reports, nested [Sales]) but never wins.
    $match = $mapper->fromClass('App\Reports\Sales\RevenueReport');
    $readings = array_map(
        fn ($candidate) => [$candidate->kind->id, $candidate->context->toArray(), $candidate->nested],
        array_values(array_filter($match->candidates, fn ($candidate) => $candidate->kind->id === 'report')),
    );

    expect($match->outcome)->toBe(ReverseOutcome::Ambiguous)
        ->and($readings)->toEqualCanonicalizing([
            ['report', ['area' => 'Sales'], []],
            ['report', [], ['Sales']],
        ]);
});

it('refuses a dimension that is multi in one kind and single in another', function () {
    $layout = (new Layout('mixed'))
        ->root('app', 'App\\', 'app')
        ->kind('model', in: '{group+}/Models')
        ->kind('policy', in: '{group}/Policies');

    try {
        $layout->compile();
    } catch (InvalidLayout $exception) {
        expect($exception->getMessage())->toContain('multi-segment')->toContain('{group+}');

        return;
    }

    throw new RuntimeException('compiled');
});

it('reports a placeholder with a misplaced plus against its kind', function () {
    $layout = (new Layout('bad'))->root('app', 'App\\', 'app')->kind('model', in: '{gro+up}/Models');

    expect(fn () => $layout->compile())->toThrow(InvalidLayout::class, '{name+}');
});
