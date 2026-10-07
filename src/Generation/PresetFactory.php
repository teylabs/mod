<?php

namespace Tey\Mod\Generation;

use Illuminate\Contracts\Container\Container;
use Tey\Mod\Preset\Preset;

/**
 * Builds the application's preset from the `mod.preset` configuration value.
 */
final readonly class PresetFactory
{
    public function __construct(private Container $container) {}

    public function make(mixed $configured): Preset
    {
        if (is_array($configured)) {
            /** @var array<string, mixed> $configured */
            return Preset::fromArray($configured);
        }

        if (is_string($configured) && is_subclass_of($configured, PresetSource::class)) {
            /** @var PresetSource $source */
            $source = $this->container->make($configured);

            return $source->preset();
        }

        throw new InvalidGeneratorSetup(sprintf(
            'Config [mod.preset] must be a preset definition array or the class name of a %s.',
            PresetSource::class,
        ));
    }
}
