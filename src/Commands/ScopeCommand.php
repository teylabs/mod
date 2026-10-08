<?php

namespace Tey\Mod\Commands;

use Illuminate\Foundation\Console\ScopeMakeCommand;
use Tey\Mod\Commands\Concerns\PlacesGeneratedClass;
use Tey\Mod\Generation\GeneratorAdapter;

/**
 * Native make:scope generator, placed by the preset.
 */
class ScopeCommand extends ScopeMakeCommand implements GeneratorAdapter
{
    use PlacesGeneratedClass;
}
