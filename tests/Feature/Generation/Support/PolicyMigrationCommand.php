<?php

namespace Tey\Mod\Tests\Feature\Generation\Support;

use Tey\Mod\Commands\MigrationCommand;
use Tey\Mod\Generation\CollisionPolicy;

final class PolicyMigrationCommand extends MigrationCommand
{
    public CollisionPolicy $policy = CollisionPolicy::Native;

    protected function collisionPolicy(): CollisionPolicy
    {
        return $this->policy;
    }
}
