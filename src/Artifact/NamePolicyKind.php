<?php

namespace Tey\Mod\Artifact;

enum NamePolicyKind: string
{
    case AsGiven = 'as-given';
    case Suffix = 'suffix';
    case Fixed = 'fixed';
    case Timestamped = 'timestamped';
}
