<?php

namespace Tey\Mod\Commands;

use Illuminate\Foundation\Console\JobMakeCommand;
use Tey\Mod\Commands\Concerns\PlacesGeneratedClass;
use Tey\Mod\Generation\GeneratorAdapter;

/**
 * Native make:job generator, placed by the preset.
 *
 * @api
 */
class JobCommand extends JobMakeCommand implements GeneratorAdapter
{
    use PlacesGeneratedClass;
}
