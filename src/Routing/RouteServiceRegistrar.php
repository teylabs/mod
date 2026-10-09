<?php

namespace Tey\Mod\Routing;

use Illuminate\Contracts\Foundation\Application;
use Tey\Mod\Commands\RouteRegistrarCommand;
use Tey\Mod\Commands\RoutesCommand;
use Tey\Mod\Exceptions\InvalidLayout;
use Tey\Mod\Layout\CompiledLayout;

/** @internal Register state for this application; route loading remains explicit. */
final class RouteServiceRegistrar
{
    public static function register(Application $app): void
    {
        if (! $app instanceof \Illuminate\Foundation\Application) {
            return;
        }
        $baseline = get_included_files();
        $app->singleton(ModRoutes::class, fn (): ModRoutes => new ModRoutes($app, $baseline));
        \Illuminate\Console\Application::starting(function (\Illuminate\Console\Application $artisan) use ($app): void {
            if ($artisan->getLaravel() !== $app || ! $app->make('config')->get('mod.commands', true)) {
                return;
            }
            try {
                $layout = $app->make(CompiledLayout::class);
            } catch (\Throwable $exception) {
                if (! $exception instanceof InvalidLayout) {
                    throw $exception;
                }

                return;
            }
            if ($layout->commandsEnabled()) {
                $artisan->resolveCommands([RoutesCommand::class, RouteRegistrarCommand::class]);
            }
        });
    }
}
