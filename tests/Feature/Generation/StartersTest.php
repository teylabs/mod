<?php

use Tey\Mod\Facades\Mod;
use Tey\Mod\Generation\Starters;
use Tey\Mod\Generation\Stub;
use Tey\Mod\Layout\CompiledLayout;
use Tey\Mod\Layout\Root;
use Tey\Mod\Reverse\ReverseMapper;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;

/*
 * Starter stubs and generated bases in every layout: a dto, view-model,
 * value-object or action kind gets its starter wherever it is declared, and
 * bases go in the bases folder (mod.bases_path, app/Support by default).
 * Package detection is faked; mod's own dev install has none of the packages.
 */

/**
 * The four starter kinds in each built-in, declared with ->kind() where the
 * layout lacks them, and the folder and namespace each one generates into.
 *
 * @return array<string, array{string, string}>
 */
function starterKinds(string $layout): array
{
    $in = match ($layout) {
        'laravel' => '',
        'features' => 'Features/Billing/',
        'slices' => 'Billing/',
        'type-first' => '',
        'modules' => 'Modules/Billing/',
        default => throw new LogicException("No starter folders for [{$layout}]."),
    };
    $suffix = $layout === 'type-first' ? '/Billing' : '';

    $kinds = [];

    foreach (['dto' => 'Data', 'view-model' => 'ViewModels', 'value-object' => 'ValueObjects', 'action' => 'Actions'] as $kind => $folder) {
        $path = "app/{$in}{$folder}{$suffix}";
        $kinds[$kind] = [$path, 'App\\'.str_replace('/', '\\', substr($path, 4))];
    }

    return $kinds;
}

it('gives every built-in layout the starters, adding kinds where the layout has none', function (string $layout) {
    Workspace::run(null, function (Workspace $workspace) use ($layout) {
        config()->set('mod.layout', $layout);
        [$bases, $basesNamespace] = isolatedBasesPath();
        starterPackages();

        if ($layout !== 'modules') {
            $placeholder = match ($layout) {
                'laravel' => '',
                'features' => 'Features/{feature}/',
                'slices' => '{feature}/',
                'type-first' => '',
                default => throw new LogicException("No placeholder for [{$layout}]."),
            };
            $after = $layout === 'type-first' ? '/{feature?}' : '';

            Mod::layout($layout)
                ->generates('dto', in: "{$placeholder}Data{$after}")
                ->generates('view-model', in: "{$placeholder}ViewModels{$after}")
                ->generates('value-object', in: "{$placeholder}ValueObjects{$after}", command: 'mod:value')
                ->generates('action', in: "{$placeholder}Actions{$after}");
        }

        $placement = $layout === 'laravel' ? [] : ['--in' => 'Billing'];
        $kinds = starterKinds($layout);

        $dto = $workspace->artisan('mod:dto', ['name' => 'InvoiceData', ...$placement]);
        $viewModel = $workspace->artisan('mod:view-model', ['name' => 'ShowInvoice', ...$placement]);
        $value = $workspace->artisan('mod:value', ['name' => 'Money', ...$placement]);
        $action = $workspace->artisan('mod:action', ['name' => 'PayInvoice', ...$placement]);

        expect($dto)->toHaveGenerated("{$kinds['dto'][0]}/InvoiceData.php", $kinds['dto'][1])
            ->and($dto->output)->toContain("Created base class {$basesNamespace}\\Data\\DataTransferObject [{$bases}/Data/DataTransferObject.php].")
            ->and($dto->output)->toContainCreated('DTO', "{$kinds['dto'][0]}/InvoiceData.php")
            ->and($workspace->read("{$kinds['dto'][0]}/InvoiceData.php"))->toContain("use {$basesNamespace}\\Data\\DataTransferObject;\n\nclass InvoiceData extends DataTransferObject\n")
            ->and($workspace->root->path("{$bases}/Data/DataTransferObject.php"))->toBeValidPhp()
            ->and($viewModel)->toHaveGenerated("{$kinds['view-model'][0]}/ShowInvoice.php", $kinds['view-model'][1])
            ->and($viewModel->output)->toContain("Created base class {$basesNamespace}\\ViewModels\\ViewModel [{$bases}/ViewModels/ViewModel.php].")
            ->and($viewModel->output)->toContain('View model [')
            ->and($workspace->read("{$kinds['view-model'][0]}/ShowInvoice.php"))->toContain('class ShowInvoice extends ViewModel')
            ->and($value)->toHaveGenerated("{$kinds['value-object'][0]}/Money.php", $kinds['value-object'][1])
            ->and($value->output)->toContain('Value object [')
            ->and($workspace->read("{$kinds['value-object'][0]}/Money.php"))->toContain("class Money\n{\n    public function __construct(")
            ->and($action)->toHaveGenerated("{$kinds['action'][0]}/PayInvoice.php", $kinds['action'][1])
            ->and($action->output)->toContain('Action [')
            ->and($workspace->read("{$kinds['action'][0]}/PayInvoice.php"))->toContain('public function handle(): void');

        // The bases are nobody's group or class.
        $mapper = new ReverseMapper(app(CompiledLayout::class));

        expect($mapper->fromPath("{$bases}/Data/DataTransferObject.php")->isMatched())->toBeFalse()
            ->and($mapper->fromPath("{$bases}/ViewModels/ViewModel.php")->isMatched())->toBeFalse();
    });
})->with(['laravel', 'features', 'slices', 'type-first', 'modules']);

it('keeps the bases out of the modules of a custom layout rooted in app/Modules', function () {
    Workspace::run(null, function (Workspace $workspace) {
        Mod::layout('custom')->mounts('modules', 'App\\Modules\\', 'app/Modules', fn (Root $root) => $root
            ->generates('dto', in: '{module}/Data'));
        config()->set('mod.layout', 'custom');
        [$bases, $basesNamespace] = isolatedBasesPath();
        starterPackages();

        $dto = $workspace->artisan('mod:dto', ['name' => 'Billing:InvoiceData']);

        expect($dto)->toHaveGenerated('app/Modules/Billing/Data/InvoiceData.php', 'App\\Modules\\Billing\\Data')
            ->and($dto->output)->toContain("Created base class {$basesNamespace}\\Data\\DataTransferObject [{$bases}/Data/DataTransferObject.php].")
            ->and($workspace->exists('app/Modules/Support'))->toBeFalse();
    });
});

it('places bases in app/UI when mod.bases_path says so', function () {
    Workspace::run(null, function (Workspace $workspace) {
        config()->set('mod.layout', 'modules');
        config()->set('mod.bases_path', 'app/UI');
        starterPackages();

        $result = $workspace->artisan('mod:view-model', ['name' => 'Billing:ShowInvoice']);

        expect($result->output)->toContain('Created base class App\\UI\\ViewModels\\ViewModel [app/UI/ViewModels/ViewModel.php].')
            ->and($workspace->read('app/UI/ViewModels/ViewModel.php'))->toContain('namespace App\\UI\\ViewModels;')
            ->toContain('abstract class ViewModel implements Arrayable, JsonSerializable')
            ->and($workspace->read('app/Modules/Billing/ViewModels/ShowInvoice.php'))
            ->toContain("use App\\UI\\ViewModels\\ViewModel;\n\nclass ShowInvoice extends ViewModel\n");

        // modules excludes App\UI\ already; a slices app would otherwise take UI for a feature.
        expect((new ReverseMapper(app(CompiledLayout::class)))->fromPath('app/UI/ViewModels/ViewModel.php')->isMatched())->toBeFalse();
    });
});

it('never takes a bases folder for a feature in slices', function () {
    Workspace::run(null, function () {
        config()->set('mod.layout', 'slices');
        config()->set('mod.bases_path', 'app/UI');
        Mod::layout('slices')->generates('view-model', in: '{feature}/ViewModels');

        $mapper = new ReverseMapper(app(CompiledLayout::class));

        expect($mapper->fromPath('app/UI/ViewModels/ViewModel.php')->isMatched())->toBeFalse()
            ->and($mapper->fromPath('app/Billing/ViewModels/ShowInvoice.php')->isMatched())->toBeTrue();
    });
});

it('leaves the rest of the bases folder to the layout', function () {
    Workspace::run(null, function () {
        config()->set('mod.layout', 'type-first');
        Mod::layout('type-first')->generates('dto', in: 'Data/{feature?}');

        $mapper = new ReverseMapper(app(CompiledLayout::class));

        // Only the folders bases go in are excluded, not app/Support itself.
        expect($mapper->fromPath('app/Support/Data/DataTransferObject.php')->isMatched())->toBeFalse()
            ->and($mapper->fromPath('app/Support/Money.php')->artifact?->kind->id)->toBe('class');
    });
});

it('refuses a bases folder outside app/ and every layout root', function () {
    Workspace::run(null, function (Workspace $workspace) {
        config()->set('mod.layout', 'modules');
        config()->set('mod.bases_path', 'lib/Bases');
        starterPackages();

        $result = $workspace->artisan('mod:dto', ['name' => 'Billing:InvoiceData']);

        expect($result->exitCode)->toBe(1)
            ->and($result->output)->toContain('Config [mod.bases_path] is [lib/Bases], which is outside app/ and every root of the layout')
            ->and($workspace->files())->toBe([]);
    });
});

it('uses the installed packages in any layout instead of generating bases', function () {
    Workspace::run(null, function (Workspace $workspace) {
        config()->set('mod.layout', 'modules');
        [$bases] = isolatedBasesPath();
        starterPackages('spatie/laravel-data', 'spatie/laravel-view-models', 'lorisleiva/laravel-actions');

        expect($workspace->artisan('mod:dto', ['name' => 'Billing:InvoiceData'])->output)->toContain('Using spatie/laravel-data (installed).')
            ->and($workspace->read('app/Modules/Billing/Data/InvoiceData.php'))->toContain('class InvoiceData extends Data')
            ->and($workspace->artisan('mod:view-model', ['name' => 'Billing:ShowInvoice'])->output)->toContain('Using spatie/laravel-view-models (installed).')
            ->and($workspace->artisan('mod:action', ['name' => 'Billing:PayInvoice'])->output)->toContain('Using lorisleiva/laravel-actions (installed).')
            ->and($workspace->read('app/Modules/Billing/Actions/PayInvoice.php'))->toContain('    use AsAction;')
            ->and($workspace->exists($bases))->toBeFalse();
    });
});

it('reads a configured base by kind id, in any layout', function () {
    Workspace::run(null, function (Workspace $workspace) {
        config()->set('mod.layout', 'modules');
        config()->set('mod.bases.dto', 'App\\Support\\Data');
        [$bases] = isolatedBasesPath();
        starterPackages('spatie/laravel-data');

        expect($workspace->artisan('mod:data', ['name' => 'Billing:InvoiceData'])->output)->toContain('Using the configured base App\\Support\\Data.')
            ->and($workspace->read('app/Modules/Billing/Data/InvoiceData.php'))->toContain("use App\\Support\\Data;\n\nclass InvoiceData extends Data\n")
            ->and($workspace->exists($bases))->toBeFalse();
    });
});

it('lets a layout stub, a package stub and a published stub replace a starter', function () {
    Workspace::run(null, function (Workspace $workspace) {
        config()->set('mod.layout', 'modules');
        [$bases] = isolatedBasesPath();
        starterPackages();
        $workspace->write('stubs/layout.stub', "<?php\n\nnamespace {{ namespace }};\n\nclass {{ class }}\n{\n    // the layout's\n}\n");
        $workspace->write('stubs/package.stub', "<?php\n\nnamespace {{ namespace }};\n\nclass {{ class }}\n{\n    // a package's\n}\n");
        Mod::layout('modules')->generates('view-model', stub: Stub::file($workspace->root->path('stubs/layout.stub')));

        $workspace->artisan('mod:view-model', ['name' => 'Billing:ShowInvoice'])->assertSuccessful();
        expect($workspace->read('app/Modules/Billing/ViewModels/ShowInvoice.php'))->toContain("// the layout's")
            ->and($workspace->exists("{$bases}/ViewModels"))->toBeFalse();

        Mod::stubs()->for('dto', Stub::file($workspace->root->path('stubs/package.stub')));
        $workspace->artisan('mod:dto', ['name' => 'Billing:InvoiceData'])->assertSuccessful();
        expect($workspace->read('app/Modules/Billing/Data/InvoiceData.php'))->toContain("// a package's");

        $workspace->write('stubs/mod.action.stub', "<?php\n\nnamespace {{ namespace }};\n\nclass {{ class }}\n{\n    // the application's\n}\n");
        $workspace->artisan('mod:action', ['name' => 'Billing:PayInvoice'])->assertSuccessful();
        expect($workspace->read('app/Modules/Billing/Actions/PayInvoice.php'))->toContain("// the application's");
    });
});

it('gives a kind of another id a starter through stub:', function () {
    Workspace::run(null, function (Workspace $workspace) {
        config()->set('mod.layout', 'modules');
        [, $basesNamespace] = isolatedBasesPath();
        starterPackages();
        Mod::layout('modules')->generates('payload', in: 'Modules/{module}/Payloads', stub: Starters::dto());

        $result = $workspace->artisan('mod:payload', ['name' => 'Billing:InvoicePayload']);

        expect($result->output)->toContainCreated('DTO', 'app/Modules/Billing/Payloads/InvoicePayload.php')
            ->and($workspace->read('app/Modules/Billing/Payloads/InvoicePayload.php'))->toContain("use {$basesNamespace}\\Data\\DataTransferObject;");
    });
});
