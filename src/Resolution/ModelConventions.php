<?php

namespace Tey\Mod\Resolution;

use Closure;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use ReflectionFunction;
use ReflectionProperty;
use Tey\Mod\Discovery\Discovery;
use Tey\Mod\Discovery\DiscoveryType;
use Tey\Mod\Layout\CompiledLayout;
use WeakReference;

/**
 * Model::factory() for models placed by any layout, with no newFactory()
 * method: Laravel's factory name resolver (Factory::guessFactoryNamesUsing)
 * asks the layout first, then whatever resolved factory names before.
 *
 * The layout's answer is the model's `factory` relation target when that
 * class exists: from the discovery inventory (the cache, when built), else
 * the reverse mapper for classes the inventory does not hold. Otherwise the
 * resolver registered before mod answers, or, when there was none, Laravel's
 * own naming. A resolver an application registers after mod replaces this
 * one, as Laravel does for any second resolver.
 *
 * One instance per application, holding it weakly; the factory resolver
 * itself is process-wide, so an earlier application's instance is unwrapped
 * rather than chained.
 *
 * @internal registered by ModServiceProvider when discovery and `mod.discovery.factories` are on.
 */
final class ModelConventions
{
    /** @var array<string, class-string<Factory<Model>>|null> model class => layout factory, per class once */
    private array $resolved = [];

    /** @var array<string, string>|null model class => factory, from the inventory */
    private ?array $inventory = null;

    private ?ModelRelations $relations = null;

    /**
     * @param  WeakReference<Application>  $app
     * @param  (Closure(class-string<Model>): class-string<Factory<Model>>)|null  $previous  the resolver registered before mod
     */
    private function __construct(
        private readonly WeakReference $app,
        public readonly ?Closure $previous,
    ) {}

    /**
     * Install the resolver for an application, delegating to the one already registered.
     */
    public static function register(Application $app): self
    {
        $previous = self::current();

        while (($earlier = self::of($previous)) !== null) {
            $previous = $earlier->previous;
        }

        $conventions = new self(WeakReference::create($app), $previous === null ? null : Closure::fromCallable($previous));
        Factory::guessFactoryNamesUsing($conventions->__invoke(...));

        return $conventions;
    }

    /**
     * Whether the factory name resolver Laravel holds now is this application's.
     */
    public static function installedFor(Application $app): bool
    {
        return self::of(self::current())?->app->get() === $app;
    }

    /**
     * The instance behind a registered resolver, when it is one of these.
     */
    public static function of(?callable $resolver): ?self
    {
        if (! $resolver instanceof Closure) {
            return null;
        }

        $owner = (new ReflectionFunction($resolver))->getClosureThis();

        return $owner instanceof self ? $owner : null;
    }

    /**
     * The factory name resolver Laravel holds now (Factory::$factoryNameResolver), or null.
     */
    public static function current(): ?callable
    {
        $resolver = self::resolver()->getValue();

        return is_callable($resolver) ? $resolver : null;
    }

    /**
     * @param  class-string<Model>  $model
     * @return class-string<Factory<Model>>
     */
    public function __invoke(string $model): string
    {
        return $this->layoutFactory($model)
            ?? ($this->previous !== null ? ($this->previous)($model) : self::laravelDefault($model));
    }

    /**
     * The factory the layout relates to this model, when it exists.
     *
     * @return class-string<Factory<Model>>|null
     */
    public function layoutFactory(string $model): ?string
    {
        if (array_key_exists($model, $this->resolved)) {
            return $this->resolved[$model];
        }

        $app = $this->app->get();

        if ($app === null) {
            return null;
        }

        $this->inventory ??= $app->bound(Discovery::class)
            ? $app->make(Discovery::class)->inventory()->pairs(DiscoveryType::Factory)
            : [];

        $this->relations ??= new ModelRelations($app->make(CompiledLayout::class));
        $factory = $this->inventory[$model] ?? $this->relations->targetOfClass($model, DiscoveryType::Factory->value);

        return $this->resolved[$model] = $factory !== null && is_a($factory, Factory::class, true) ? $factory : null;
    }

    /**
     * Laravel's own factory name for a model: Factory::resolveFactoryName()
     * with no resolver registered, computed by Laravel itself.
     *
     * @param  class-string<Model>  $model
     * @return class-string<Factory<Model>>
     */
    public static function laravelDefault(string $model): string
    {
        $property = self::resolver();
        $registered = $property->getValue();
        $property->setValue(null, null);

        try {
            return Factory::resolveFactoryName($model);
        } finally {
            $property->setValue(null, $registered);
        }
    }

    private static function resolver(): ReflectionProperty
    {
        return new ReflectionProperty(Factory::class, 'factoryNameResolver');
    }
}
