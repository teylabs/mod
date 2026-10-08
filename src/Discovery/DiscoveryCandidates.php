<?php

namespace Tey\Mod\Discovery;

use Closure;
use Tey\Mod\Layout\CompiledRoot;

/**
 * The candidate-file source a package set with Mod::discoverUsing(), read
 * when discovery registers.
 *
 * @internal
 */
final class DiscoveryCandidates
{
    /** @var (Closure(CompiledRoot, string, DiscoveryDefinition): iterable<string>)|null */
    public ?Closure $using = null;
}
