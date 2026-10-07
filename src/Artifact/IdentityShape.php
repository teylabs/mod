<?php

namespace Tey\Mod\Artifact;

/**
 * Whether an artifact is a PHP class or a plain file (a migration, a routes file).
 */
enum IdentityShape: string
{
    case PhpClass = 'class';
    case File = 'file';
}
