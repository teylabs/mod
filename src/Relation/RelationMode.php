<?php

namespace Tey\Mod\Relation;

/**
 * What a generator does with a related artifact.
 */
enum RelationMode: string
{
    /** Generate the target when it is missing. */
    case Generate = 'generate';

    /** Only compute and reference the target's identity. */
    case Reference = 'reference';

    /** Declared for navigation only; never acted on. */
    case None = 'none';
}
