<?php

namespace Tey\Mod\Tests;

use Closure;
use Illuminate\Contracts\Foundation\Application;
use Orchestra\Testbench\TestCase as Orchestra;
use Tey\Mod\ModServiceProvider;
use Tey\Mod\Tests\Support\AppServiceProvider;

abstract class TestCase extends Orchestra
{
    /** @var (Closure(Application): void)|null */
    private ?Closure $bootUsing = null;

    protected function getPackageProviders($app): array
    {
        return [
            ModServiceProvider::class,
            AppServiceProvider::class,
        ];
    }

    /**
     * Boot a fresh application, configured by the callback before any provider registers.
     *
     * @param  Closure(Application): void  $callback
     */
    public function bootApplicationUsing(Closure $callback): Application
    {
        $this->bootUsing = $callback;

        try {
            $this->refreshApplication();
        } finally {
            $this->bootUsing = null;
        }

        return $this->app ?? throw new \RuntimeException('Testbench did not create an application.');
    }

    /**
     * Runs after configuration loads and before providers register.
     *
     * Discovery is off unless a test turns it on: the discovery suites drive
     * DiscoveryRegistrar themselves, and the provider registers it only when enabled.
     *
     * @param  \Illuminate\Foundation\Application  $app
     */
    protected function resolveApplicationConfiguration($app)
    {
        parent::resolveApplicationConfiguration($app);

        $app->make('config')->set('mod.discovery.enabled', false);

        if ($this->bootUsing !== null) {
            ($this->bootUsing)($app);
        }
    }
}
