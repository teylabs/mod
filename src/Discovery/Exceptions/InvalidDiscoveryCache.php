<?php

namespace Tey\Mod\Discovery\Exceptions;

use Tey\Mod\Exceptions\ModException;

final class InvalidDiscoveryCache extends ModException
{
    public static function because(string $path, string $problem): self
    {
        return new self(sprintf(
            'The discovery cache [%s] cannot be used: %s. Rebuild it with `php artisan mod:discovery-cache` or remove it with `php artisan mod:discovery-clear`.',
            $path,
            $problem,
        ));
    }
}
