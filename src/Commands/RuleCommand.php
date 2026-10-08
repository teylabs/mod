<?php

namespace Tey\Mod\Commands;

use Illuminate\Foundation\Console\RuleMakeCommand;
use Tey\Mod\Commands\Concerns\PlacesGeneratedClass;
use Tey\Mod\Generation\GeneratorAdapter;

/**
 * Native make:rule generator, placed by the preset.
 */
class RuleCommand extends RuleMakeCommand implements GeneratorAdapter
{
    use PlacesGeneratedClass;
}
