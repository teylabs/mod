<?php

namespace Tey\Mod\Commands;

use Illuminate\Foundation\Console\RequestMakeCommand;
use Tey\Mod\Commands\Concerns\PlacesGeneratedClass;
use Tey\Mod\Generation\GeneratorAdapter;

/**
 * Native make:* request generator, placed by the preset.
 */
class RequestCommand extends RequestMakeCommand implements GeneratorAdapter
{
    use PlacesGeneratedClass;
}
