<?php

namespace Tey\Mod\Generation;

use Tey\Mod\Artifact\ArtifactKind;
use Tey\Mod\Preset\Preset;

/**
 * A mod:* command that generates one preset kind.
 *
 * Implemented by thin subclasses of Laravel's own make:* commands, so stubs,
 * options and prompts stay native; the adapter only places the output.
 */
interface GeneratorAdapter
{
    /** Whether this adapter can generate artifacts of the given kind's shape and naming. */
    public static function supports(ArtifactKind $kind): bool;

    /** Bind the command to a kind: takes the kind's command name and adds --in. */
    public function forKind(Preset $preset, ArtifactKind $kind): static;
}
