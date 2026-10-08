<?php

namespace Tey\Mod\Artifact;

/**
 * @internal
 */
enum NamePolicyKind: string
{
    case AsGiven = 'as-given';
    case Suffix = 'suffix';
    case Fixed = 'fixed';
    case Timestamped = 'timestamped';
}
