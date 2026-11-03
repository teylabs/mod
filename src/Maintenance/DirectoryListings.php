<?php

declare(strict_types=1);

namespace Tey\Mod\Maintenance;

/**
 * Directory listings reused across one read-only pass over many declared paths, so each parent is listed once for the case-alias
 * check rather than once per path under it. A pass creates its own and drops it when done: the listings show the checkout as it was
 * when each directory was first listed, so nothing that writes may use one. Every link, type and existence check still runs per path.
 *
 * @internal
 */
final class DirectoryListings
{
    /** @var array<string, list<string>|false> */
    private array $entries = [];

    /** @return list<string>|false */
    public function of(string $directory): array|false
    {
        return $this->entries[$directory] ??= scandir($directory);
    }
}
