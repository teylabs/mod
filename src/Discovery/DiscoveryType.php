<?php

namespace Tey\Mod\Discovery;

/**
 * What the framework does with a discovered artifact. Eligibility is decided
 * by this type through the class's real ancestry or methods, never by name.
 */
enum DiscoveryType: string
{
    case Provider = 'provider';
    case Command = 'command';
    case Listener = 'listener';

    /** An event subscriber: a class with a public subscribe() taking one parameter (Event::subscribe). */
    case Subscriber = 'subscriber';

    /** Directories of a file kind that hold at least one file (migration paths, say). */
    case Directory = 'directory';

    public function isClassType(): bool
    {
        return $this !== self::Directory;
    }
}
