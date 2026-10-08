<?php

namespace Tey\Mod\Facades;

use Illuminate\Support\Facades\Facade;
use Tey\Mod\Generation\GeneratorRegistry;
use Tey\Mod\Generation\StubRegistry;
use Tey\Mod\Layout\Layout;
use Tey\Mod\ModManager;

/**
 * @method static Layout layout(string $name) define a layout, or extend a built-in or defined one
 * @method static bool has(string $name)
 * @method static list<string> names()
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
