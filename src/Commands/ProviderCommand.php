<?php

namespace Tey\Mod\Commands;

use Illuminate\Foundation\Console\ProviderMakeCommand;
use Tey\Mod\Commands\Concerns\PlacesGeneratedClass;
use Tey\Mod\Generation\GeneratorAdapter;

/**
 * Native make:* service provider generator, placed by the preset.
 *
 * @api
 */
class ProviderCommand extends ProviderMakeCommand implements GeneratorAdapter
{
    use PlacesGeneratedClass;
}
