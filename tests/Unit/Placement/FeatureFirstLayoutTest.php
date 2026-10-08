<?php

use Tey\Mod\Exceptions\InvalidArtifactName;
use Tey\Mod\Exceptions\MissingDimension;
use Tey\Mod\Placement\PlacementResolver;
use Tey\Mod\Relation\RelationResolver;
use Tey\Mod\Reverse\ReverseMapper;
use Tey\Mod\Reverse\ReverseOutcome;
use Tey\Mod\Tests\Fixtures\Layouts;

/*
 * Layout 2: feature-first (app/Features/<Feature>/...).
 */

it('places artifacts inside the feature', function (string $kind, string $name, ?string $fqcn, string $path) {
    $artifact = place(Layouts::featureFirst(), $kind, $name, 'Billing', ['timestamp' => MIGRATION_TIMESTAMP]);

    expect($artifact->fqcn())->toBe($fqcn)
        ->and($artifact->path())->toBe($path)
        ->and($artifact->context->toArray())->toBe(['feature' => 'Billing']);
})->with([
    'model' => ['model', 'Invoice', 'App\Features\Billing\Models\Invoice', 'app/Features/Billing/Models/Invoice.php'],
    'controller' => ['controller', 'Invoice', 'App\Features\Billing\Http\Controllers\InvoiceController', 'app/Features/Billing/Http/Controllers/InvoiceController.php'],
    'request' => ['request', 'StoreInvoice', 'App\Features\Billing\Http\Requests\StoreInvoiceRequest', 'app/Features/Billing/Http/Requests/StoreInvoiceRequest.php'],
    'policy' => ['policy', 'Invoice', 'App\Features\Billing\Policies\InvoicePolicy', 'app/Features/Billing/Policies/InvoicePolicy.php'],
    'factory' => ['factory', 'Invoice', 'App\Features\Billing\Database\Factories\InvoiceFactory', 'app/Features/Billing/Database/Factories/InvoiceFactory.php'],
    'query' => ['query', 'FindInvoice', 'App\Features\Billing\Queries\FindInvoice', 'app/Features/Billing/Queries/FindInvoice.php'],
    'validator (custom kind)' => ['validator', 'Invoice', 'App\Features\Billing\Validation\InvoiceValidator', 'app/Features/Billing/Validation/InvoiceValidator.php'],
    'provider' => ['provider', 'Billing', 'App\Features\Billing\Providers\BillingServiceProvider', 'app/Features/Billing/Providers/BillingServiceProvider.php'],
    'migration' => ['migration', 'create_invoices_table', null, 'app/Features/Billing/Database/Migrations/2026_01_01_000000_create_invoices_table.php'],
]);

it('places shared infrastructure outside features with its own rule', function () {
    $command = place(Layouts::featureFirst(), 'command', 'PruneInvoices');

    expect($command->fqcn())->toBe('App\Console\Commands\PruneInvoices')
        ->and($command->context->isEmpty())->toBeTrue();
});

it('requires the feature for feature kinds', function () {
    expect(fn () => place(Layouts::featureFirst(), 'model', 'Invoice'))
        ->toThrow(MissingDimension::class, 'needs a [feature]');
});

it('rejects nested names and points at --in', function () {
    expect(fn () => place(Layouts::featureFirst(), 'model', 'Billing/Invoice'))
        ->toThrow(InvalidArtifactName::class, '--in=<feature>');
});

it('keeps relations inside the feature', function () {
    $preset = Layouts::featureFirst();
    $relations = new RelationResolver($preset, new PlacementResolver($preset));
    $model = place($preset, 'model', 'Invoice', 'Billing');

    expect($relations->resolve($model, 'factory')->target?->fqcn())->toBe('App\Features\Billing\Database\Factories\InvoiceFactory')
        ->and($relations->resolve($model, 'policy')->target?->fqcn())->toBe('App\Features\Billing\Policies\InvoicePolicy')
        ->and($relations->resolve(place($preset, 'controller', 'Invoice', 'Billing'), 'store-request')->target?->fqcn())
        ->toBe('App\Features\Billing\Http\Requests\StoreInvoiceRequest');
});

it('reverse-maps classes and paths', function (string $subject, ReverseOutcome $outcome, ?string $kind, ?string $feature, ?string $reason) {
    $mapper = new ReverseMapper(Layouts::featureFirst());
    $match = str_contains($subject, '\\') ? $mapper->fromClass($subject) : $mapper->fromPath($subject);

    expect($match->outcome)->toBe($outcome)
        ->and($match->artifact?->kind->id)->toBe($kind)
        ->and($match->artifact?->context->get('feature'))->toBe($feature);

    if ($reason !== null) {
        expect($match->reason)->toContain($reason);
    }
})->with([
    'model' => ['App\Features\Billing\Models\Invoice', ReverseOutcome::Matched, 'model', 'Billing', null],
    'factory' => ['App\Features\Billing\Database\Factories\InvoiceFactory', ReverseOutcome::Matched, 'factory', 'Billing', null],
    'validator' => ['App\Features\Billing\Validation\InvoiceValidator', ReverseOutcome::Matched, 'validator', 'Billing', null],
    'hand-written model by path' => ['app/Features/Billing/Models/Invoice.php', ReverseOutcome::Matched, 'model', 'Billing', null],
    'migration by path' => ['app/Features/Billing/Database/Migrations/2026_01_01_000000_create_invoices_table.php', ReverseOutcome::Matched, 'migration', 'Billing', null],
    'another feature' => ['App\Features\Shipping\Models\Parcel', ReverseOutcome::Matched, 'model', 'Shipping', null],
    'shared command (no feature)' => ['App\Console\Commands\PruneInvoices', ReverseOutcome::Matched, 'command', null, null],
    'excluded support root' => ['App\Support\Clock', ReverseOutcome::NotOwned, null, null, 'excluded root [App\Support\]'],
    'unruled folder in a feature' => ['App\Features\Billing\Services\Pricing', ReverseOutcome::NotOwned, null, null, 'no declared rule'],
    'ordinary model is not a feature' => ['App\Models\User', ReverseOutcome::NotOwned, null, null, 'no declared rule'],
]);
