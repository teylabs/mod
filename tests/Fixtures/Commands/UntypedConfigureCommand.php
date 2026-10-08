<?php

namespace Tey\Mod\Tests\Fixtures\Commands;

use Tey\Mod\Commands\ClassCommand;

/**
 * An application's subclass overriding configure() as Symfony Console 7 declares it: untyped.
 */
class UntypedConfigureCommand extends ClassCommand
{
    /**
     * @return void
     */
    protected function configure()
    {
        parent::configure();
    }
}
