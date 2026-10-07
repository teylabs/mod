<?php

namespace Tey\Mod\Placement;

/**
 * A placement dimension declared by the preset (for example "feature" or "module").
 *
 * Dimensions are data: the engine has none of its own and none is mandatory.
 */
final readonly class Dimension
{
    public function __construct(public string $name) {}
}
