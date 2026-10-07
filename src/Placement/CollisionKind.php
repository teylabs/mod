<?php

namespace Tey\Mod\Placement;

enum CollisionKind: string
{
    case Path = 'path';
    case ClassName = 'class';
}
