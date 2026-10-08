<?php

use Illuminate\Support\Facades\Artisan;
use Tey\Mod\Commands\GenericClassCommand;
use Tey\Mod\Facades\Mod;
use Tey\Mod\Generation\PackageDetector;
use Tey\Mod\Generation\Stub;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;

/*
 * Stub variants and generated bases, through the ddd layout's DTO, view
 * model and action. Package detection is faked: mod's own dev install has
 * none of the optional packages, and installing them is not needed to show
 * which stub and base mod picks.
 */

/**
 * Pretend these Composer packages are installed.
 */
function installedPackages(string ...$packages): void
{
    app()->instance(PackageDetector::class, new class($packages) implements PackageDetector
    {
        /**
         * @param  list<string>  $packages
         */
        public function __construct(private array $packages) {}

        public function isInstalled(string $package): bool
        {
            return in_array($package, $this->packages, true);
        }

        public function classExists(string $class): bool
        {
            return class_exists($class);
        }
    });
}

it('writes the DataTransferObject base on first use, says so, and extends it', function () {
    Workspace::run(null, function (Workspace $workspace) {
        config()->set('mod.layout', 'ddd');
        $ns = isolatedDomainNamespace();
        installedPackages();

        $first = $workspace->artisan('mod:dto', ['name' => 'Billing:InvoiceData']);

        expect($first)->toHaveGenerated('src/Domain/Billing/Data/InvoiceData.php', "{$ns}\\Billing\\Data")
            ->and($first->output)->toContain("Created base class {$ns}\\Shared\\Data\\DataTransferObject [src/Domain/Shared/Data/DataTransferObject.php].")
            ->and(strpos($first->output, 'Created base class'))->toBeLessThan(strpos($first->output, 'InvoiceData.php'))
            ->and($workspace->read('src/Domain/Billing/Data/InvoiceData.php'))
            ->toContain("use {$ns}\\Shared\\Data\\DataTransferObject;\n\nclass InvoiceData extends DataTransferObject\n")
            ->and($workspace->root->path('src/Domain/Shared/Data/DataTransferObject.php'))->toBeValidPhp();

        $second = $workspace->artisan('mod:dto', ['name' => 'Billing:LineData']);

        expect($second)->toHaveGenerated('src/Domain/Billing/Data/LineData.php')
            ->and($second->output)->not->toContain('Created base class');
    });
});

it('never overwrites a base, not even with --force', function () {
    Workspace::run(null, function (Workspace $workspace) {
        config()->set('mod.layout', 'ddd');
        isolatedDomainNamespace();
        installedPackages();

        $workspace->artisan('mod:dto', ['name' => 'Billing:InvoiceData'])->assertSuccessful();
        $base = 'src/Domain/Shared/Data/DataTransferObject.php';
        $workspace->write($base, $workspace->read($base)."\n// edited by the application\n");

        $workspace->artisan('mod:dto', ['name' => 'Billing:InvoiceData', '--force' => true])->assertSuccessful();

        expect($workspace->read($base))->toContain('// edited by the application');
    });
});

it('writes the ViewModel base from the shipped body', function () {
    Workspace::run(null, function (Workspace $workspace) {
        config()->set('mod.layout', 'ddd');
        $ns = isolatedDomainNamespace();
        installedPackages();

        $result = $workspace->artisan('mod:view-model', ['name' => 'Billing:ShowInvoice']);
        $base = $workspace->read('src/Domain/Shared/ViewModels/ViewModel.php');

        expect($result)->toHaveGenerated('src/Domain/Billing/ViewModels/ShowInvoice.php')
            ->and($result->output)->toContain("Created base class {$ns}\\Shared\\ViewModels\\ViewModel [src/Domain/Shared/ViewModels/ViewModel.php].")
            ->and($base)->toContain("namespace {$ns}\\Shared\\ViewModels;")
            ->toContain('abstract class ViewModel implements Arrayable, JsonSerializable')
            ->toContain('public static function make(...$args)')
            ->toContain("'__construct', 'make', 'toArray', 'jsonSerialize',")
            ->and($workspace->read('src/Domain/Billing/ViewModels/ShowInvoice.php'))->toContain('class ShowInvoice extends ViewModel');
    });
});

it('uses an installed package instead of a generated base', function () {
    Workspace::run(null, function (Workspace $workspace) {
        config()->set('mod.layout', 'ddd');
        isolatedDomainNamespace();
        installedPackages('spatie/laravel-data', 'spatie/laravel-view-models', 'lorisleiva/laravel-actions');

        $dto = $workspace->artisan('mod:dto', ['name' => 'Billing:InvoiceData']);
        $viewModel = $workspace->artisan('mod:view-model', ['name' => 'Billing:ShowInvoice']);
        $action = $workspace->artisan('mod:action', ['name' => 'Billing:PayInvoice']);

        expect($dto)->toHaveGenerated('src/Domain/Billing/Data/InvoiceData.php')
            ->and($dto->output)->toContain('Using spatie/laravel-data (installed).')
            ->and($dto->output)->not->toContain('Created base class')
            ->and($workspace->read('src/Domain/Billing/Data/InvoiceData.php'))->toContain("use Spatie\\LaravelData\\Data;\n\nclass InvoiceData extends Data\n")
            ->and($viewModel->output)->toContain('Using spatie/laravel-view-models (installed).')
            ->and($workspace->read('src/Domain/Billing/ViewModels/ShowInvoice.php'))->toContain("use Spatie\\ViewModels\\ViewModel;\n\nclass ShowInvoice extends ViewModel\n")
            ->and($action->output)->toContain('Using lorisleiva/laravel-actions (installed).')
            ->and($workspace->read('src/Domain/Billing/Actions/PayInvoice.php'))->toContain('use Lorisleiva\\Actions\\Concerns\\AsAction;')->toContain('    use AsAction;')
            ->and($workspace->exists('src/Domain/Shared/Data/DataTransferObject.php'))->toBeFalse()
            ->and($workspace->exists('src/Domain/Shared/ViewModels/ViewModel.php'))->toBeFalse();
    });
});

it('extends a configured base before anything detected or generated', function () {
    Workspace::run(null, function (Workspace $workspace) {
        config()->set('mod.layout', 'ddd');
        isolatedDomainNamespace();
        config()->set('mod.layouts.ddd.bases.dto', 'App\\Support\\Data');
        config()->set('mod.layouts.ddd.bases.action', 'App\\Support\\Action');
        installedPackages('spatie/laravel-data');

        $dto = $workspace->artisan('mod:dto', ['name' => 'Billing:InvoiceData']);
        $action = $workspace->artisan('mod:action', ['name' => 'Billing:PayInvoice']);

        expect($dto->output)->toContain('Using the configured base App\\Support\\Data.')
            ->and($workspace->read('src/Domain/Billing/Data/InvoiceData.php'))->toContain("use App\\Support\\Data;\n\nclass InvoiceData extends Data\n")
            ->and($action->output)->toContain('Using the configured base App\\Support\\Action.')
            ->and($workspace->read('src/Domain/Billing/Actions/PayInvoice.php'))->toContain("use App\\Support\\Action;\n\nclass PayInvoice extends Action\n")
            ->and($workspace->exists('src/Domain/Shared/Data/DataTransferObject.php'))->toBeFalse();
    });
});

it('writes plain value objects and actions when nothing applies', function () {
    Workspace::run(null, function (Workspace $workspace) {
        config()->set('mod.layout', 'ddd');
        $ns = isolatedDomainNamespace();
        installedPackages();

        $workspace->artisan('mod:value', ['name' => 'Billing:Money'])->assertSuccessful();
        $workspace->artisan('mod:action', ['name' => 'Billing:PayInvoice'])->assertSuccessful();

        expect($workspace->read('src/Domain/Billing/ValueObjects/Money.php'))
            ->toBe("<?php\n\nnamespace {$ns}\\Billing\\ValueObjects;\n\nclass Money\n{\n    public function __construct(\n        //\n    ) {}\n}\n")
            ->and($workspace->read('src/Domain/Billing/Actions/PayInvoice.php'))
            ->toBe("<?php\n\nnamespace {$ns}\\Billing\\Actions;\n\nclass PayInvoice\n{\n    public function handle(): void\n    {\n        //\n    }\n}\n");
    });
});

it('prefers a published stub, then a package stub, then the layout stub', function () {
    Workspace::run(null, function (Workspace $workspace) {
        config()->set('mod.layout', 'ddd');
        isolatedDomainNamespace();
        installedPackages();
        $workspace->write('package/value.stub', "<?php\n\nnamespace {{ namespace }};\n\nclass {{ class }}\n{\n    // from a package\n}\n");
        Mod::stubs()->for('value-object', Stub::file($workspace->root->path('package/value.stub')));

        $workspace->artisan('mod:value', ['name' => 'Billing:Money'])->assertSuccessful();
        expect($workspace->read('src/Domain/Billing/ValueObjects/Money.php'))->toContain('// from a package');

        $workspace->write('stubs/mod.value-object.stub', "<?php\n\nnamespace {{ namespace }};\n\nclass {{ class }}\n{\n    // from the application\n}\n");
        $workspace->artisan('mod:value', ['name' => 'Billing:Amount'])->assertSuccessful();
        expect($workspace->read('src/Domain/Billing/ValueObjects/Amount.php'))->toContain('// from the application');

        // A published stub still gets the base mod chose.
        $workspace->write('stubs/mod.dto.stub', "<?php\n\nnamespace {{ namespace }};\n{{ baseImport }}\nclass {{ class }}{{ extends }}\n{\n    // published\n}\n");
        $workspace->artisan('mod:dto', ['name' => 'Billing:InvoiceData'])->assertSuccessful();
        expect($workspace->read('src/Domain/Billing/Data/InvoiceData.php'))->toContain('class InvoiceData extends DataTransferObject')->toContain('// published');
    });
});

it('uses a published base stub for a generated base', function () {
    Workspace::run(null, function (Workspace $workspace) {
        config()->set('mod.layout', 'ddd');
        isolatedDomainNamespace();
        installedPackages();
        $workspace->write('stubs/mod.base.data-transfer-object.stub', "<?php\n\nnamespace {{ namespace }};\n\nabstract class {{ class }}\n{\n    // the application's base\n}\n");

        $workspace->artisan('mod:dto', ['name' => 'Billing:InvoiceData'])->assertSuccessful();

        expect($workspace->read('src/Domain/Shared/Data/DataTransferObject.php'))->toContain("// the application's base");
    });
});

it('registers the laravel-ddd aliases', function () {
    Workspace::run(null, function (Workspace $workspace) {
        config()->set('mod.layout', 'ddd');
        isolatedDomainNamespace();
        installedPackages();
        $commands = Artisan::all();

        foreach (['mod:data-transfer-object' => 'mod:dto', 'mod:datatransferobject' => 'mod:dto', 'mod:data' => 'mod:dto', 'mod:value-object' => 'mod:value', 'mod:valueobject' => 'mod:value', 'mod:viewmodel' => 'mod:view-model'] as $alias => $command) {
            expect($commands[$alias] ?? null)->toBe($commands[$command], $alias);
        }

        expect($workspace->artisan('mod:data', ['name' => 'Billing:InvoiceData']))->toHaveGenerated('src/Domain/Billing/Data/InvoiceData.php');
    });
});

it('lets a package swap the generator of a kind', function () {
    Workspace::run(null, function () {
        config()->set('mod.layout', 'ddd');
        isolatedDomainNamespace();
        Mod::generators()->use('dto', SwappedDtoCommand::class);

        expect(Artisan::all()['mod:dto'])->toBeInstanceOf(SwappedDtoCommand::class);
    });
});

final class SwappedDtoCommand extends GenericClassCommand {}
