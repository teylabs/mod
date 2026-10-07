<?php

use Tey\Mod\Exceptions\InvalidArtifactName;
use Tey\Mod\Placement\PlacementResolver;
use Tey\Mod\Relation\RelationResolver;
use Tey\Mod\Reverse\ReverseMapper;
use Tey\Mod\Reverse\ReverseOutcome;
use Tey\Mod\Tests\Fixtures\Layouts;

/*
 * Layout 4: type-first with feature identity after the kind segments.
 */

it('places artifacts with and without a feature from one preset', function (string $kind, string $name, string $in, ?string $fqcn, string $path) {
    $artifact = place(Layouts::typeFirst(), $kind, $name, $in, ['timestamp' => MIGRATION_TIMESTAMP]);

    expect($artifact->fqcn())->toBe($fqcn)
        ->and($artifact->path())->toBe($path)
        ->and($artifact->context->get('feature'))->toBe($in === '' ? null : $in);
})->with([
    'model in feature' => ['model', 'Invoice', 'Billing', 'App\Models\Billing\Invoice', 'app/Models/Billing/Invoice.php'],
    'model without feature' => ['model', 'Invoice', '', 'App\Models\Invoice', 'app/Models/Invoice.php'],
    'controller' => ['controller', 'Invoice', 'Billing', 'App\Http\Controllers\Billing\InvoiceController', 'app/Http/Controllers/Billing/InvoiceController.php'],
    'request' => ['request', 'StoreInvoice', 'Billing', 'App\Http\Requests\Billing\StoreInvoiceRequest', 'app/Http/Requests/Billing/StoreInvoiceRequest.php'],
    'query' => ['query', 'FindInvoice', 'Billing', 'App\Queries\Billing\FindInvoice', 'app/Queries/Billing/FindInvoice.php'],
    'policy' => ['policy', 'Invoice', 'Billing', 'App\Policies\Billing\InvoicePolicy', 'app/Policies/Billing/InvoicePolicy.php'],
    'factory across roots' => ['factory', 'Invoice', 'Billing', 'Database\Factories\Billing\InvoiceFactory', 'database/factories/Billing/InvoiceFactory.php'],
    'factory without feature' => ['factory', 'Invoice', '', 'Database\Factories\InvoiceFactory', 'database/factories/InvoiceFactory.php'],
    'migration in feature' => ['migration', 'create_invoices_table', 'Billing', null, 'database/migrations/Billing/2026_01_01_000000_create_invoices_table.php'],
    'migration without feature' => ['migration', 'create_invoices_table', '', null, 'database/migrations/2026_01_01_000000_create_invoices_table.php'],
]);

it('rejects a nested name that would duplicate the feature', function () {
    expect(fn () => place(Layouts::typeFirst(), 'model', 'Billing\Invoice', 'Billing'))
        ->toThrow(InvalidArtifactName::class, '--in=<feature>');
});

it('carries the feature across roots in relations', function () {
    $preset = Layouts::typeFirst();
    $relations = new RelationResolver($preset, new PlacementResolver($preset));
    $model = place($preset, 'model', 'Invoice', 'Billing');

    expect($relations->resolve($model, 'factory')->target?->fqcn())->toBe('Database\Factories\Billing\InvoiceFactory')
        ->and($relations->resolve($model, 'policy')->target?->fqcn())->toBe('App\Policies\Billing\InvoicePolicy');

    $plain = place($preset, 'model', 'Invoice');

    expect($relations->resolve($plain, 'factory')->target?->fqcn())->toBe('Database\Factories\InvoiceFactory');
});

it('reverse-maps classes and paths', function (string $subject, ReverseOutcome $outcome, ?string $kind, ?string $feature, ?string $reason) {
    $mapper = new ReverseMapper(Layouts::typeFirst());
    $match = str_contains($subject, '\\') ? $mapper->fromClass($subject) : $mapper->fromPath($subject);

    expect($match->outcome)->toBe($outcome)
        ->and($match->artifact?->kind->id)->toBe($kind)
        ->and($match->artifact?->context->get('feature'))->toBe($feature);

    if ($reason !== null) {
        expect($match->reason)->toContain($reason);
    }
})->with([
    'model in feature' => ['App\Models\Billing\Invoice', ReverseOutcome::Matched, 'model', 'Billing', null],
    'model without feature' => ['App\Models\Invoice', ReverseOutcome::Matched, 'model', null, null],
    'request: feature is the fourth segment' => ['App\Http\Requests\Billing\StoreInvoiceRequest', ReverseOutcome::Matched, 'request', 'Billing', null],
    'factory in feature' => ['Database\Factories\Billing\InvoiceFactory', ReverseOutcome::Matched, 'factory', 'Billing', null],
    'migration in feature by path' => ['database/migrations/Billing/2026_01_01_000000_create_invoices_table.php', ReverseOutcome::Matched, 'migration', 'Billing', null],
    'migration without feature by path' => ['database/migrations/2026_01_01_000000_create_invoices_table.php', ReverseOutcome::Matched, 'migration', null, null],
    'first segment is never a feature' => ['App\Billing\Invoice', ReverseOutcome::NotOwned, null, null, 'no declared rule'],
    'model concerns are excluded' => ['App\Models\Concerns\HasUuid', ReverseOutcome::NotOwned, null, null, 'excluded root'],
    'two levels under Models' => ['App\Models\Billing\Reports\Summary', ReverseOutcome::NotOwned, null, null, 'no declared rule'],
]);
