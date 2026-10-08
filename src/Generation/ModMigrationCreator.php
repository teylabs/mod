<?php

namespace Tey\Mod\Generation;

use Illuminate\Database\Migrations\MigrationCreator;
use ReflectionProperty;

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
        // Laravel 12 uses a plain date prefix; newer creators track the directory.
        if (! property_exists(MigrationCreator::class, 'currentMigrationPath')) {
            return parent::getDatePrefix();
        }

        // Read the native per-directory clock without changing its state.
        $property = new ReflectionProperty(MigrationCreator::class, 'currentMigrationPath');
        $previous = $property->getValue($this);
        $property->setValue($this, $directory);

        try {
            return parent::getDatePrefix();
        } finally {
            $property->setValue($this, $previous);
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
        $this->pin($prefix);

        try {
            return $callback();
        } finally {
            $this->pin(null);
        }
    }

    /**
     * Fix the date prefix until released with null (a command that plans
     * after the native handle() started pins here and releases in its finally).
     */
    public function pin(?string $prefix): void
    {
        $this->pinnedPrefix = $prefix;
    }

    protected function getDatePrefix()
    {
        return $this->pinnedPrefix ?? parent::getDatePrefix();
    }
}
