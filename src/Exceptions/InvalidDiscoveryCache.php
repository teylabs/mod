<?php

namespace Tey\Mod\Exceptions;

/** @api */
final class InvalidDiscoveryCache extends ModException
{
    public static function because(string $path, string $problem): self
    {
        return new self(sprintf(
            'The discovery cache [%s] cannot be used: %s. Rebuild it with `php artisan mod:cache` or remove it with `php artisan mod:clear`.',
            $path,
            $problem,
        ));
    }
}
