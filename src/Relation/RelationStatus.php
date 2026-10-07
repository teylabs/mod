<?php

namespace Tey\Mod\Relation;

enum RelationStatus: string
{
    case Resolved = 'resolved';
    case Unresolved = 'unresolved';
}
