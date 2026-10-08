<?php

namespace Tey\Mod\Tests\Fixtures;

use Tey\Mod\Layout\CompiledLayout;

/**
 * The five proof layouts as provisional preset definitions. Fresh instances
 * every call: nothing is cached or shared between tests.
 */
final class Layouts
{
    public const NAMES = ['ordinary', 'feature-first', 'vertical-slices', 'type-first', 'modules'];

    public static function ordinary(): CompiledLayout
    {
        return CompiledLayout::fromArray(self::definition('ordinary'));
    }

    public static function featureFirst(): CompiledLayout
    {
        return CompiledLayout::fromArray(self::definition('feature-first'));
    }

    public static function verticalSlices(): CompiledLayout
    {
        return CompiledLayout::fromArray(self::definition('vertical-slices'));
    }

    public static function typeFirst(): CompiledLayout
    {
        return CompiledLayout::fromArray(self::definition('type-first'));
    }

    public static function modules(): CompiledLayout
    {
        return CompiledLayout::fromArray(self::definition('modules'));
    }

    public static function named(string $name): CompiledLayout
    {
        return CompiledLayout::fromArray(self::definition($name));
    }

    /**
     * The raw definition, for tests that vary a layout.
     *
     * @return array<string, mixed>
     */
    public static function definition(string $name): array
    {
        /** @var array<string, mixed> $definition */
        $definition = require __DIR__.'/Layouts/'.$name.'.php';

        return $definition;
    }
}
