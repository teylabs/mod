<?php

namespace Tey\Mod\Relation;

/**
 * @internal
 */
enum RelationStatus: string
{
    case Resolved = 'resolved';
    case Unresolved = 'unresolved';
}
