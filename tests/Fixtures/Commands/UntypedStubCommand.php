<?php

namespace Tey\Mod\Tests\Fixtures\Commands;

use Tey\Mod\Commands\ClassCommand;

/**
 * An application's subclass overriding getStub() as Laravel declares it: untyped.
 */
class UntypedStubCommand extends ClassCommand
{
    /**
     * @return string
     */
    protected function getStub()
    {
        return parent::getStub();
    }
}
