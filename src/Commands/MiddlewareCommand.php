<?php

namespace Tey\Mod\Commands;

use Illuminate\Routing\Console\MiddlewareMakeCommand;
use Tey\Mod\Commands\Concerns\PlacesGeneratedClass;
use Tey\Mod\Generation\GeneratorAdapter;

/**
 * Native make:middleware generator, placed by the preset.
 *
 * @api
 */
class MiddlewareCommand extends MiddlewareMakeCommand implements GeneratorAdapter
{
    use PlacesGeneratedClass;
}
