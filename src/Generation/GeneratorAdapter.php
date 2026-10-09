<?php

namespace Tey\Mod\Generation;

use Tey\Mod\Artifact\ArtifactKind;
use Tey\Mod\Layout\CompiledLayout;

/**
 * A mod:* command that generates one preset kind.
 *
 * Implemented by thin subclasses of Laravel's own make:* commands, so stubs,
 * options and prompts stay native; the adapter only places the output.
 */
/** @internal The engine protocol; hosts extend the public command adapters. */
interface GeneratorAdapter
{
    /** Whether this adapter can generate artifacts of the given kind's shape and naming. */
    public static function supports(ArtifactKind $kind): bool;

    /** Bind the command to a kind: takes the kind's command name and adds the placement options (--in and one per dimension). */
    public function forKind(CompiledLayout $preset, ArtifactKind $kind): static;
}
