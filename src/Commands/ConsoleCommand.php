<?php

namespace Tey\Mod\Commands;

use Illuminate\Foundation\Console\ConsoleMakeCommand;
use Tey\Mod\Commands\Concerns\PlacesGeneratedClass;
use Tey\Mod\Generation\GeneratorAdapter;

/**
 * Native make:* Artisan command generator, placed by the preset.
 */
class ConsoleCommand extends ConsoleMakeCommand implements GeneratorAdapter
{
    use PlacesGeneratedClass;
}
