<?php

namespace Tey\Mod\Commands;

use Illuminate\Foundation\Console\TraitMakeCommand;
use Tey\Mod\Commands\Concerns\PlacesGeneratedClass;
use Tey\Mod\Generation\GeneratorAdapter;

/**
 * Native make:trait generator, placed by the preset.
 */
class TraitCommand extends TraitMakeCommand implements GeneratorAdapter
{
    use PlacesGeneratedClass;
}
