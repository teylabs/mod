<?php

use Tey\Mod\Placement\CollisionDiagnoser;
use Tey\Mod\Placement\CollisionKind;
use Tey\Mod\Preset\Preset;
use Tey\Mod\Tests\Fixtures\Layouts;

it('diagnoses path and class collisions against what the caller says exists', function () {
    $model = place(Layouts::ordinary(), 'model', 'Invoice');
    $diagnoser = new CollisionDiagnoser;

    expect($diagnoser->check($model, []))->toBe([]);

    $collisions = $diagnoser->check($model, ['app/Models/Invoice.php', '\App\Models\Invoice', 'app/Models/Other.php', 'App\Models\Other']);

    expect(array_map(fn ($collision) => $collision->kind, $collisions))->toBe([CollisionKind::Path, CollisionKind::ClassName])
        ->and($collisions[0]->describe())->toContain('path collision: app/Models/Invoice.php already exists');
});

it('normalises path separators but not class case', function () {
    $model = place(Layouts::ordinary(), 'model', 'Invoice');
    $diagnoser = new CollisionDiagnoser;

    expect($diagnoser->check($model, ['./app\Models\Invoice.php']))->toHaveCount(1)
        ->and($diagnoser->check($model, ['App\Models\invoice']))->toBe([]);
});

it('finds no collision between duplicate basenames in different slices', function () {
    $create = place(Layouts::verticalSlices(), 'request', '', 'Billing/CreateInvoice');
    $cancel = place(Layouts::verticalSlices(), 'request', '', 'Billing/CancelInvoice');

    expect((new CollisionDiagnoser)->between($create, $cancel))->toBe([]);
});

it('finds the collision when two kinds share a folder and a name', function () {
    $definition = Layouts::definition('ordinary');
    $definition['kinds']['report'] = ['shape' => 'class', 'name' => 'as-given', 'root' => 'app', 'segments' => ['Queries'], 'priority' => 1];
    $preset = Preset::fromArray($definition);

    $query = place($preset, 'query', 'FindInvoice');
    $report = place($preset, 'report', 'FindInvoice');

    $collisions = (new CollisionDiagnoser)->between($query, $report);

    expect(array_map(fn ($collision) => $collision->kind, $collisions))->toBe([CollisionKind::Path, CollisionKind::ClassName]);
});
