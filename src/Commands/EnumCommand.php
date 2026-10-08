<?php

namespace Tey\Mod\Commands;

use Illuminate\Foundation\Console\EnumMakeCommand;
use Tey\Mod\Commands\Concerns\PlacesGeneratedClass;
use Tey\Mod\Generation\GeneratorAdapter;

/**
 * Native make:enum generator, placed by the preset.
 */
class EnumCommand extends EnumMakeCommand implements GeneratorAdapter
{
    use PlacesGeneratedClass;
}
