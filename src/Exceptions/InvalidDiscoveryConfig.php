<?php

namespace Tey\Mod\Exceptions;

/** @api */
final class InvalidDiscoveryConfig extends ModException
{
    public static function because(string $key, string $problem): self
    {
        return new self("Invalid discovery configuration [mod.discovery.{$key}]: {$problem}.");
    }
}
