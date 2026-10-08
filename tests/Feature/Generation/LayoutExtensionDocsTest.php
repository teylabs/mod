<?php

use Tey\Mod\Commands\GenericClassCommand;
use Tey\Mod\Facades\Mod;
use Tey\Mod\Generation\GeneratedBase;
use Tey\Mod\Generation\GenerationPlan;
use Tey\Mod\Generation\PackageDetector;
use Tey\Mod\Generation\Stub;
use Tey\Mod\Layout\Root;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;

/*
 * The examples in docs/layouts.md ("Adding a Layer") and docs/extending.md
 * ("Writing a Mod Plugin"), run as written against the ddd layout.
 */

function withoutOptionalPackages(): void
{
    app()->instance(PackageDetector::class, new class implements PackageDetector
    {
        public function isInstalled(string $package): bool
        {
            return false;
        }

        public function classExists(string $class): bool
        {
            return false;
        }
    });
}

it('adds an infrastructure layer to the ddd layout', function () {
    Workspace::run(null, function (Workspace $workspace) {
        config()->set('mod.layout', 'ddd');

        Mod::layout('ddd')
            ->root('infrastructure', 'Infrastructure\\', 'src/Infrastructure', fn (Root $root) => $root
                ->kind('repository', in: '{domain+}/Repositories', suffix: 'Repository')
                ->kind('client', in: '{domain+}/Clients', suffix: 'Client'));
        Mod::layout('ddd')->kind('job', in: 'infrastructure:{domain+}/Jobs');

        expect($workspace->artisan('mod:repository', ['name' => 'Billing:Invoice']))
            ->toHaveGenerated('src/Infrastructure/Billing/Repositories/InvoiceRepository.php', 'Infrastructure\\Billing\\Repositories')
            ->and($workspace->artisan('mod:client', ['name' => 'Reporting.Internal:Ledger']))
            ->toHaveGenerated('src/Infrastructure/Reporting/Internal/Clients/LedgerClient.php', 'Infrastructure\\Reporting\\Internal\\Clients')
            ->and($workspace->artisan('mod:job', ['name' => 'SyncInvoices', '--domain' => 'Billing']))
            ->toHaveGenerated('src/Infrastructure/Billing/Jobs/SyncInvoices.php', 'Infrastructure\\Billing\\Jobs');

        $workspace->write('stubs/mod.repository.stub', "<?php\n\nnamespace {{ namespace }};\n\nclass {{ class }}\n{\n    //\n}\n");
        $workspace->artisan('mod:repository', ['name' => 'Billing:Payment'])->assertSuccessful();

        expect($workspace->read('src/Infrastructure/Billing/Repositories/PaymentRepository.php'))
            ->toBe("<?php\n\nnamespace Infrastructure\\Billing\\Repositories;\n\nclass PaymentRepository\n{\n    //\n}\n");
    });
});

it('adds a kind with an alias, its stub and a swapped generator from a plugin', function () {
    Workspace::run(null, function (Workspace $workspace) {
        config()->set('mod.layout', 'ddd');
        $ns = isolatedDomainNamespace();
        $workspace->write('package/stubs/builder.stub', "<?php\n\nnamespace {{ namespace }};\n\nuse Illuminate\\Database\\Eloquent\\Builder;\n\nclass {{ class }} extends Builder\n{\n    //\n}\n");

        Mod::layout('ddd')
            ->kind('builder', in: '{domain+}/Builders', suffix: 'Builder', aliases: ['mod:query-builder'], label: 'Query builder');
        Mod::stubs()->for('builder', Stub::file($workspace->root->path('package/stubs/builder.stub')));
        Mod::generators()->use('builder', DocsBuilderCommand::class);

        $builder = $workspace->artisan('mod:builder', ['name' => 'Billing:Invoice']);

        expect($builder)->toHaveGenerated('src/Domain/Billing/Builders/InvoiceBuilder.php', "{$ns}\\Billing\\Builders")
            ->and($builder->output)->toContain('Query builder [src/Domain/Billing/Builders/InvoiceBuilder.php] created successfully.')
            ->and($builder->output)->toContain('Add a newEloquentBuilder() method to the model to use it.')
            ->and($workspace->read('src/Domain/Billing/Builders/InvoiceBuilder.php'))->toContain('class InvoiceBuilder extends Builder')
            ->and($workspace->artisan('mod:query-builder', ['name' => 'Billing:Payment']))
            ->toHaveGenerated('src/Domain/Billing/Builders/PaymentBuilder.php');
    });
});

it('adds to the aliases of an existing kind', function () {
    Workspace::run(null, function (Workspace $workspace) {
        config()->set('mod.layout', 'ddd');
        isolatedDomainNamespace();
        withoutOptionalPackages();

        Mod::layout('ddd')->kind('dto', aliases: ['mod:payload']);

        expect($workspace->artisan('mod:payload', ['name' => 'Billing:InvoiceData']))->toHaveGenerated('src/Domain/Billing/Data/InvoiceData.php')
            ->and($workspace->artisan('mod:data', ['name' => 'Billing:LineData']))->toHaveGenerated('src/Domain/Billing/Data/LineData.php');
    });
});

it('reads a plugin base from its own config key before detection and the generated base', function () {
    Workspace::run(null, function (Workspace $workspace) {
        config()->set('mod.layout', 'ddd');
        $ns = isolatedDomainNamespace();
        withoutOptionalPackages();
        $workspace->write('package/stubs/dto.stub', "<?php\n\nnamespace {{ namespace }};\n{{ baseImport }}\nclass {{ class }}{{ extends }}\n{\n    public function __construct(\n        //\n    ) {}\n}\n");
        $workspace->write('package/stubs/bases/data-transfer-object.stub', "<?php\n\nnamespace {{ namespace }};\n\nabstract class {{ class }}\n{\n}\n");
        $dto = fn () => Stub::file($workspace->root->path('package/stubs/dto.stub'))
            ->base(config: 'ddd.base_dto')
            ->whenInstalled('spatie/laravel-data', base: 'Spatie\\LaravelData\\Data')
            ->generatesBase(GeneratedBase::named('DataTransferObject', in: 'Shared/Data', stub: $workspace->root->path('package/stubs/bases/data-transfer-object.stub')));

        Mod::stubs()->for('dto', $dto());
        $generated = $workspace->artisan('mod:dto', ['name' => 'Billing:InvoiceData']);

        expect($generated->output)->toContain("Created base class {$ns}\\Shared\\Data\\DataTransferObject [src/Domain/Shared/Data/DataTransferObject.php].")
            ->and($workspace->read('src/Domain/Billing/Data/InvoiceData.php'))->toContain('class InvoiceData extends DataTransferObject');

        config()->set('ddd.base_dto', "{$ns}\\Shared\\Data\\BaseData");
        $configured = $workspace->artisan('mod:dto', ['name' => 'Billing:LineData']);

        expect($configured->output)->toContain("Using the configured base {$ns}\\Shared\\Data\\BaseData.")
            ->and($workspace->read('src/Domain/Billing/Data/LineData.php'))->toContain("use {$ns}\\Shared\\Data\\BaseData;\n\nclass LineData extends BaseData\n");
    });
});

class DocsBuilderCommand extends GenericClassCommand
{
    protected function afterGeneration(GenerationPlan $plan, int $exitCode): void
    {
        $this->components->info('Add a newEloquentBuilder() method to the model to use it.');
    }
}
