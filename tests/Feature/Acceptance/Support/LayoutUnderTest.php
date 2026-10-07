<?php

namespace Tey\Mod\Tests\Feature\Acceptance\Support;

use Closure;

/**
 * A `mod.layout` name plus the Mod::layout() calls an application would make
 * in its AppServiceProvider::boot() (extra kinds, overrides).
 */
final readonly class LayoutUnderTest
{
    /**
     * @param  (Closure(): mixed)|null  $define
     */
    public function __construct(
        public string $name,
        public ?Closure $define = null,
    ) {}
}
