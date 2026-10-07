<?php

use Illuminate\Contracts\Console\Kernel;
use Tey\Mod\Commands\FactoryCommand;
use Tey\Mod\Commands\ModelCommand;
use Tey\Mod\Tests\Feature\Acceptance\Support\AcceptanceApp;

/*
 * One logical request ("an Invoice model with its factory, in
 * Billing where the layout has a place for it") generated for real under
 * the five built-in layouts, selected by name. Five different identities
 * come out of the same command classes; only the layout data differs.
 */

it('generates the same logical artifact as five different identities through the same code', function () {
    $results = [];

    foreach (['laravel', 'features', 'slices', 'type-first', 'modules'] as $layout) {
        $results[$layout] = AcceptanceApp::run($layout, function (AcceptanceApp $app) {
            $name = "Invoice{$app->tag}";
            $in = $app->preset->dimensionNames() === [] ? [] : ['--in' => 'Billing'];
            $app->boot();

            $commands = $app->app()->make(Kernel::class)->all();
            $app->artisan('mod:model', ['name' => $name, '--factory' => true, ...$in])->assertSuccessful();

            // What the pure core says, before looking at the disk.
            $model = place($app->preset, 'model', $name, $in['--in'] ?? '');
            $factory = place($app->preset, 'factory', $name, $in['--in'] ?? '');

            $expected = [$model->path(), $factory->path()];
            sort($expected);

            expect($app->files())->toBe($expected)
                ->and($app->read($model->path()))
                ->toContain('namespace '.$model->class()?->namespace.';')
                ->toContain('class '.$model->class()?->basename.' extends Model')
                ->and($app->read($factory->path()))->toContain('namespace '.$factory->class()?->namespace.';');

            $app->assertOwned($model->path(), 'model', $model->context->toArray(), $model->fqcn());
            $app->assertOwned($factory->path(), 'factory', $factory->context->toArray(), $factory->fqcn());

            return [
                'model' => str_replace($app->tag, '', (string) $model->fqcn()),
                'model path' => str_replace($app->tag, '', $model->path()),
                'factory' => str_replace($app->tag, '', (string) $factory->fqcn()),
                'adapters' => [get_class($commands['mod:model']), get_class($commands['mod:factory'])],
            ];
        });
    }

    expect(array_column($results, 'model'))->toBe([
        'App\Models\Invoice',
        'App\Features\Billing\Models\Invoice',
        'App\Billing\Models\Invoice',
        'App\Models\Billing\Invoice',
        'App\Modules\Billing\Models\Invoice',
    ])
        ->and(array_column($results, 'factory'))->toBe([
            'Database\Factories\InvoiceFactory',
            'App\Features\Billing\Database\Factories\InvoiceFactory',
            'App\Billing\Database\Factories\InvoiceFactory',
            'Database\Factories\Billing\InvoiceFactory',
            'App\Modules\Billing\Database\Factories\InvoiceFactory',
        ])
        ->and(array_unique(array_column($results, 'model path')))->toHaveCount(5)
        // No per-layout code path: the very same adapter classes generated all five.
        ->and(array_values(array_unique(array_map(fn (array $result) => implode(',', $result['adapters']), $results))))
        ->toBe([implode(',', [ModelCommand::class, FactoryCommand::class])]);
});
