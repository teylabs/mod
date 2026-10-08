<?php

namespace Tey\Mod\Commands;

use Illuminate\Foundation\Console\ClassMakeCommand;
use Tey\Mod\Commands\Concerns\PlacesGeneratedClass;
use Tey\Mod\Generation\GeneratorAdapter;

/**
 * Native make:class generator, placed by the preset.
 */
class ClassCommand extends ClassMakeCommand implements GeneratorAdapter
{
    use PlacesGeneratedClass;
}
