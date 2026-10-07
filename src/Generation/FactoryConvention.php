<?php

namespace Tey\Mod\Generation;

/**
 * Laravel's own factory <-> model naming convention, to tell when a resolved
 * pair needs an explicit link (newFactory() / $model) because the framework's
 * guess would miss it, e.g. a factory placed in a module.
 *
 * @internal used by the factory and model adapters.
 */
final readonly class FactoryConvention
{
    private const FACTORY_NAMESPACE = 'Database\\Factories\\';

    public function __construct(private string $appNamespace = 'App\\') {}

    /** The factory Laravel resolves for a model (Factory::resolveFactoryName). */
    public function factoryFor(string $model): string
    {
        $relative = str_starts_with($model, $this->appNamespace.'Models\\')
            ? substr($model, strlen($this->appNamespace.'Models\\'))
            : (str_starts_with($model, $this->appNamespace) ? substr($model, strlen($this->appNamespace)) : $model);

        return self::FACTORY_NAMESPACE.$relative.'Factory';
    }

    /** Whether Laravel finds this factory from the model, and the model from the factory, unaided. */
    public function links(string $model, string $factory): bool
    {
        if ($this->factoryFor($model) !== $factory) {
            return false;
        }

        $relative = substr($factory, strlen(self::FACTORY_NAMESPACE), -strlen('Factory'));

        return $model === $this->appNamespace.'Models\\'.$relative;
    }
}
