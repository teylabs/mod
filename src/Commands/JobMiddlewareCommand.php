<?php

namespace Tey\Mod\Commands;

use Illuminate\Foundation\Console\JobMiddlewareMakeCommand;
use Tey\Mod\Commands\Concerns\PlacesGeneratedClass;
use Tey\Mod\Generation\GeneratorAdapter;

/**
 * Native make:job-middleware generator, placed by the preset.
 */
class JobMiddlewareCommand extends JobMiddlewareMakeCommand implements GeneratorAdapter
{
    use PlacesGeneratedClass;
}
