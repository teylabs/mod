<?php

namespace Tey\Mod\Placement;

/**
 * @internal
 */
enum CollisionKind: string
{
    case Path = 'path';
    case ClassName = 'class';
}
