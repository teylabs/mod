<?php

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Log;
use Tey\Mod\Commands\ControllerCommand;
use Tey\Mod\Generation\GeneratorRegistry;
use Tey\Mod\Layout\CompiledLayout;
use Tey\Mod\Layout\LayoutRegistry;
use Tey\Mod\Layout\Root;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;

/*
 * Placement options named from placeholders: --in, plus one option per
 * dimension the kind reads (--module=, --feature= --slice=), equivalent to
 * the "Group:Name" shorthand, never shadowing a native option.
 */

/**
 * The dimension options a kind's command adds, in the layout's --in order.
 *
 * @return array<string, string> option => dimension
 */
function dimensionOptionsOf(CompiledLayout $preset, string $kindId): array
{
    $reads = $preset->rule($kindId)->dimensions();
    $options = [];

    foreach ($preset->placementOptions() as $dimension => $option) {
        if (in_array($dimension, $reads, true)) {
            $options[$option] = $dimension;
        }
    }

    return $options;
}

/**
 * A value for each dimension: a single folder, or two for a multi-segment one.
 *
 * @return array<string, string> dimension => value
 */
function dimensionValues(CompiledLayout $preset): array
{
    $values = [];

    foreach ($preset->dimensions() as $index => $dimension) {
        $values[$dimension->name] = ['Billing', 'CreateInvoice', 'Archive'][$index] ?? 'Extra';
    }

    return $values;
}

/**
 * Every built-in kind with a command whose rule reads at least one dimension.
 *
 * @return array<string, array{string, string}>
 */
function placedKinds(): array
{
    $cases = [];

    foreach (['features', 'slices', 'type-first', 'modules', 'ddd'] as $layout) {
        $preset = (new LayoutRegistry)->compile($layout);

        foreach ($preset->kinds() as $kind) {
            if ($kind->command !== null && $kind->id !== 'migration' && $preset->rule($kind->id)->dimensions() !== []) {
                $cases["{$layout} {$kind->id}"] = [$layout, $kind->id];
            }
        }
    }

    return $cases;
}

it('adds --in plus one option per dimension the kind reads', function (string $layout, string $command, array $expected) {
    Workspace::run(null, function () use ($layout, $command, $expected) {
        config()->set('mod.layout', $layout);
        $definition = Artisan::all()[$command]->getDefinition();

        foreach ($expected as $option => $description) {
            expect($definition->hasOption($option))->toBeTrue("{$command} --{$option}")
                ->and($definition->getOption($option)->isValueRequired())->toBeTrue()
                ->and($definition->getOption($option)->getDescription())->toBe($description);
        }

        foreach (['module', 'feature', 'slice'] as $absent) {
            if (! isset($expected[$absent])) {
                expect($definition->hasOption($absent))->toBeFalse("{$command} has no --{$absent}");
            }
        }
    });
})->with([
    'modules' => ['modules', 'mod:model', ['module' => 'Place in this module (same as --in)']],
    'features' => ['features', 'mod:model', ['feature' => 'Place in this feature (same as --in)']],
    'slices' => ['slices', 'mod:request', ['feature' => 'Place in this feature (same as --in)', 'slice' => 'Place in this slice (same as --in)']],
    'slices, feature only' => ['slices', 'mod:controller', ['feature' => 'Place in this feature (same as --in)']],
    'type-first' => ['type-first', 'mod:model', ['feature' => 'Place in this feature (same as --in)']],
    'laravel' => ['laravel', 'mod:model', []],
    'ddd' => ['ddd', 'mod:model', ['domain' => 'Place in this domain, nested folders separated by "." or "/" (same as --in)']],
]);

it('generates through the dimension options exactly as through the shorthand', function (string $layout, string $kindId) {
    Workspace::run(null, function (Workspace $workspace) use ($layout, $kindId) {
        config()->set('mod.layout', $layout);

        if ($layout === 'ddd') {
            // Both runs then write the generated base, whatever other tests loaded.
            isolatedDomainNamespace();
        }

        $preset = (new LayoutRegistry)->compile($layout);
        $command = (string) $preset->kind($kindId)->command;
        $values = dimensionValues($preset);
        $options = dimensionOptionsOf($preset, $kindId);
        $placement = implode('/', array_map(static fn (string $dimension): string => $values[$dimension], array_values($options)));

        $shorthand = $workspace->artisan($command, ['name' => "{$placement}:Probe"])->assertSuccessful();
        $files = [];

        foreach ($workspace->files() as $path) {
            $files[$path] = $workspace->read($path);
            unlink($workspace->root->path($path));
        }

        expect($files)->not->toBeEmpty();

        $parameters = ['name' => 'Probe'];

        foreach ($options as $option => $dimension) {
            $parameters['--'.$option] = $values[$dimension];
        }

        $result = $workspace->artisan($command, $parameters);
        $primary = array_key_first($files);

        if (str_ends_with((string) $primary, '.php')) {
            expect($result)->toHaveGenerated((string) $primary);
        }

        expect($result->output)->toBe($shorthand->output)
            ->and($workspace->files())->toBe(array_keys($files));

        foreach ($files as $path => $bytes) {
            expect($workspace->read($path))->toBe($bytes);
        }
    });
})->with(placedKinds());

it('generates a migration through the dimension option exactly as through the shorthand', function () {
    Workspace::run(null, function (Workspace $workspace) {
        config()->set('mod.layout', 'modules');
        Date::setTestNow('2026-01-01 00:00:00');

        try {
            $shorthand = $workspace->artisan('mod:migration', ['name' => 'Billing:create_invoices_table'])->assertSuccessful();
            $files = $workspace->files();
            $bytes = array_map($workspace->read(...), $files);

            foreach ($files as $path) {
                unlink($workspace->root->path($path));
            }

            $result = $workspace->artisan('mod:migration', ['name' => 'create_invoices_table', '--module' => 'Billing'])->assertSuccessful();

            expect($result->output)->toBe($shorthand->output)
                ->and($workspace->files())->toBe($files)
                ->and(array_map($workspace->read(...), $files))->toBe($bytes);
        } finally {
            Date::setTestNow();
        }
    });
});

it('reads several dimension options in the layout order, like --in', function () {
    Workspace::run(null, function (Workspace $workspace) {
        config()->set('mod.layout', 'slices');

        expect($workspace->artisan('mod:request', ['name' => 'CreateInvoice', '--slice' => 'CreateInvoice', '--feature' => 'Billing']))
            ->toHaveGenerated('app/Billing/CreateInvoice/Request.php', 'App\\Billing\\CreateInvoice');
    });
});

it('accepts only one form of placement', function () {
    Workspace::run(null, function (Workspace $workspace) {
        config()->set('mod.layout', 'modules');

        $workspace->artisan('mod:model', ['name' => 'Invoice', '--in' => 'Billing', '--module' => 'Shipping'])
            ->expectsOutputToContain('The placement was given twice, as --in=Billing and as --module=Shipping. Use one of them.')
            ->assertFailed();

        $workspace->artisan('mod:model', ['name' => 'Billing:Invoice', '--module' => 'Shipping'])
            ->expectsOutputToContain('Placement was given twice: as the prefix [Billing:] of the name and as --module=Shipping. Use one of them.')
            ->assertFailed();

        expect($workspace->files())->toBe([]);
    });
});

it('needs the earlier dimension options before a later one', function () {
    Workspace::run(null, function (Workspace $workspace) {
        config()->set('mod.layout', 'slices');

        $workspace->artisan('mod:request', ['name' => 'CreateInvoice', '--slice' => 'CreateInvoice'])
            ->expectsOutputToContain("--slice needs --feature too: values are read in the layout's order (feature, then slice).")
            ->assertFailed();

        $workspace->artisan('mod:model', ['name' => 'Invoice', '--feature' => 'Billing/Invoicing'])
            ->expectsOutputToContain('contains an invalid value [Billing/Invoicing]')
            ->assertFailed();

        expect($workspace->files())->toBe([]);
    });
});

it('falls back as before when no placement is given', function () {
    Workspace::run(null, function (Workspace $workspace) {
        config()->set('mod.layout', 'type-first');

        expect($workspace->artisan('mod:model', ['name' => 'Invoice']))->toHaveGenerated('app/Models/Invoice.php', 'App\\Models')
            ->and($workspace->artisan('mod:model', ['name' => 'Payment', '--feature' => 'Billing']))->toHaveGenerated('app/Models/Billing/Payment.php', 'App\\Models\\Billing');
    });
});

it('accepts dots or slashes inside a multi-segment dimension option', function () {
    Workspace::run(null, function (Workspace $workspace) {
        config()->set('mod.layout', 'laravel');
        app(LayoutRegistry::class)->layout('laravel')
            ->root('app', 'App\\', 'app', fn (Root $root) => $root->kind('model', in: 'Models/{area+}'));

        expect(Artisan::all()['mod:model']->getDefinition()->getOption('area')->getDescription())
            ->toBe('Place in this area, nested folders separated by "." or "/" (same as --in)')
            ->and($workspace->artisan('mod:model', ['name' => 'Invoice', '--area' => 'Reporting.Internal']))
            ->toHaveGenerated('app/Models/Reporting/Internal/Invoice.php', 'App\\Models\\Reporting\\Internal')
            ->and($workspace->artisan('mod:model', ['name' => 'Payment', '--area' => 'Reporting/Internal']))
            ->toHaveGenerated('app/Models/Reporting/Internal/Payment.php', 'App\\Models\\Reporting\\Internal');
    });
});

it('uses a renamed placement option', function () {
    Workspace::run(null, function (Workspace $workspace) {
        config()->set('mod.layout', 'modules');
        app(LayoutRegistry::class)->layout('modules')->placementOption('area');

        $definition = Artisan::all()['mod:model']->getDefinition();

        expect($definition->hasOption('module'))->toBeFalse()
            ->and($definition->getOption('area')->getDescription())->toBe('Place in this module (same as --in)')
            ->and($workspace->artisan('mod:model', ['name' => 'Invoice', '--area' => 'Billing']))
            ->toHaveGenerated('app/Modules/Billing/Models/Invoice.php', 'App\\Modules\\Billing\\Models');

        $workspace->artisan('mod:model', ['name' => 'Payment'])
            ->expectsOutputToContain('mod:model needs a module. Pass --area=<module>, --in=<module>, or prefix the name: <module>:Payment.')
            ->assertFailed();
    });
});

it('never shadows a native option: a colliding dimension option is left out and reported', function () {
    Workspace::run(null, function (Workspace $workspace) {
        config()->set('mod.layout', 'laravel');
        app(LayoutRegistry::class)->layout('laravel')
            ->root('app', 'App\\', 'app', fn (Root $root) => $root
                ->kind('controller', in: 'Http/Controllers/{model}', suffix: 'Controller'));
        $log = Log::spy();

        $commands = Artisan::all();
        $adapter = $commands['mod:controller'];
        $native = $commands['make:controller'];

        if (! $adapter instanceof ControllerCommand) {
            throw new RuntimeException('mod:controller is not the controller adapter.');
        }

        expect($adapter->getDefinition()->getOption('model')->getDescription())->toBe($native->getDefinition()->getOption('model')->getDescription())
            ->and($adapter->getDefinition()->getOption('model')->getShortcut())->toBe($native->getDefinition()->getOption('model')->getShortcut())
            ->and(array_map(static fn ($issue): string => $issue->describe(), $adapter->placementOptionIssues()))
            ->toBe(["[placement-option-collision] mod:controller: placeholder {model} would add --model, which mod:controller already defines. It is left out; use --in or the \"Group:Name\" prefix, or rename it with ->placementOption('...', '{model}')."]);

        $log->shouldHaveReceived('warning')->withArgs(static fn (string $message): bool => str_contains($message, '[placement-option-collision] mod:controller'));

        expect($workspace->artisan('mod:controller', ['name' => 'Billing:Invoice']))
            ->toHaveGenerated('app/Http/Controllers/Billing/InvoiceController.php', 'App\\Http\\Controllers\\Billing');
    });
});

it('adds exactly the expected placement options to every native definition', function (string $layout) {
    Workspace::run(null, function () use ($layout) {
        config()->set('mod.layout', $layout);
        $preset = (new LayoutRegistry)->compile($layout);
        $commands = Artisan::all();
        $natives = [];

        foreach ($commands as $command) {
            $natives[$command::class] = $command;
        }

        $checked = 0;

        foreach ($preset->kinds() as $kind) {
            /** @var Command|null $adapter */
            $adapter = $kind->command !== null ? ($commands[$kind->command] ?? null) : null;
            $nativeClass = $adapter !== null ? array_search($adapter::class, GeneratorRegistry::NATIVE, true) : false;

            if ($adapter === null || $nativeClass === false || ! isset($natives[$nativeClass])) {
                continue;
            }

            $native = $natives[$nativeClass];
            $nativeOptions = array_keys($native->getDefinition()->getOptions());
            $adapterOptions = array_keys($adapter->getDefinition()->getOptions());

            expect(array_values(array_diff($adapterOptions, $nativeOptions)))->toBe(['in', ...array_keys(dimensionOptionsOf($preset, $kind->id))], "{$layout} {$kind->command}")
                ->and(array_values(array_diff($nativeOptions, $adapterOptions)))->toBe([]);

            foreach ($native->getDefinition()->getOptions() as $option => $definition) {
                $placed = $adapter->getDefinition()->getOption($option);
                expect([$placed->getShortcut(), $placed->getDefault(), $placed->isValueRequired(), $placed->getDescription()])
                    ->toBe([$definition->getShortcut(), $definition->getDefault(), $definition->isValueRequired(), $definition->getDescription()]);
            }

            $checked++;
        }

        expect($checked)->toBeGreaterThan(20);
    });
})->with(['laravel', 'features', 'slices', 'type-first', 'modules', 'ddd']);
