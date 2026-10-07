<?php

use Tey\Mod\Artifact\FileIdentity;
use Tey\Mod\Exceptions\MissingDimension;
use Tey\Mod\Placement\PlacementResolver;
use Tey\Mod\Relation\RelationResolver;
use Tey\Mod\Reverse\ReverseMapper;
use Tey\Mod\Reverse\ReverseOutcome;
use Tey\Mod\Tests\Fixtures\Layouts;

/*
 * Layout 5: app/Modules/<Module> with flat folders.
 */

it('places artifacts inside the module', function (string $kind, string $name, ?string $fqcn, string $path) {
    $artifact = place(Layouts::modules(), $kind, $name, 'Billing', ['timestamp' => MIGRATION_TIMESTAMP]);

    expect($artifact->fqcn())->toBe($fqcn)
        ->and($artifact->path())->toBe($path)
        ->and($artifact->context->toArray())->toBe(['module' => 'Billing']);
})->with([
    'model' => ['model', 'Invoice', 'App\Modules\Billing\Models\Invoice', 'app/Modules/Billing/Models/Invoice.php'],
    'controller (flat, no Http)' => ['controller', 'Invoice', 'App\Modules\Billing\Controllers\InvoiceController', 'app/Modules/Billing/Controllers/InvoiceController.php'],
    'request' => ['request', 'StoreInvoice', 'App\Modules\Billing\Requests\StoreInvoiceRequest', 'app/Modules/Billing/Requests/StoreInvoiceRequest.php'],
    'policy' => ['policy', 'Invoice', 'App\Modules\Billing\Policies\InvoicePolicy', 'app/Modules/Billing/Policies/InvoicePolicy.php'],
    'provider' => ['provider', 'Billing', 'App\Modules\Billing\Providers\BillingServiceProvider', 'app/Modules/Billing/Providers/BillingServiceProvider.php'],
    'event' => ['event', 'InvoiceCreated', 'App\Modules\Billing\Events\InvoiceCreated', 'app/Modules/Billing/Events/InvoiceCreated.php'],
    'action (custom kind)' => ['action', 'CreateInvoice', 'App\Modules\Billing\Actions\CreateInvoice', 'app/Modules/Billing/Actions/CreateInvoice.php'],
    'query (custom kind)' => ['query', 'FindInvoice', 'App\Modules\Billing\Queries\FindInvoice', 'app/Modules/Billing/Queries/FindInvoice.php'],
    'factory' => ['factory', 'Invoice', 'App\Modules\Billing\Database\Factories\InvoiceFactory', 'app/Modules/Billing/Database/Factories/InvoiceFactory.php'],
    'seeder' => ['seeder', 'Invoice', 'App\Modules\Billing\Database\Seeders\InvoiceSeeder', 'app/Modules/Billing/Database/Seeders/InvoiceSeeder.php'],
    'migration' => ['migration', 'create_invoices_table', null, 'app/Modules/Billing/Database/Migrations/2026_01_01_000000_create_invoices_table.php'],
    'routes file' => ['routes', 'web', null, 'app/Modules/Billing/routes/web.php'],
]);

it('gives the routes file a file identity', function () {
    expect(place(Layouts::modules(), 'routes', 'web', 'Billing')->identity)->toBeInstanceOf(FileIdentity::class);
});

it('requires the module', function () {
    expect(fn () => place(Layouts::modules(), 'model', 'Invoice'))
        ->toThrow(MissingDimension::class, 'requires a [module]');
});

it('keeps relations inside the module', function () {
    $preset = Layouts::modules();
    $relations = new RelationResolver($preset, new PlacementResolver($preset));

    expect($relations->resolve(place($preset, 'model', 'Invoice', 'Billing'), 'factory')->target?->fqcn())
        ->toBe('App\Modules\Billing\Database\Factories\InvoiceFactory');

    $requests = array_map(
        fn ($resolution) => $resolution->target?->fqcn(),
        $relations->resolveAll(place($preset, 'controller', 'Invoice', 'Billing')),
    );

    expect($requests)->toBe(['App\Modules\Billing\Requests\StoreInvoiceRequest', 'App\Modules\Billing\Requests\UpdateInvoiceRequest']);
});

it('reverse-maps classes and paths', function (string $subject, ReverseOutcome $outcome, ?string $kind, ?string $module, ?string $reason) {
    $mapper = new ReverseMapper(Layouts::modules());
    $match = str_contains($subject, '\\') ? $mapper->fromClass($subject) : $mapper->fromPath($subject);

    expect($match->outcome)->toBe($outcome)
        ->and($match->artifact?->kind->id)->toBe($kind)
        ->and($match->artifact?->context->get('module'))->toBe($module);

    if ($reason !== null) {
        expect($match->reason)->toContain($reason);
    }
})->with([
    'model' => ['App\Modules\Billing\Models\Invoice', ReverseOutcome::Matched, 'model', 'Billing', null],
    'controller' => ['App\Modules\Billing\Controllers\InvoiceController', ReverseOutcome::Matched, 'controller', 'Billing', null],
    'factory' => ['App\Modules\Billing\Database\Factories\InvoiceFactory', ReverseOutcome::Matched, 'factory', 'Billing', null],
    'migration by path' => ['app/Modules/Billing/Database/Migrations/2026_01_01_000000_create_invoices_table.php', ReverseOutcome::Matched, 'migration', 'Billing', null],
    'routes by path' => ['app/Modules/Billing/routes/web.php', ReverseOutcome::Matched, 'routes', 'Billing', null],
    'UI sibling is not a module' => ['App\UI\ViewModels\ViewModel', ReverseOutcome::NotOwned, null, null, 'excluded root [App\UI\]'],
    'Support sibling is not a module' => ['App\Support\TypeScript\ViewModelTransformer', ReverseOutcome::NotOwned, null, null, 'excluded root [App\Support\]'],
    'a module literally named UI is a module' => ['App\Modules\UI\Data\Thing', ReverseOutcome::Matched, 'data', 'UI', null],
    'host default model' => ['App\Models\User', ReverseOutcome::NotOwned, null, null, 'no declared rule'],
    'host base controller' => ['App\Http\Controllers\Controller', ReverseOutcome::NotOwned, null, null, 'no declared rule'],
    'grouped view models (two levels) are not ruled' => ['App\Modules\Billing\ViewModels\Account\ShowViewModel', ReverseOutcome::NotOwned, null, null, 'no declared rule'],
]);
