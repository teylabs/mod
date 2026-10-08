<?php

namespace Tey\Mod\Commands;

use Illuminate\Foundation\Console\NotificationMakeCommand;
use Tey\Mod\Commands\Concerns\PlacesGeneratedClass;
use Tey\Mod\Generation\GeneratorAdapter;

/**
 * Native make:notification generator, placed by the preset.
 */
class NotificationCommand extends NotificationMakeCommand implements GeneratorAdapter
{
    use PlacesGeneratedClass;
}
