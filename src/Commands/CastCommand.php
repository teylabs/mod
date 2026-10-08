<?php

namespace Tey\Mod\Commands;

use Illuminate\Foundation\Console\CastMakeCommand;
use Tey\Mod\Commands\Concerns\PlacesGeneratedClass;
use Tey\Mod\Generation\GeneratorAdapter;

/**
 * Native make:cast generator, placed by the preset.
 */
class CastCommand extends CastMakeCommand implements GeneratorAdapter
{
    use PlacesGeneratedClass;
}
