<?php

namespace Tey\Mod\Commands;

use Illuminate\Foundation\Console\ObserverMakeCommand;
use Tey\Mod\Commands\Concerns\PlacesGeneratedClass;
use Tey\Mod\Generation\GeneratorAdapter;

/**
 * Native make:observer generator, placed by the preset.
 */
class ObserverCommand extends ObserverMakeCommand implements GeneratorAdapter
{
    use PlacesGeneratedClass;
}
