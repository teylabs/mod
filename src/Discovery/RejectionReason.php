<?php

namespace Tey\Mod\Discovery;

enum RejectionReason: string
{
    /** No declared rule owns the file, or it lies in an excluded root. */
    case NotOwned = 'not-owned';

    /** Several rules recognise the file and none has priority. */
    case Ambiguous = 'ambiguous';

    /** A callback rule covers the file, so ownership cannot be decided. */
    case Unsupported = 'unsupported';

    /** Owned by a discovered kind, but the class is not what the type requires. */
    case Ineligible = 'ineligible';
}
