<?php

namespace Tey\Mod\Reverse;

enum ReverseOutcome: string
{
    case Matched = 'matched';
    case NotOwned = 'not-owned';
    case Ambiguous = 'ambiguous';
    case Unsupported = 'unsupported';
}
