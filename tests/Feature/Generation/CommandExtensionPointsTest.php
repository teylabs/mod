<?php

use Illuminate\Contracts\Console\Kernel;
use Symfony\Component\Console\Input\InputOption;
use Tey\Mod\Artifact\ArtifactKind;
use Tey\Mod\Commands\ModelCommand;
use Tey\Mod\Exceptions\ModException;
use Tey\Mod\Generation\CollisionPolicy;
use Tey\Mod\Generation\GenerationPlan;
use Tey\Mod\Placement\PlacementContext;
use Tey\Mod\Preset\Preset;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;
use Tey\Mod\Tests\Fixtures\Layouts;

/*
 * E7+: the protected hooks a host package builds its own generators on, and
 * the "Group:Name" shorthand every adapter accepts.
 */

it('accepts the colon shorthand as placement on every adapter', function (string $command, string $name, string $path) {
    Workspace::run('modules', function (Workspace $workspace) use ($command, $name, $path) {
        $workspace->artisan($command, ['name' => 'Billing:'.$name])->assertSuccessful();

        expect($workspace->files())->toBe([$path]);
    });
})->with([
    'model' => ['mod:model', 'Invoice', 'app/Modules/Billing/Models/Invoice.php'],
    'controller' => ['mod:controller', 'InvoiceController', 'app/Modules/Billing/Controllers/InvoiceController.php'],
    'provider' => ['mod:provider', 'BillingServiceProvider', 'app/Modules/Billing/Providers/BillingServiceProvider.php'],
    'declared kind' => ['mod:query', 'OverdueInvoices', 'app/Modules/Billing/Queries/OverdueInvoices.php'],
]);

it('generates the same file from the shorthand and from --in', function () {
    $shorthand = Workspace::run('modules', function (Workspace $workspace) {
        $workspace->artisan('mod:model', ['name' => 'Billing:Invoice'])->assertSuccessful();

        return [$workspace->files(), $workspace->read('app/Modules/Billing/Models/Invoice.php')];
    });
    $option = Workspace::run('modules', function (Workspace $workspace) {
        $workspace->artisan('mod:model', ['name' => 'Invoice', '--in' => 'Billing'])->assertSuccessful();

        return [$workspace->files(), $workspace->read('app/Modules/Billing/Models/Invoice.php')];
    });

    expect($shorthand)->toBe($option);
});

it('places a shorthand migration and keeps the migration name intact', function () {
    Workspace::run('modules', function (Workspace $workspace) {
        $workspace->artisan('mod:migration', ['name' => 'Billing:create_invoices_table', '--create' => 'invoices'])->assertSuccessful();

        expect($workspace->files())->toHaveCount(1)
            ->and($workspace->files()[0])->toStartWith('app/Modules/Billing/Database/Migrations/')
            ->and($workspace->files()[0])->toEndWith('_create_invoices_table.php');
    });
});

it('refuses placement given both as the prefix and as --in, naming both', function () {
    Workspace::run('modules', function (Workspace $workspace) {
        $workspace->artisan('mod:model', ['name' => 'Billing:Invoice', '--in' => 'Shipping'])
            ->expectsOutputToContain('Placement was given twice: as the prefix [Billing:] of the name and as --in=Shipping')
            ->assertFailed();

        expect($workspace->files())->toBe([]);
    });
});

it('refuses the shorthand in a layout without placement groups, actionably', function () {
    Workspace::run('ordinary', function (Workspace $workspace) {
        config()->set('mod.layout', 'laravel');

        $workspace->artisan('mod:model', ['name' => 'Billing:Invoice'])
            ->expectsOutputToContain('Layout [laravel] has no placement groups; drop the [Billing:] prefix.')
            ->assertFailed();

        expect($workspace->files())->toBe([]);
    });
});

it('reads multi-segment and nested names through the shorthand', function () {
    $definition = Layouts::definition('modules');

    foreach ($definition['kinds'] as $id => $kind) {
        if (isset($kind['segments'])) {
            $definition['kinds'][$id]['segments'] = array_map(fn (string $s) => $s === '{module}' ? '{module+}' : $s, $kind['segments']);
        }
    }
    $definition['kinds']['model']['nested'] = true;

    Workspace::run($definition, function (Workspace $workspace) {
        $workspace->artisan('mod:model', ['name' => 'Billing.Invoicing:Archived/Invoice'])->assertSuccessful();

        expect($workspace->files())->toBe(['app/Modules/Billing/Invoicing/Models/Archived/Invoice.php'])
            ->and($workspace->read('app/Modules/Billing/Invoicing/Models/Archived/Invoice.php'))
            ->toContain('namespace App\Modules\Billing\Invoicing\Models\Archived;');
    });
});

/**
 * A host generator built on the model adapter: its own placement option,
 * a per-invocation kind, native collision handling and hooks.
 */
final class HostModelCommand extends ModelCommand
{
    protected $name = 'host:model';

    /** @var list<string> */
    public array $trace = [];

    public ?Preset $hostPreset = null;

    public CollisionPolicy $policy = CollisionPolicy::Refuse;

    public bool $eager = true;

    protected function configure(): void
    {
        $this->getDefinition()->addOption(new InputOption('group', null, InputOption::VALUE_REQUIRED, 'The group'));

        parent::configure();

        // Native generators declare $signature on recent Laravel, which wins over $name.
        $this->setName('host:model');
    }

    protected function placementOptionName(): ?string
    {
        return null;
    }

    protected function resolvePreset(): Preset
    {
        return $this->hostPreset ?? throw new LogicException('no host preset');
    }

    protected function kindId(): string
    {
        return 'model';
    }

    protected function placementInput(): ?string
    {
        $group = $this->option('group');

        return is_string($group) && $group !== '' ? $group : parent::placementInput();
    }

    protected function collisionPolicy(): CollisionPolicy
    {
        return $this->policy;
    }

    protected function plansEagerly(): bool
    {
        return $this->eager;
    }

    public function handle()
    {
        $this->trace[] = 'handle';

        if (! $this->eager) {
            $this->resolvePlan();
        }

        return parent::handle();
    }

    protected function beforeGeneration(GenerationPlan $plan): void
    {
        $this->trace[] = 'before:'.$plan->primary->fqcn();
    }

    protected function afterGeneration(GenerationPlan $plan, int $exitCode): void
    {
        $this->trace[] = 'after:'.$exitCode;
    }

    protected function reportRefusal(ModException $exception): int
    {
        $this->trace[] = 'refused';
        $this->components->warn('Host says no: '.$exception->getMessage());

        return 7;
    }
}

it('lets a host command bring its own preset, kind, placement option and output', function () {
    Workspace::run('modules', function (Workspace $workspace) {
        $command = new HostModelCommand(app('files'));
        $command->hostPreset = Preset::fromArray(Layouts::definition('modules'));
        $command->setLaravel(app());
        app(Kernel::class)->registerCommand($command);

        $workspace->artisan('host:model', ['name' => 'Invoice', '--group' => 'Billing'])->assertSuccessful();

        expect($workspace->files())->toBe(['app/Modules/Billing/Models/Invoice.php'])
            ->and($command->trace)->toBe(['before:App\Modules\Billing\Models\Invoice', 'handle', 'after:0']);

        // The host's shorthand still works without an --in option, and its refusal output is its own.
        $command->trace = [];
        $workspace->artisan('host:model', ['name' => 'Billing:Invoice'])
            ->expectsOutputToContain('Host says no: Refusing to write: path collision')
            ->assertFailed();

        expect($command->trace)->toBe(['refused']);
    });
});

it('lets a host plan lazily from inside handle() and leave collisions to the native generator', function () {
    Workspace::run('modules', function (Workspace $workspace) {
        $workspace->write('app/Modules/Billing/Models/Invoice.php', '<?php // mine');

        $command = new HostModelCommand(app('files'));
        $command->hostPreset = Preset::fromArray(Layouts::definition('modules'));
        $command->policy = CollisionPolicy::Native;
        $command->eager = false;
        $command->setLaravel(app());
        app(Kernel::class)->registerCommand($command);

        // Native "already exists" decides: the generator reports it and leaves the file alone. The native
        // handle() returns false there, which Laravel's console runner casts to exit code 0: the policy
        // hands the host exactly the native behaviour, quirks included.
        $native = $workspace->artisan('host:model', ['name' => 'Invoice', '--group' => 'Billing'])
            ->expectsOutputToContain('already exists');

        expect($workspace->read('app/Modules/Billing/Models/Invoice.php'))->toBe('<?php // mine')
            ->and($command->trace)->toBe(['handle', 'before:App\Modules\Billing\Models\Invoice', 'after:'.$native->exitCode]);

        // And --force overwrites, as natively.
        $command->trace = [];
        $workspace->artisan('host:model', ['name' => 'Invoice', '--group' => 'Billing', '--force' => true])->assertSuccessful();

        expect($workspace->read('app/Modules/Billing/Models/Invoice.php'))->toContain('class Invoice extends Model')
            ->and($command->trace)->toBe(['handle', 'before:App\Modules\Billing\Models\Invoice', 'after:0']);
    });
});

it('passes placement to child commands through the host shorthand when there is no --in option', function () {
    Workspace::run('modules', function (Workspace $workspace) {
        $command = new HostModelCommand(app('files'));
        $command->hostPreset = Preset::fromArray(Layouts::definition('modules'));
        $command->setLaravel(app());
        app(Kernel::class)->registerCommand($command);

        $workspace->artisan('host:model', ['name' => 'Billing:Invoice', '--factory' => true])->assertSuccessful();

        expect($workspace->files())->toBe([
            'app/Modules/Billing/Database/Factories/InvoiceFactory.php',
            'app/Modules/Billing/Models/Invoice.php',
        ]);
    });
});

it('keeps the adapter contract for the host kind', function () {
    expect(HostModelCommand::supports(ArtifactKind::phpClass('model')))->toBeTrue()
        ->and(PlacementContext::of(['module' => 'Billing'])->toArray())->toBe(['module' => 'Billing']);
});
