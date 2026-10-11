<?php

namespace Tey\Mod\Database;

use Illuminate\Database\Migrations\Migrator;
use Tey\Mod\Support\Path;

/** @internal Laravel's migrator with connection-aware discovered paths. */
final class ConnectionMigrator extends Migrator
{
    public GroupConnections $groups;

    public string $applicationPath;

    /** @return array<string> */
    public function paths()
    {
        $paths = $this->groups->paths($this->connection);
        if ($paths === null || $this->groups->isDefault($this->connection)) {
            return array_values(array_unique([...parent::paths(), ...($paths ?? [])]));
        }

        return $paths;
    }

    /** @param string|array<string> $paths
     * @return array<string, string>
     */
    public function getMigrationFiles($paths)
    {
        if (! $this->groups->isDefault($this->connection) && $this->groups->paths($this->connection) !== null) {
            $paths = array_values(array_filter((array) $paths, fn (string $path): bool => ! Path::same($path, $this->applicationPath)));
        }

        return parent::getMigrationFiles($paths);
    }
}
