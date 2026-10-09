<?php

namespace Tey\Mod\Generation;

use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Console\Factories\FactoryMakeCommand;
use Illuminate\Database\Console\Migrations\MigrateMakeCommand;
use Illuminate\Database\Console\Seeds\SeederMakeCommand;
use Illuminate\Foundation\Console\CastMakeCommand;
use Illuminate\Foundation\Console\ChannelMakeCommand;
use Illuminate\Foundation\Console\ClassMakeCommand;
use Illuminate\Foundation\Console\ConfigMakeCommand;
use Illuminate\Foundation\Console\ConsoleMakeCommand;
use Illuminate\Foundation\Console\EnumMakeCommand;
use Illuminate\Foundation\Console\EventMakeCommand;
use Illuminate\Foundation\Console\ExceptionMakeCommand;
use Illuminate\Foundation\Console\InterfaceMakeCommand;
use Illuminate\Foundation\Console\JobMakeCommand;
use Illuminate\Foundation\Console\JobMiddlewareMakeCommand;
use Illuminate\Foundation\Console\ListenerMakeCommand;
use Illuminate\Foundation\Console\MailMakeCommand;
use Illuminate\Foundation\Console\ModelMakeCommand;
use Illuminate\Foundation\Console\NotificationMakeCommand;
use Illuminate\Foundation\Console\ObserverMakeCommand;
use Illuminate\Foundation\Console\PolicyMakeCommand;
use Illuminate\Foundation\Console\ProviderMakeCommand;
use Illuminate\Foundation\Console\RequestMakeCommand;
use Illuminate\Foundation\Console\ResourceMakeCommand;
use Illuminate\Foundation\Console\RuleMakeCommand;
use Illuminate\Foundation\Console\ScopeMakeCommand;
use Illuminate\Foundation\Console\TestMakeCommand;
use Illuminate\Foundation\Console\TraitMakeCommand;
use Illuminate\Routing\Console\ControllerMakeCommand;
use Illuminate\Routing\Console\MiddlewareMakeCommand;
use Symfony\Component\Console\Command\Command;
use Tey\Mod\Artifact\ArtifactKind;
use Tey\Mod\Commands\CastCommand;
use Tey\Mod\Commands\ChannelCommand;
use Tey\Mod\Commands\ClassCommand;
use Tey\Mod\Commands\ConfigCommand;
use Tey\Mod\Commands\ConsoleCommand;
use Tey\Mod\Commands\ControllerCommand;
use Tey\Mod\Commands\EnumCommand;
use Tey\Mod\Commands\EventCommand;
use Tey\Mod\Commands\ExceptionCommand;
use Tey\Mod\Commands\FactoryCommand;
use Tey\Mod\Commands\GenericClassCommand;
use Tey\Mod\Commands\InterfaceCommand;
use Tey\Mod\Commands\JobCommand;
use Tey\Mod\Commands\JobMiddlewareCommand;
use Tey\Mod\Commands\ListenerCommand;
use Tey\Mod\Commands\MailCommand;
use Tey\Mod\Commands\MiddlewareCommand;
use Tey\Mod\Commands\MigrationCommand;
use Tey\Mod\Commands\ModelCommand;
use Tey\Mod\Commands\NotificationCommand;
use Tey\Mod\Commands\ObserverCommand;
use Tey\Mod\Commands\PolicyCommand;
use Tey\Mod\Commands\ProviderCommand;
use Tey\Mod\Commands\RequestCommand;
use Tey\Mod\Commands\ResourceCommand;
use Tey\Mod\Commands\RuleCommand;
use Tey\Mod\Commands\ScopeCommand;
use Tey\Mod\Commands\SeederCommand;
use Tey\Mod\Commands\TemplateCommand;
use Tey\Mod\Commands\TestCommand;
use Tey\Mod\Commands\TraitCommand;
use Tey\Mod\Exceptions\InvalidGeneratorSetup;
use Tey\Mod\Layout\CompiledLayout;

/**
 * Which adapter generates which kind. Kinds stay preset data: the registry is
 * a keyed map the host can extend through `mod.generators` or, from a
 * package's service provider, Mod::generators()->use(); a class-shaped kind
 * nobody claims gets the declarative generic generator.
 */
final class GeneratorRegistry
{
    /** @var array<string, class-string<GeneratorAdapter&Command>> */
    public const DEFAULTS = [
        'cast' => CastCommand::class,
        'channel' => ChannelCommand::class,
        'class' => ClassCommand::class,
        'enum' => EnumCommand::class,
        'exception' => ExceptionCommand::class,
        'interface' => InterfaceCommand::class,
        'job' => JobCommand::class,
        'job-middleware' => JobMiddlewareCommand::class,
        'mail' => MailCommand::class,
        'middleware' => MiddlewareCommand::class,
        'notification' => NotificationCommand::class,
        'observer' => ObserverCommand::class,
        'resource' => ResourceCommand::class,
        'rule' => RuleCommand::class,
        'scope' => ScopeCommand::class,
        'test' => TestCommand::class,
        'trait' => TraitCommand::class,
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

    /** Native command class => placement adapter (including optional framework commands).
     *
     * @var array<string, class-string<GeneratorAdapter&Command>>
     */
    public const NATIVE = [
        ModelMakeCommand::class => ModelCommand::class,
        RequestMakeCommand::class => RequestCommand::class,
        PolicyMakeCommand::class => PolicyCommand::class,
        ProviderMakeCommand::class => ProviderCommand::class,
        ListenerMakeCommand::class => ListenerCommand::class,
        EventMakeCommand::class => EventCommand::class,
        ConsoleMakeCommand::class => ConsoleCommand::class,
        ControllerMakeCommand::class => ControllerCommand::class,
        FactoryMakeCommand::class => FactoryCommand::class,
        SeederMakeCommand::class => SeederCommand::class,
        MigrateMakeCommand::class => MigrationCommand::class,
        CastMakeCommand::class => CastCommand::class,
        ChannelMakeCommand::class => ChannelCommand::class,
        ClassMakeCommand::class => ClassCommand::class,
        ConfigMakeCommand::class => ConfigCommand::class,
        EnumMakeCommand::class => EnumCommand::class,
        ExceptionMakeCommand::class => ExceptionCommand::class,
        InterfaceMakeCommand::class => InterfaceCommand::class,
        JobMakeCommand::class => JobCommand::class,
        JobMiddlewareMakeCommand::class => JobMiddlewareCommand::class,
        MailMakeCommand::class => MailCommand::class,
        MiddlewareMakeCommand::class => MiddlewareCommand::class,
        NotificationMakeCommand::class => NotificationCommand::class,
        ObserverMakeCommand::class => ObserverCommand::class,
        ResourceMakeCommand::class => ResourceCommand::class,
        RuleMakeCommand::class => RuleCommand::class,
        ScopeMakeCommand::class => ScopeCommand::class,
        TestMakeCommand::class => TestCommand::class,
        TraitMakeCommand::class => TraitCommand::class,
    ];

    /** @var array<string, class-string<GeneratorAdapter&Command>> */
    private array $adapters;

    /**
     * @param  array<array-key, mixed>  $overrides  kind id => adapter class (config `mod.generators`)
     */
    public function __construct(array $overrides = [])
    {
        $adapters = self::DEFAULTS;

        if (class_exists(ConfigMakeCommand::class)) {
            $adapters['config'] = ConfigCommand::class;
        }

        $this->adapters = $adapters;

        foreach ($overrides as $kindId => $adapter) {
            if (! is_string($kindId) || ! is_string($adapter)) {
                throw new InvalidGeneratorSetup(sprintf(
                    'Config [mod.generators] must map file type ids to %s commands; [%s] is not one.',
                    GeneratorAdapter::class,
                    is_string($adapter) ? $adapter : get_debug_type($adapter),
                ));
            }

            $this->use($kindId, $adapter, 'Config [mod.generators]');
        }
    }

    /**
     * Generate a kind with this adapter (a subclass of a mod:* command, or any
     * command implementing GeneratorAdapter), replacing the built-in one.
     */
    public function use(string $kindId, string $adapter, string $source = 'Mod::generators()->use()'): self
    {
        if (! is_subclass_of($adapter, GeneratorAdapter::class) || ! is_subclass_of($adapter, Command::class)) {
            throw new InvalidGeneratorSetup(sprintf('%s must map file type ids to %s commands; [%s] is not one.', $source, GeneratorAdapter::class, $adapter));
        }

        $this->adapters[$kindId] = $adapter;

        return $this;
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
    public function commands(CompiledLayout $preset, Container $container): array
    {
        $commands = [];

        foreach ($preset->kinds() as $kind) {
            if ($kind->command === null || ($adapter = (isset($preset->templates()[$kind->id]) ? TemplateCommand::class : $this->adapterFor($kind))) === null) {
                continue;
            }

            if (! $adapter::supports($kind)) {
                throw new InvalidGeneratorSetup(sprintf(
                    'Generator [%s] cannot generate file type [%s] (%s, name %s).',
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
