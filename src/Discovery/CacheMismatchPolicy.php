<?php

namespace Tey\Mod\Discovery;

/**
 * What to do when a discovery cache file exists but cannot be trusted
 * (unknown schema, other preset or definitions, malformed).
 */
enum CacheMismatchPolicy: string
{
    /** Throw InvalidDiscoveryCache naming the problem and the fix. */
    case Fail = 'fail';

    /** Ignore the file and scan cold, in memory. The file is never rewritten silently. */
    case Scan = 'scan';
}
