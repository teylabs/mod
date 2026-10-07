<?php

namespace Tey\Mod\Generation;

use Illuminate\Contracts\Container\Container;
use Symfony\Component\Console\Command\Command;
use Tey\Mod\Artifact\ArtifactKind;
use Tey\Mod\Commands\ConsoleCommand;
use Tey\Mod\Commands\ControllerCommand;
use Tey\Mod\Commands\EventCommand;
use Tey\Mod\Commands\FactoryCommand;
use Tey\Mod\Commands\GenericClassCommand;
use Tey\Mod\Commands\ListenerCommand;
use Tey\Mod\Commands\MigrationCommand;
use Tey\Mod\Commands\ModelCommand;
use Tey\Mod\Commands\PolicyCommand;
use Tey\Mod\Commands\ProviderCommand;
use Tey\Mod\Commands\RequestCommand;
use Tey\Mod\Commands\SeederCommand;
use Tey\Mod\Preset\Preset;

/**
 * Which adapter generates which kind. Kinds stay preset data: the registry is
 * a keyed map the host can extend through `mod.generators`, and a
 * class-shaped kind nobody claims gets the declarative generic generator.
 */
final readonly class GeneratorRegistry
{
    /** @var array<string, class-string<GeneratorAdapter&Command>> */
    public const DEFAULTS = [
        'model' => ModelCommand::class,
        'controller' => ControllerCommand::class,
        'request' => RequestCommand::class,
        'factory' => FactoryCommand::class,
        'migration' => MigrationCommand::class,
        'policy' => PolicyCommand::class,
        'provider' => ProviderCommand::class,
        'command' => ConsoleCommand::class,
        'listener' => ListenerCommand::class,
        'event' => EventCommand::class,
        'seeder' => SeederCommand::class,
    ];

    /** @var array<string, class-string<GeneratorAdapter&Command>> */
    private array $adapters;

    /**
     * @param  array<array-key, mixed>  $overrides  kind id => adapter class (config `mod.generators`)
     */
    public function __construct(array $overrides = [])
    {
        $adapters = self::DEFAULTS;

        foreach ($overrides as $kindId => $adapter) {
            if (! is_string($kindId) || ! is_string($adapter)
                || ! is_subclass_of($adapter, GeneratorAdapter::class) || ! is_subclass_of($adapter, Command::class)) {
                throw new InvalidGeneratorSetup(sprintf(
                    'Config [mod.generators] must map kind ids to %s commands; [%s] is not one.',
                    GeneratorAdapter::class,
                    is_string($adapter) ? $adapter : get_debug_type($adapter),
                ));
            }

            $adapters[$kindId] = $adapter;
        }

        $this->adapters = $adapters;
    }

    /**
     * The adapter class for a kind, or null when the kind cannot be generated.
     *
     * @return class-string<GeneratorAdapter&Command>|null
     */
    public function adapterFor(ArtifactKind $kind): ?string
    {
        if (isset($this->adapters[$kind->id])) {
            return $this->adapters[$kind->id];
        }

        return $kind->isClass() ? GenericClassCommand::class : null;
    }

    /**
     * One command per preset kind that declares a command name.
     *
     * @return list<GeneratorAdapter&Command>
     */
    public function commands(Preset $preset, Container $container): array
    {
        $commands = [];

        foreach ($preset->kinds() as $kind) {
            if ($kind->command === null || ($adapter = $this->adapterFor($kind)) === null) {
                continue;
            }

            if (! $adapter::supports($kind)) {
                throw new InvalidGeneratorSetup(sprintf(
                    'Generator [%s] cannot generate kind [%s] (%s, name %s).',
                    $adapter,
                    $kind->id,
                    $kind->shape->value,
                    $kind->namePolicy->describe(),
                ));
            }

            /** @var GeneratorAdapter&Command $command */
            $command = $container->make($adapter);

            $commands[] = $command->forKind($preset, $kind);
        }

        return $commands;
    }
}
