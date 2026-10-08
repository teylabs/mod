<?php

namespace Tey\Mod\Commands;

use Illuminate\Foundation\Console\InterfaceMakeCommand;
use Tey\Mod\Commands\Concerns\PlacesGeneratedClass;
use Tey\Mod\Generation\GeneratorAdapter;

/**
 * Native make:interface generator, placed by the preset.
 */
class InterfaceCommand extends InterfaceMakeCommand implements GeneratorAdapter
{
    use PlacesGeneratedClass;
}
