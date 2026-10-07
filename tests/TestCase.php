<?php

namespace Tey\Mod\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Tey\Mod\ModServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            ModServiceProvider::class,
        ];
    }
}
