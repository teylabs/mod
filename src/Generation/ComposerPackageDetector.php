<?php

namespace Tey\Mod\Generation;

use Composer\InstalledVersions;

/**
 * @internal the application's installed Composer packages and autoloadable classes, read at generation time.
 */
final class ComposerPackageDetector implements PackageDetector
{
    public function isInstalled(string $package): bool
    {
        return class_exists(InstalledVersions::class) && InstalledVersions::isInstalled($package);
    }

    public function classExists(string $class): bool
    {
        return class_exists($class);
    }
}
