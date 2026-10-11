<?php

namespace Tey\Mod\Database;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Migrations\Migrator;

/** @internal Keep Laravel's commands and nested calls on the same path selection. */
final class MigrationConnections
{
    public static function register(Application $app): void
    {
        $app->extend('migrator', static function (Migrator $native, Application $app): Migrator {
            if (get_class($native) !== Migrator::class) {
                if ($app->make(GroupConnections::class)->declarations() !== []) {
                    throw new \LogicException('Group connections need the native Laravel migrator; the application has replaced it.');
                }

                return $native;
            }
            $migrator = new ConnectionMigrator($app->make('migration.repository'), $app->make('db'), $app->make('files'), $app->make('events'));
            $migrator->groups = $app->make(GroupConnections::class);
            $migrator->applicationPath = $app->databasePath('migrations');
            foreach ($native->paths() as $path) {
                $migrator->path($path);
            }

            return $migrator;
        });
    }
}
