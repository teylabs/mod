<?php

namespace Tey\Mod\Facades;

use Illuminate\Support\Facades\Facade;
use Tey\Mod\Layout\Layout;
use Tey\Mod\Layout\LayoutRegistry;

/**
 * @method static Layout layout(string $name) define a layout, or extend a built-in or defined one
 * @method static bool has(string $name)
 * @method static list<string> names()
 *
 * @see LayoutRegistry
 */
final class Mod extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return LayoutRegistry::class;
    }
}
