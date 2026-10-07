<?php

namespace Tey\Mod\Tests\Support;

use Closure;
use Illuminate\Support\ServiceProvider;

/**
 * Stands in for an application's own AppServiceProvider: registered after
 * mod, its boot() runs whatever a test bound under BOOT (typically
 * Mod::layout() calls), exactly where an application would put them.
 */
final class AppServiceProvider extends ServiceProvider
{
    public const BOOT = 'mod.tests.app-service-provider.boot';

    public function boot(): void
    {
        if (! $this->app->bound(self::BOOT)) {
            return;
        }

        $boot = $this->app->make(self::BOOT);

        if ($boot instanceof Closure) {
            $boot();
        }
    }
}
