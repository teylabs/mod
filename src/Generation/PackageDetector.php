<?php

namespace Tey\Mod\Generation;

/**
 * @internal answers which optional packages and classes an application has, for stub variants.
 */
interface PackageDetector
{
    /** Whether a Composer package is installed (`vendor/name`). */
    public function isInstalled(string $package): bool;

    /** Whether a class can be loaded. */
    public function classExists(string $class): bool;
}
