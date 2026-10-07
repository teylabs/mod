<?php

namespace Tey\Mod\Discovery;

/**
 * What the framework does with a discovered class. Eligibility is decided by
 * this type through the class's real ancestry, never by its name.
 */
enum DiscoveryType: string
{
    case Provider = 'provider';
    case Command = 'command';
    case Listener = 'listener';
}
