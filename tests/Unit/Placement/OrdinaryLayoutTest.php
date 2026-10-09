<?php

use Tey\Mod\Artifact\ArtifactRequest;
use Tey\Mod\Artifact\FileIdentity;
use Tey\Mod\Exceptions\DimensionNotApplicable;
use Tey\Mod\Exceptions\InvalidName;
use Tey\Mod\Exceptions\InvalidPlacementOption;
use Tey\Mod\Exceptions\UnknownFileType;
use Tey\Mod\Placement\PlacementContext;
use Tey\Mod\Placement\PlacementResolver;
use Tey\Mod\Relation\RelationMode;
use Tey\Mod\Relation\RelationResolver;
use Tey\Mod\Reverse\ReverseMapper;
use Tey\Mod\Reverse\ReverseOutcome;
use Tey\Mod\Tests\Fixtures\Layouts;

/*
 * Layout 1: ordinary Laravel without a module.
 */

it('places artifacts', function (string $kind, string $name, ?string $fqcn, string $path) {
    $artifact = place(Layouts::ordinary(), $kind, $name, '', ['timestamp' => MIGRATION_TIMESTAMP]);

    expect($artifact->fqcn())->toBe($fqcn)
        ->and($artifact->path())->toBe($path)
        ->and($artifact->context->isEmpty())->toBeTrue();
})->with([
    'model' => ['model', 'Invoice', 'App\Models\Invoice', 'app/Models/Invoice.php'],
    'controller (suffix added)' => ['controller', 'Invoice', 'App\Http\Controllers\InvoiceController', 'app/Http/Controllers/InvoiceController.php'],
    'controller (suffix kept)' => ['controller', 'InvoiceController', 'App\Http\Controllers\InvoiceController', 'app/Http/Controllers/InvoiceController.php'],
    'request' => ['request', 'StoreInvoice', 'App\Http\Requests\StoreInvoiceRequest', 'app/Http/Requests/StoreInvoiceRequest.php'],
    'factory (other root)' => ['factory', 'Invoice', 'Database\Factories\InvoiceFactory', 'database/factories/InvoiceFactory.php'],
    'seeder' => ['seeder', 'Invoice', 'Database\Seeders\InvoiceSeeder', 'database/seeders/InvoiceSeeder.php'],
    'policy' => ['policy', 'Invoice', 'App\Policies\InvoicePolicy', 'app/Policies/InvoicePolicy.php'],
    'provider' => ['provider', 'Billing', 'App\Providers\BillingServiceProvider', 'app/Providers/BillingServiceProvider.php'],
    'command' => ['command', 'PruneInvoices', 'App\Console\Commands\PruneInvoices', 'app/Console/Commands/PruneInvoices.php'],
    'event' => ['event', 'InvoiceCreated', 'App\Events\InvoiceCreated', 'app/Events/InvoiceCreated.php'],
    'listener' => ['listener', 'SendInvoiceReceipt', 'App\Listeners\SendInvoiceReceipt', 'app/Listeners/SendInvoiceReceipt.php'],
    'query (custom kind, data only)' => ['query', 'FindInvoice', 'App\Queries\FindInvoice', 'app/Queries/FindInvoice.php'],
    'migration (no class)' => ['migration', 'create_invoices_table', null, 'database/migrations/2026_01_01_000000_create_invoices_table.php'],
]);

it('gives a migration a file identity and keeps the timestamp semantics', function () {
    $migration = place(Layouts::ordinary(), 'migration', 'create_invoices_table', '', ['timestamp' => MIGRATION_TIMESTAMP]);

    expect($migration->identity)->toBeInstanceOf(FileIdentity::class)
        ->and($migration->class())->toBeNull()
        ->and($migration->name)->toBe('create_invoices_table');

    expect(fn () => place(Layouts::ordinary(), 'migration', 'create_invoices_table'))
        ->toThrow(InvalidName::class, 'timestamp');
});

it('refuses a placement value because the layout has no dimensions (no fake empty module)', function () {
    expect(fn () => place(Layouts::ordinary(), 'request', 'StoreInvoice', 'Billing'))
        ->toThrow(InvalidPlacementOption::class, 'This layout takes no placement, so drop --in=Billing.');

    expect(fn () => (new PlacementResolver(Layouts::ordinary()))->resolve(
        ArtifactRequest::for('request', 'StoreInvoice', PlacementContext::of(['feature' => 'Billing'])),
    ))->toThrow(DimensionNotApplicable::class);
});

it('rejects an unknown kind', function () {
    expect(fn () => place(Layouts::ordinary(), 'repository', 'Invoice'))
        ->toThrow(UnknownFileType::class, '[repository]');
});

it('rejects nested names without a --in hint when there are no dimensions', function () {
    expect(fn () => place(Layouts::ordinary(), 'model', 'Billing/Invoice'))
        ->toThrow(InvalidName::class, 'Invalid name [Billing/Invoice]. Nested names are not supported.');
});

it('resolves relations across roots', function () {
    $preset = Layouts::ordinary();
    $relations = new RelationResolver($preset, new PlacementResolver($preset));

    $model = place($preset, 'model', 'Invoice');
    $factory = $relations->resolve($model, 'factory');

    expect($factory->isResolved())->toBeTrue()
        ->and($factory->target?->fqcn())->toBe('Database\Factories\InvoiceFactory')
        ->and($factory->target?->path())->toBe('database/factories/InvoiceFactory.php')
        ->and($factory->mode())->toBe(RelationMode::Generate);

    $back = $relations->resolve($factory->target, 'model');

    expect($back->target?->fqcn())->toBe('App\Models\Invoice')
        ->and($back->mode())->toBe(RelationMode::Reference);

    $controller = place($preset, 'controller', 'Invoice');
    $requests = array_map(
        fn ($resolution) => $resolution->target?->fqcn(),
        $relations->resolveAll($controller),
    );

    expect($requests)->toBe(['App\Http\Requests\StoreInvoiceRequest', 'App\Http\Requests\UpdateInvoiceRequest']);
});

it('reverse-maps classes and paths', function (string $subject, ReverseOutcome $outcome, ?string $kind, ?string $reason) {
    $mapper = new ReverseMapper(Layouts::ordinary());
    $match = str_contains($subject, '\\') ? $mapper->fromClass($subject) : $mapper->fromPath($subject);

    expect($match->outcome)->toBe($outcome)
        ->and($match->artifact?->kind->id)->toBe($kind)
        ->and($match->artifact?->context->isEmpty())->toBe($kind === null ? null : true);

    if ($reason !== null) {
        expect($match->reason)->toContain($reason);
    }
})->with([
    'model' => ['App\Models\Invoice', ReverseOutcome::Matched, 'model', null],
    'model with leading separator' => ['\App\Models\Invoice', ReverseOutcome::Matched, 'model', null],
    'factory in another root' => ['Database\Factories\InvoiceFactory', ReverseOutcome::Matched, 'factory', null],
    'custom query' => ['App\Queries\FindInvoice', ReverseOutcome::Matched, 'query', null],
    'controller by path' => ['app/Http/Controllers/InvoiceController.php', ReverseOutcome::Matched, 'controller', null],
    'migration by path' => ['database/migrations/2026_01_01_000000_create_invoices_table.php', ReverseOutcome::Matched, 'migration', null],
    'base controller (empty stem)' => ['App\Http\Controllers\Controller', ReverseOutcome::NotOwned, null, 'no declared rule'],
    'model concern (extra segment)' => ['App\Models\Concerns\HasUuid', ReverseOutcome::NotOwned, null, 'no declared rule'],
    'support class' => ['App\Support\Money', ReverseOutcome::NotOwned, null, 'no declared rule'],
    'vendor class' => ['Illuminate\Foundation\Http\FormRequest', ReverseOutcome::NotOwned, null, 'no declared rule'],
    'non-php file' => ['app/Models/notes.txt', ReverseOutcome::NotOwned, null, null],
]);

it('reverse-maps a migration to its name and file identity', function () {
    $match = (new ReverseMapper(Layouts::ordinary()))->fromPath('database/migrations/2026_01_01_000000_create_invoices_table.php');

    expect($match->artifact?->name)->toBe('create_invoices_table')
        ->and($match->artifact?->identity)->toBeInstanceOf(FileIdentity::class);
});
