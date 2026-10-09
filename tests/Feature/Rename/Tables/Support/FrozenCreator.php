<?php

namespace Tey\Mod\Tests\Feature\Rename\Tables\Support;

use Tey\Mod\Generation\ModMigrationCreator;

/** A clock fixture usable on Laravel 12 (date()) and Laravel 13 (Date). */
final class FrozenCreator extends ModMigrationCreator
{
    public function datePrefixFor(string $directory): string
    {
        return '2026_10_09_163000';
    }

    public function create($name, $path, $table = null, $create = false)
    {
        throw new \LogicException('Candidate generation must never call create().');
    }
}
