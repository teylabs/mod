<?php

namespace Tey\Mod\Tests\Integration;

use Illuminate\Console\Application;
use Illuminate\Console\Command;
use Illuminate\Database\Migrations\MigrationCreator;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Composer;
use Orchestra\Testbench\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tey\Mod\Artifact\ResolvedArtifact;
use Tey\Mod\Commands\ControllerCommand;
use Tey\Mod\Commands\GenericClassCommand;
use Tey\Mod\Commands\MigrationCommand;
use Tey\Mod\Commands\ModelCommand;
use Tey\Mod\Commands\RequestCommand;
use Tey\Mod\Discovery\Discovery;
use Tey\Mod\Generation\GeneratedBase;
use Tey\Mod\Generation\GenerationPlan;
use Tey\Mod\Generation\Stub;
use Tey\Mod\Generation\StubRegistry;
use Tey\Mod\Layout\CompiledLayout;
use Tey\Mod\Layout\Layout;
use Tey\Mod\ModServiceProvider;
use Tey\Mod\Relation\RelationResolution;
use Tey\Mod\Tests\Support\OwnedAppRoot;

final class HostGeneratorsTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [];
    }

    /** @param array<string,mixed> $parameters */
    private function input(array $parameters): ArrayInput
    {
        $input = new ArrayInput($parameters);
        $input->setInteractive(false);

        return $input;
    }

    public function test_isolated_host_model_maps_native_relations_and_migration_preflight(): void
    {
        OwnedAppRoot::using(function (OwnedAppRoot $root): void {
            $this->app->setBasePath($root->path);
            $this->assertSame([], $this->app->getProviders(ModServiceProvider::class));
            $this->assertFalse($this->app->bound(CompiledLayout::class));
            ModServiceProvider::registerGenerationServices($this->app);
            ModServiceProvider::registerGenerationServices($this->app);
            $this->assertFalse($this->app->bound(Discovery::class));
            $this->assertFalse($this->app->bound(CompiledLayout::class));
            $namespace = 'Host'.bin2hex(random_bytes(4));
            $layout = Layout::fresh('host')->path('src')->mounts('host', $namespace.'\\', 'src')
                ->generates('custom-model', in: 'host:Models', command: 'host:model')
                ->generates('custom-factory', in: 'host:Factories', suffix: 'Factory', command: 'host:child')
                ->generates('custom-migration', in: 'host:Migrations', timestamped: true, command: 'host:migration')
                ->relates('custom-model', 'custom-factory')
                ->relates('custom-model', 'custom-migration', name: 'explicit')->compiled();
            $command = new HostModel($this->app->make('files'), $layout);
            $command->setLaravel($this->app);
            $command->setName('host:model');
            $console = new Application($this->app, $this->app->make('events'), 'test');
            $console->resolve($command);
            $child = new HostChild;
            $console->resolve($child);
            $migrationChild = new HostChild;
            $migrationChild->setName('host:migration');
            $console->resolve($migrationChild);
            $output = new BufferedOutput;
            $code = $console->run(new ArrayInput(['command' => 'host:model', 'name' => 'Invoice', '--factory' => true, '--migration' => true]), $output);
            $this->assertSame(0, $code, $output->fetch());
            $this->assertFileExists($root->path('src/Models/Invoice.php'));
            $this->assertStringContainsString($namespace.'\\Factories\\InvoiceFactory', file_get_contents($root->path('src/Models/Invoice.php')));
            $this->assertSame(['custom-factory', 'custom-migration'], $command->followed);
            $this->assertSame(['Invoice', 'create_invoices_table'], [...$child->names, ...$migrationChild->names]);
            $this->assertNull($command->inspectPlan());
        });
    }

    public function test_custom_primary_is_validated_and_invocation_state_is_cleared(): void
    {
        OwnedAppRoot::using(function (OwnedAppRoot $root): void {
            $this->app->setBasePath($root->path);
            ModServiceProvider::registerGenerationServices($this->app);
            $layout = Layout::fresh('host')->path('src')->mounts('host', 'Host\\', 'src')->generates('custom-class', in: 'host:Classes')->compiled();
            $command = new HostPrimary($this->app->make('files'), $layout);
            $command->setLaravel($this->app);
            $output = new BufferedOutput;
            $this->assertSame(0, $command->run($this->input(['name' => 'Relocated']), $output), $output->fetch());
            $this->assertStringContainsString('namespace Chosen;', file_get_contents($root->path('chosen/Relocated.php')));
            $this->assertNull($command->inspectPlan());
            $this->assertSame(0, $command->run($this->input(['name' => 'Second']), $output), $output->fetch());
            $this->assertFileExists($root->path('chosen/Second.php'));
            $this->assertNull($command->inspectPlan());
        });
    }

    public function test_cross_layer_requests_use_host_names_dispatch_and_relation_id_fallback(): void
    {
        OwnedAppRoot::using(function (OwnedAppRoot $root): void {
            $this->app->setBasePath($root->path);
            ModServiceProvider::registerGenerationServices($this->app);
            $ns = 'Forward'.bin2hex(random_bytes(4));
            $layout = Layout::fresh('host')->path('src')
                ->mounts('host', $ns.'\\', 'src')
                ->mounts('requests', $ns.'\\Application\\', 'application')
                ->generates('custom-controller', in: 'host:Controllers', suffix: 'Controller')
                ->generates('custom-model', in: 'host:Models')
                ->generates('custom-request', in: 'requests:Requests')
                ->relates('custom-controller', 'custom-request', as: 'custom-controller-store-request')
                ->relates('custom-controller', 'custom-request', as: 'custom-controller-update-request')->compiled();
            mkdir($root->path('src/Models'), 0700, true);
            file_put_contents($root->path('src/Models/Invoice.php'), '<?php namespace '.$ns.'\\Models; class Invoice {}');
            $controller = new HostController($this->app->make('files'), $layout);
            $controller->setName('host:controller');
            $request = new HostRequest($this->app->make('files'), $layout);
            $request->setName('host:request');
            $console = new Application($this->app, $this->app->make('events'), 'test');
            $console->resolve($controller);
            $console->resolve($request);
            $output = new BufferedOutput;
            $code = $console->run($this->input(['command' => 'host:controller', 'name' => 'Invoice', '--model' => 'Invoice', '--requests' => true]), $output);
            $this->assertSame(0, $code, $output->fetch());
            $this->assertFileExists($root->path('application/Requests/StoreInvoiceRequest.php'));
            $this->assertFileExists($root->path('application/Requests/UpdateInvoiceRequest.php'));
            $source = str_replace("\r\n", "\n", file_get_contents($root->path('src/Controllers/InvoiceController.php')));
            $this->assertStringContainsString('use '.$ns.'\\Application\\Requests\\StoreInvoiceRequest;', $source);
            $this->assertStringContainsString('use '.$ns.'\\Application\\Requests\\UpdateInvoiceRequest;', $source);
            $this->assertSame(['StoreInvoiceRequest', 'UpdateInvoiceRequest'], $controller->forwarded);
            $this->assertNull($controller->inspectPlan());
        });
    }

    public function test_targeted_defaults_preserve_host_bindings_and_support_variants_and_generated_bases(): void
    {
        OwnedAppRoot::using(function (OwnedAppRoot $root): void {
            $this->app->setBasePath($root->path);
            $registry = new StubRegistry;
            $this->app->instance(StubRegistry::class, $registry);
            ModServiceProvider::registerGenerationServices($this->app);
            $this->assertSame($registry, $this->app->make(StubRegistry::class));
            mkdir($root->path('templates'), 0700, true);
            file_put_contents($root->path('templates/class.stub'), "<?php\nnamespace {{ namespace }};\n{{ baseImport }}\nclass {{ class }}{{ extends }} {}\n");
            file_put_contents($root->path('templates/base.stub'), "<?php\nnamespace {{ namespace }};\nclass {{ class }} {}\n");
            $layout = Layout::fresh('host')->path('src')->mounts('host', 'Host\\', 'src')->generates('custom-class', in: 'host:Classes')->compiled();
            $registry->forFileType('custom-class', Stub::file($root->path('templates/class.stub'))->whenInstalled('laravel/framework', base: 'Illuminate\\Support\\Collection'));
            $command = new HostPrimary($this->app->make('files'), $layout);
            $command->setLaravel($this->app);
            $output = new BufferedOutput;
            $this->assertSame(0, $command->run($this->input(['name' => 'Variant']), $output), $output->fetch());
            $this->assertStringContainsString('use Illuminate\\Support\\Collection;', file_get_contents($root->path('chosen/Variant.php')));
            $base = 'Base'.bin2hex(random_bytes(4));
            $registry->forFileType('custom-class', Stub::file($root->path('templates/class.stub'))->generatesBase(GeneratedBase::named($base, in: 'Hosts', stub: $root->path('templates/base.stub'))));
            $this->assertSame(0, $command->run($this->input(['name' => 'WithBase']), $output), $output->fetch());
            $this->assertFileExists($root->path('app/Support/Hosts/'.$base.'.php'));
            $this->assertStringContainsString('extends '.$base, file_get_contents($root->path('chosen/WithBase.php')));
        });
    }

    public function test_framework_and_custom_migration_creators_need_no_mod_internal_type(): void
    {
        OwnedAppRoot::using(function (OwnedAppRoot $root): void {
            $this->app->setBasePath($root->path);
            $files = $this->app->make('files');
            $layout = Layout::fresh('laravel')->compiled();
            $native = new MigrationCreator($files, $root->path('stubs'));
            $command = new HostMigration($native, new Composer($files), $layout);
            $command->setLaravel($this->app);
            $output = new BufferedOutput;
            $this->assertSame(0, $command->run($this->input(['name' => 'create_hosts_table']), $output), $output->fetch());
            $this->assertCount(1, glob($root->path('database/migrations/*_create_hosts_table.php')));
            $custom = new class($files, $root->path('stubs')) extends MigrationCreator
            {
                public bool $called = false;

                public function create($name, $path, $table = null, $create = false)
                {
                    $this->called = true;

                    return parent::create($name, $path, $table, $create);
                }
            };
            $command = new HostMigration($custom, new Composer($files), $layout);
            $command->setLaravel($this->app);
            $this->assertSame(0, $command->run($this->input(['name' => 'custom_write']), $output), $output->fetch());
            $this->assertTrue($custom->called);
            $this->assertSame(0, $command->before);
        });
    }
}

class HostModel extends ModelCommand
{
    /** @var list<string> */
    public array $followed = [];

    public function __construct(Filesystem $files, private readonly CompiledLayout $hostLayout)
    {
        parent::__construct($files);
    }

    protected function resolveLayout(): CompiledLayout
    {
        return $this->hostLayout;
    }

    protected function fileTypeId(): string
    {
        return 'custom-model';
    }

    protected function relatedFileType(string $role): string
    {
        return 'custom-'.$role;
    }

    /** @return array<string,mixed> */
    protected function argumentsFor(ResolvedArtifact $target): array
    {
        return ['name' => $target->nestedName()];
    }

    /** @param array<string,mixed> $arguments */
    protected function followRelation(RelationResolution $resolution, array $arguments = []): void
    {
        $this->followed[] = $resolution->target->fileType->id;
        parent::followRelation($resolution, $arguments);
    }

    public function inspectPlan(): ?GenerationPlan
    {
        return $this->currentPlan();
    }
}

class HostChild extends Command
{
    protected $signature = 'host:child {name} {--model=} {--create=}';

    /** @var list<string> */
    public array $names = [];

    public function handle(): int
    {
        $this->names[] = $this->argument('name');

        return 0;
    }
}

class HostPrimary extends GenericClassCommand
{
    public function __construct(Filesystem $files, private readonly CompiledLayout $hostLayout)
    {
        parent::__construct($files);
    }

    protected function resolveLayout(): CompiledLayout
    {
        return $this->hostLayout;
    }

    protected function fileTypeId(): string
    {
        return 'custom-class';
    }

    protected function plan(): GenerationPlan
    {
        $name = $this->argument('name');

        return new GenerationPlan(ResolvedArtifact::phpClass('custom-class', 'Chosen', $name, 'chosen/'.$name.'.php'));
    }

    public function inspectPlan(): ?GenerationPlan
    {
        return $this->currentPlan();
    }
}

class HostMigration extends MigrationCommand
{
    public int $before = 0;

    public function __construct(MigrationCreator $creator, Composer $composer, private readonly CompiledLayout $hostLayout)
    {
        parent::__construct($creator, $composer);
    }

    protected function resolveLayout(): CompiledLayout
    {
        return $this->hostLayout;
    }

    protected function fileTypeId(): string
    {
        return 'migration';
    }

    protected function beforeGeneration(GenerationPlan $plan): void
    {
        $this->before++;
    }

    protected function nativePathAllowed(): bool
    {
        return true;
    }
}

class HostController extends ControllerCommand
{
    /** @var list<string> */
    public array $forwarded = [];

    public function __construct(Filesystem $files, private readonly CompiledLayout $hostLayout)
    {
        parent::__construct($files);
    }

    protected function resolveLayout(): CompiledLayout
    {
        return $this->hostLayout;
    }

    protected function fileTypeId(): string
    {
        return 'custom-controller';
    }

    protected function relatedFileType(string $role): string
    {
        return 'custom-'.$role;
    }

    protected function plansEagerly(): bool
    {
        return false;
    }

    public function handle()
    {
        $this->resolvePlan();

        return parent::handle();
    }

    /** @return list<RelationResolution> */
    protected function plannedRelations(ResolvedArtifact $primary): array
    {
        $result = [];
        foreach ($this->layout()->relationsFrom($this->fileTypeId()) as $relation) {
            $name = (str_ends_with($relation->id, 'update-request') ? 'Update' : 'Store').class_basename($this->option('model')).'Request';
            $result[] = RelationResolution::resolved($relation, $primary, $this->layout()->place($relation->toFileType, $name, $primary->context));
        }

        return $result;
    }

    protected function plannedRelation(string $relationId): ?RelationResolution
    {
        foreach ($this->currentPlan()->relations ?? [] as $resolution) {
            if (str_ends_with($resolution->relation->id, str_replace('controller-', '', $relationId))) {
                return $resolution;
            }
        }

        return parent::plannedRelation($relationId);
    }

    /** @return array<string,mixed> */
    protected function argumentsFor(ResolvedArtifact $target): array
    {
        return ['name' => $target->nestedName()];
    }

    /** @param array<string,mixed> $arguments */
    protected function followRelation(RelationResolution $resolution, array $arguments = []): void
    {
        $target = $resolution->target;
        if ($target === null) {
            throw new \LogicException('A target is required.');
        }
        $this->forwarded[] = $target->nestedName();
        if ($this->call('host:request', $this->argumentsFor($target)) !== 0) {
            throw new \RuntimeException('Host request dispatch failed.');
        }
    }

    public function inspectPlan(): ?GenerationPlan
    {
        return $this->currentPlan();
    }
}

class HostRequest extends RequestCommand
{
    public function __construct(Filesystem $files, private readonly CompiledLayout $hostLayout)
    {
        parent::__construct($files);
    }

    protected function resolveLayout(): CompiledLayout
    {
        return $this->hostLayout;
    }

    protected function fileTypeId(): string
    {
        return 'custom-request';
    }
}
