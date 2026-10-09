<?php

namespace Tey\Mod\Commands;

use Illuminate\Foundation\Console\ExceptionMakeCommand;
use Tey\Mod\Commands\Concerns\PlacesGeneratedClass;
use Tey\Mod\Generation\GeneratorAdapter;

/**
 * Native make:exception generator, placed by the preset.
 *
 * @api
 */
class ExceptionCommand extends ExceptionMakeCommand implements GeneratorAdapter
{
    use PlacesGeneratedClass;
}
