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

        expect($workspace->artisan('mod:repository', ['name' => 'Knowledge:Document']))
            ->toHaveGenerated('src/Infrastructure/Knowledge/Repositories/DocumentRepository.php', 'Infrastructure\\Knowledge\\Repositories')
            ->and($workspace->artisan('mod:client', ['name' => 'Knowledge.Search:Index']))
            ->toHaveGenerated('src/Infrastructure/Knowledge/Search/Clients/IndexClient.php', 'Infrastructure\\Knowledge\\Search\\Clients')
            ->and($workspace->artisan('mod:job', ['name' => 'SyncDocuments', '--domain' => 'Knowledge']))
            ->toHaveGenerated('src/Infrastructure/Knowledge/Jobs/SyncDocuments.php', 'Infrastructure\\Knowledge\\Jobs');

        $workspace->write('stubs/mod.repository.stub', "<?php\n\nnamespace {{ namespace }};\n\nclass {{ class }}\n{\n    //\n}\n");
        $workspace->artisan('mod:repository', ['name' => 'Knowledge:Chunk'])->assertSuccessful();

        expect($workspace->read('src/Infrastructure/Knowledge/Repositories/ChunkRepository.php'))
            ->toBe("<?php\n\nnamespace Infrastructure\\Knowledge\\Repositories;\n\nclass ChunkRepository\n{\n    //\n}\n");
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

        $builder = $workspace->artisan('mod:builder', ['name' => 'Knowledge:Document']);

        expect($builder)->toHaveGenerated('src/Domain/Knowledge/Builders/DocumentBuilder.php', "{$ns}\\Knowledge\\Builders")
            ->and($builder->output)->toContainCreated('Query builder', 'src/Domain/Knowledge/Builders/DocumentBuilder.php')
            ->and($builder->output)->toContain('Add a newEloquentBuilder() method to the model to use it.')
            ->and($workspace->read('src/Domain/Knowledge/Builders/DocumentBuilder.php'))->toContain('class DocumentBuilder extends Builder')
            ->and($workspace->artisan('mod:query-builder', ['name' => 'Knowledge:Chunk']))
            ->toHaveGenerated('src/Domain/Knowledge/Builders/ChunkBuilder.php');
    });
});

it('adds to the aliases of an existing kind', function () {
    Workspace::run(null, function (Workspace $workspace) {
        config()->set('mod.layout', 'ddd');
        isolatedDomainNamespace();
        withoutOptionalPackages();

        Mod::layout('ddd')->kind('dto', aliases: ['mod:payload']);

        expect($workspace->artisan('mod:payload', ['name' => 'Knowledge:DocumentData']))->toHaveGenerated('src/Domain/Knowledge/Data/DocumentData.php')
            ->and($workspace->artisan('mod:data', ['name' => 'Knowledge:ChunkData']))->toHaveGenerated('src/Domain/Knowledge/Data/ChunkData.php');
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
            ->generatesBase(GeneratedBase::named('DataTransferObject', in: 'Shared/Data', stub: $workspace->root->path('package/stubs/bases/data-transfer-object.stub'))->inKindRoot());

        Mod::stubs()->for('dto', $dto());
        $generated = $workspace->artisan('mod:dto', ['name' => 'Knowledge:DocumentData']);

        expect($generated->output)->toContain("Created base class {$ns}\\Shared\\Data\\DataTransferObject [src/Domain/Shared/Data/DataTransferObject.php].")
            ->and($workspace->read('src/Domain/Knowledge/Data/DocumentData.php'))->toContain('class DocumentData extends DataTransferObject');

        config()->set('ddd.base_dto', "{$ns}\\Shared\\Data\\BaseData");
        $configured = $workspace->artisan('mod:dto', ['name' => 'Knowledge:ChunkData']);

        expect($configured->output)->toContain("Using the configured base {$ns}\\Shared\\Data\\BaseData.")
            ->and($workspace->read('src/Domain/Knowledge/Data/ChunkData.php'))->toContain("use {$ns}\\Shared\\Data\\BaseData;\n\nclass ChunkData extends BaseData\n");
    });
});

class DocsBuilderCommand extends GenericClassCommand
{
    protected function afterGeneration(GenerationPlan $plan, int $exitCode): void
    {
        $this->components->info('Add a newEloquentBuilder() method to the model to use it.');
    }
}
