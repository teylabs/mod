<?php

namespace Tey\Mod\Generation;

use Illuminate\Database\Migrations\MigrationCreator;
use ReflectionClass;

/**
 * The native migration creator, with its date-prefix clock exposed and pinnable.
 *
 * mod:migration reads the timestamp from the native clock, resolves the
 * placement with it, then pins it so the native create() writes exactly the
 * resolved file.
 *
 * @internal the creator mod:migration binds; hosts subclass MigrationCommand, not this.
 */
class ModMigrationCreator extends MigrationCreator
{
    private ?string $pinnedPrefix = null;

    /**
     * The date prefix the native creator would use for a migration in the given directory.
     */
    public function datePrefixFor(string $directory): string
    {
        // Laravel 11 has no per-directory clock; its prefix is plain date().
        if (! (new ReflectionClass(MigrationCreator::class))->hasProperty('currentMigrationPath')) {
            return parent::getDatePrefix();
        }

        $previous = $this->currentMigrationPath;
        $this->currentMigrationPath = $directory;

        try {
            return parent::getDatePrefix();
        } finally {
            $this->currentMigrationPath = $previous;
        }
    }

    /**
     * Run the callback with the date prefix fixed to the given timestamp.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public function pinned(string $prefix, callable $callback): mixed
    {
        $this->pinnedPrefix = $prefix;

        try {
            return $callback();
        } finally {
            $this->pinnedPrefix = null;
        }
    }

    protected function getDatePrefix()
    {
        return $this->pinnedPrefix ?? parent::getDatePrefix();
    }
}
