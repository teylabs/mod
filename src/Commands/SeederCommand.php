<?php

namespace Tey\Mod\Commands;

use Illuminate\Database\Console\Seeds\SeederMakeCommand;
use Tey\Mod\Commands\Concerns\PlacesGeneratedClass;
use Tey\Mod\Generation\GeneratorAdapter;

/**
 * Native make:* seeder generator, placed by the preset.
 *
 * @api
 */
class SeederCommand extends SeederMakeCommand implements GeneratorAdapter
{
    use PlacesGeneratedClass;
}
