<?php

namespace Tey\Mod\Placement;

/**
 * A placement dimension declared by the preset (for example "feature" or "module").
 *
 * Dimensions are data: the engine has none of its own and none is mandatory.
 * A multi-segment dimension (`{name+}` in every rule that reads it) holds a
 * '/'-joined chain of folders, e.g. "Billing/Invoicing".
 */
final readonly class Dimension
{
    public function __construct(
        public string $name,
        public bool $multi = false,
    ) {}
}
