<?php

namespace Tey\Mod\Facades;

use Illuminate\Support\Facades\Facade;
use Tey\Mod\Generation\GeneratorRegistry;
use Tey\Mod\Generation\StubRegistry;
use Tey\Mod\Layout\CompiledLayout;
use Tey\Mod\Layout\Layout;
use Tey\Mod\ModManager;

/**
 * @method static ModManager scaffold(string $name, \Closure $recipe) declare a scaffold recipe
 * @method static ModManager scaffolds(array<array-key, \Closure|class-string> $recipes) declare keyed recipes or invokable classes
 * @method static Layout layout(string $name) define a layout, or extend a built-in or defined one
 * @method static CompiledLayout current() the active layout, compiled
 * @method static ModManager discoverUsing(\Closure $candidates) supply the files discovery considers: fn (CompiledRoot $root, string $basePath, DiscoveryDefinition $definition): iterable
 * @method static bool hasLayout(string $name) whether a layout of this name is built in or defined
 * @method static list<string> layouts() the names of the built-in and defined layouts
 * @method static StubRegistry stubs() stubs packages register for a kind
 * @method static GeneratorRegistry generators() generator commands by kind
 *
 * @see ModManager
 */
final class Mod extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return ModManager::class;
    }
}
