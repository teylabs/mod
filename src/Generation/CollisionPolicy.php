<?php

namespace Tey\Mod\Generation;

/**
 * What a generator does when the plan collides with what exists.
 *
 * @api
 */
enum CollisionPolicy: string
{
    /** Diagnose every collision of the whole plan before writing and refuse (mod's default). */
    case Refuse = 'refuse';

    /** Leave it to the native generator: its own "already exists" check and --force semantics decide. */
    case Native = 'native';
}
