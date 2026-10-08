<?php

use Tey\Mod\Exceptions\DimensionNotApplicable;
use Tey\Mod\Exceptions\MissingDimension;
use Tey\Mod\Placement\PlacementResolver;
use Tey\Mod\Preset\Preset;
use Tey\Mod\Relation\RelationResolver;
use Tey\Mod\Reverse\ReverseMapper;
use Tey\Mod\Reverse\ReverseOutcome;
use Tey\Mod\Tests\Fixtures\Layouts;

/*
 * Layout 3: vertical slices by use case (app/Billing/CreateInvoice/...).
 */

it('places slice artifacts with fixed basenames', function (string $kind, string $in, string $fqcn, string $path) {
    $artifact = place(Layouts::verticalSlices(), $kind, '', $in);

    expect($artifact->fqcn())->toBe($fqcn)->and($artifact->path())->toBe($path);
})->with([
    'create command' => ['message', 'Billing/CreateInvoice', 'App\Billing\CreateInvoice\Command', 'app/Billing/CreateInvoice/Command.php'],
    'create handler' => ['handler', 'Billing/CreateInvoice', 'App\Billing\CreateInvoice\Handler', 'app/Billing/CreateInvoice/Handler.php'],
    'create request' => ['request', 'Billing/CreateInvoice', 'App\Billing\CreateInvoice\Request', 'app/Billing/CreateInvoice/Request.php'],
    'create validator' => ['validator', 'Billing/CreateInvoice', 'App\Billing\CreateInvoice\Validator', 'app/Billing/CreateInvoice/Validator.php'],
    'cancel command' => ['message', 'Billing/CancelInvoice', 'App\Billing\CancelInvoice\Command', 'app/Billing/CancelInvoice/Command.php'],
    'cancel handler' => ['handler', 'Billing/CancelInvoice', 'App\Billing\CancelInvoice\Handler', 'app/Billing/CancelInvoice/Handler.php'],
    'cancel request' => ['request', 'Billing/CancelInvoice', 'App\Billing\CancelInvoice\Request', 'app/Billing/CancelInvoice/Request.php'],
    'read query (custom kind)' => ['query', 'Billing/ReadInvoice', 'App\Billing\ReadInvoice\Query', 'app/Billing/ReadInvoice/Query.php'],
]);

it('places feature-scoped artifacts beside the slices', function (string $kind, string $name, ?string $fqcn, string $path) {
    $artifact = place(Layouts::verticalSlices(), $kind, $name, 'Billing', ['timestamp' => MIGRATION_TIMESTAMP]);

    expect($artifact->fqcn())->toBe($fqcn)
        ->and($artifact->path())->toBe($path)
        ->and($artifact->context->toArray())->toBe(['feature' => 'Billing']);
})->with([
    'shared model' => ['model', 'Invoice', 'App\Billing\Models\Invoice', 'app/Billing/Models/Invoice.php'],
    'shared factory' => ['factory', 'Invoice', 'App\Billing\Database\Factories\InvoiceFactory', 'app/Billing/Database/Factories/InvoiceFactory.php'],
    'shared policy' => ['policy', 'Invoice', 'App\Billing\Policies\InvoicePolicy', 'app/Billing/Policies/InvoicePolicy.php'],
    'migration' => ['migration', 'create_invoices_table', null, 'app/Billing/Database/Migrations/2026_01_01_000000_create_invoices_table.php'],
]);

it('places a genuine Artisan command in its own declared root', function () {
    $command = place(Layouts::verticalSlices(), 'command', 'PruneInvoices');

    expect($command->fqcn())->toBe('App\Console\Commands\PruneInvoices')
        ->and($command->path())->toBe('app/Console/Commands/PruneInvoices.php');
});

it('treats duplicate basenames in different slices as different identities', function () {
    $create = place(Layouts::verticalSlices(), 'request', '', 'Billing/CreateInvoice');
    $cancel = place(Layouts::verticalSlices(), 'request', '', 'Billing/CancelInvoice');

    expect($create->equals($cancel))->toBeFalse()
        ->and($create->class()?->basename)->toBe($cancel->class()?->basename);
});

it('rejects a slice on a feature-scoped kind and requires it on slice kinds', function () {
    expect(fn () => place(Layouts::verticalSlices(), 'model', 'Invoice', 'Billing/CreateInvoice'))
        ->toThrow(DimensionNotApplicable::class, 'does not use a [slice]');

    expect(fn () => place(Layouts::verticalSlices(), 'request', '', 'Billing'))
        ->toThrow(MissingDimension::class, 'needs a [slice]');
});

it('relates a slice request to the shared-scope model with an explicit name', function () {
    $preset = Layouts::verticalSlices();
    $relations = new RelationResolver($preset, new PlacementResolver($preset));
    $request = place($preset, 'request', '', 'Billing/CreateInvoice');

    $model = $relations->resolve($request, 'model', 'Invoice');

    expect($model->isResolved())->toBeTrue()
        ->and($model->target?->fqcn())->toBe('App\Billing\Models\Invoice')
        ->and($model->target?->context->toArray())->toBe(['feature' => 'Billing']);

    $unnamed = $relations->resolve($request, 'model');

    expect($unnamed->isResolved())->toBeFalse()
        ->and($unnamed->reason)->toContain('pass it explicitly');

    $handler = place($preset, 'handler', '', 'Billing/CreateInvoice');

    expect($relations->resolve($handler, 'request')->target?->fqcn())->toBe('App\Billing\CreateInvoice\Request');
});

it('reverse-maps classes and paths', function (string $subject, ReverseOutcome $outcome, ?string $kind, array $context, ?string $reason) {
    $mapper = new ReverseMapper(Layouts::verticalSlices());
    $match = str_contains($subject, '\\') ? $mapper->fromClass($subject) : $mapper->fromPath($subject);

    expect($match->outcome)->toBe($outcome)
        ->and($match->artifact?->kind->id)->toBe($kind)
        ->and($match->artifact?->context->toArray() ?? [])->toBe($context);

    if ($reason !== null) {
        expect($match->reason)->toContain($reason);
    }
})->with([
    'create request' => ['App\Billing\CreateInvoice\Request', ReverseOutcome::Matched, 'request', ['feature' => 'Billing', 'slice' => 'CreateInvoice'], null],
    'cancel request' => ['App\Billing\CancelInvoice\Request', ReverseOutcome::Matched, 'request', ['feature' => 'Billing', 'slice' => 'CancelInvoice'], null],
    'message named Command is not an Artisan command' => ['App\Billing\CreateInvoice\Command', ReverseOutcome::Matched, 'message', ['feature' => 'Billing', 'slice' => 'CreateInvoice'], null],
    'genuine Artisan command' => ['App\Console\Commands\PruneInvoices', ReverseOutcome::Matched, 'command', [], null],
    'shared model' => ['App\Billing\Models\Invoice', ReverseOutcome::Matched, 'model', ['feature' => 'Billing'], null],
    'query by path' => ['app/Billing/ReadInvoice/Query.php', ReverseOutcome::Matched, 'query', ['feature' => 'Billing', 'slice' => 'ReadInvoice'], null],
    'hand-written model named Request' => ['App\Billing\Models\Request', ReverseOutcome::Ambiguous, null, [], 'none has priority'],
    'http request is excluded, not a feature' => ['App\Http\Requests\Request', ReverseOutcome::NotOwned, null, [], 'excluded root [App\Http\]'],
    'provider is excluded' => ['App\Providers\AppServiceProvider', ReverseOutcome::NotOwned, null, [], 'excluded root'],
    'too shallow' => ['App\Billing\Invoice', ReverseOutcome::NotOwned, null, [], 'no declared rule'],
    'too deep' => ['App\Billing\CreateInvoice\Steps\Validate', ReverseOutcome::NotOwned, null, [], 'no declared rule'],
]);

it('lists both candidates when ambiguous', function () {
    $match = (new ReverseMapper(Layouts::verticalSlices()))->fromClass('App\Billing\Models\Request');

    expect(array_map(fn ($candidate) => $candidate->kind->id, $match->candidates))->toBe(['request', 'model']);
});

it('resolves the ambiguity with a declared priority', function () {
    $definition = Layouts::definition('vertical-slices');
    $definition['kinds']['model']['priority'] = 10;

    $match = (new ReverseMapper(Preset::fromArray($definition)))->fromClass('App\Billing\Models\Request');

    expect($match->outcome)->toBe(ReverseOutcome::Matched)
        ->and($match->artifact?->kind->id)->toBe('model')
        ->and($match->artifact?->name)->toBe('Request');
});

it('shows the trap an exclusion closes: without it the declared rule matches App\Http', function () {
    $definition = Layouts::definition('vertical-slices');
    $definition['excluded'] = [];

    $match = (new ReverseMapper(Preset::fromArray($definition)))->fromClass('App\Http\Requests\Request');

    expect($match->outcome)->toBe(ReverseOutcome::Matched)
        ->and($match->artifact?->context->toArray())->toBe(['feature' => 'Http', 'slice' => 'Requests']);
});
