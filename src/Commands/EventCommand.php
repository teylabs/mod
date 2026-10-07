<?php

namespace Tey\Mod\Commands;

use Illuminate\Foundation\Console\EventMakeCommand;
use Tey\Mod\Commands\Concerns\PlacesGeneratedClass;
use Tey\Mod\Generation\GeneratorAdapter;

/**
 * Native make:* event generator, placed by the preset.
 */
class EventCommand extends EventMakeCommand implements GeneratorAdapter
{
    use PlacesGeneratedClass;
}
