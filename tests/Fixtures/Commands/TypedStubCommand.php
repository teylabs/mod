<?php

namespace Tey\Mod\Tests\Fixtures\Commands;

use Tey\Mod\Commands\ClassCommand;

/**
 * An application's subclass overriding getStub() with a return type, narrowing Laravel's.
 */
class TypedStubCommand extends ClassCommand
{
    protected function getStub(): string
    {
        return parent::getStub();
    }
}
