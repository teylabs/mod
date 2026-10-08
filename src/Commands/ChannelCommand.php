<?php

namespace Tey\Mod\Commands;

use Illuminate\Foundation\Console\ChannelMakeCommand;
use Tey\Mod\Commands\Concerns\PlacesGeneratedClass;
use Tey\Mod\Generation\GeneratorAdapter;

/**
 * Native make:channel generator, placed by the preset.
 */
class ChannelCommand extends ChannelMakeCommand implements GeneratorAdapter
{
    use PlacesGeneratedClass;
}
