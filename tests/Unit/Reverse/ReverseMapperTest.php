<?php

use Tey\Mod\Placement\PlacementContext;
use Tey\Mod\Preset\Preset;
use Tey\Mod\Reverse\ReverseMapper;
use Tey\Mod\Reverse\ReverseMatch;
use Tey\Mod\Reverse\ReverseOutcome;
use Tey\Mod\Tests\Fixtures\Layouts;

function presetWithOpaqueKind(string $root = 'app'): Preset
{
    $definition = Layouts::definition('ordinary');
    $definition['roots']['reports'] = ['namespace' => 'App\\Reports\\', 'path' => 'app/Reports'];
    $definition['kinds']['report'] = [
        'shape' => 'class',
        'name' => ['suffix' => 'Report'],
        'root' => $root === 'app' ? 'app' : 'reports',
        'place' => fn (string $name, PlacementContext $context): string => 'Reports\\'.strtoupper(substr($name, 0, 1)),
    ];

    return Preset::fromArray($definition);
}

it('places with an opaque rule but reports Unsupported on the way back', function () {
    $preset = presetWithOpaqueKind('reports');
    $report = place($preset, 'report', 'Revenue');

    expect($report->fqcn())->toBe('App\Reports\Reports\R\RevenueReport')
        ->and($report->path())->toBe('app/Reports/Reports/R/RevenueReport.php');

    $match = (new ReverseMapper($preset))->fromClass('App\Reports\Reports\R\RevenueReport');

    expect($match->outcome)->toBe(ReverseOutcome::Unsupported)
        ->and($match->reason)->toContain('file type [report] is placed by a callback');
});

it('reports Unsupported for everything under an opaque rule root, even what another rule recognises', function () {
    $mapper = new ReverseMapper(presetWithOpaqueKind('app'));

    expect($mapper->fromClass('App\Models\Invoice')->outcome)->toBe(ReverseOutcome::Unsupported)
        ->and($mapper->fromClass('Database\Factories\InvoiceFactory')->outcome)->toBe(ReverseOutcome::Matched);
});

it('narrows the opaque rule to its own root so the rest of the layout stays invertible', function () {
    $mapper = new ReverseMapper(presetWithOpaqueKind('reports'));

    expect($mapper->fromClass('App\Models\Invoice')->outcome)->toBe(ReverseOutcome::Matched)
        ->and($mapper->fromPath('app/Reports/Anything.php')->outcome)->toBe(ReverseOutcome::Unsupported);
});

it('exposes the four outcomes explicitly', function () {
    $artifact = place(Layouts::ordinary(), 'model', 'Invoice');

    expect(ReverseMatch::matched($artifact)->isMatched())->toBeTrue()
        ->and(ReverseMatch::matched($artifact)->candidates)->toBe([$artifact])
        ->and(ReverseMatch::notOwned('because')->reason)->toBe('because')
        ->and(ReverseMatch::ambiguous([$artifact, $artifact])->outcome)->toBe(ReverseOutcome::Ambiguous)
        ->and(ReverseMatch::unsupported('callback')->artifact)->toBeNull();
});

it('never maps a migration from a class name', function () {
    expect((new ReverseMapper(Layouts::ordinary()))->fromClass('CreateInvoicesTable')->outcome)
        ->toBe(ReverseOutcome::NotOwned);
});
