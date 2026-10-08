<?php

namespace Tey\Mod\Commands;

use Illuminate\Foundation\Console\ResourceMakeCommand;
use Tey\Mod\Commands\Concerns\PlacesGeneratedClass;
use Tey\Mod\Generation\GeneratorAdapter;

/**
 * Native make:resource generator, placed by the preset.
 */
class ResourceCommand extends ResourceMakeCommand implements GeneratorAdapter
{
    use PlacesGeneratedClass;
}
