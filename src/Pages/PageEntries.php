<?php

namespace Tey\Mod\Pages;

use Illuminate\Support\Str;
use InvalidArgumentException;

/** @internal The inclusion policy shared by page globs and identities. */
final class PageEntries
{
    /** @return ($entries is null ? null : list<string>) */
    public static function validate(mixed $entries, string $owner): ?array
    {
        if ($entries === null) {
            return null;
        }
        if (! is_array($entries) || ! array_is_list($entries)) {
            throw new InvalidArgumentException('Page entries at ['.$owner.'] must be a list of file stems.');
        }
        foreach ($entries as $entry) {
            if (! is_string($entry) || preg_match('/^[A-Za-z0-9_*\-]+$/D', $entry) !== 1) {
                throw new InvalidArgumentException('Page entries at ['.$owner.'] must be file stems with only letters, numbers, underscores, hyphens or *.');
            }
        }

        return $entries;
    }

    /** @param list<string>|null $entries */
    public static function matches(string $path, ?array $entries): bool
    {
        return $entries === null || Str::is($entries, pathinfo($path, PATHINFO_FILENAME));
    }

    /**
     * @param  list<string>|null  $entries
     * @return list<string>
     */
    public static function globs(string $directory, string $extension, ?array $entries): array
    {
        return array_map(static fn (string $stem): string => $directory.'/**/'.$stem.'.'.$extension, $entries ?? ['*']);
    }
}
