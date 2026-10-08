<?php

namespace Tey\Mod\Commands;

use Illuminate\Foundation\Console\MailMakeCommand;
use Tey\Mod\Commands\Concerns\PlacesGeneratedClass;
use Tey\Mod\Generation\GeneratorAdapter;

/**
 * Native make:mail generator, placed by the preset.
 */
class MailCommand extends MailMakeCommand implements GeneratorAdapter
{
    use PlacesGeneratedClass;
}
