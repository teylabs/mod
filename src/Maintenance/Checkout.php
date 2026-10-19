<?php

namespace Tey\Mod\Maintenance;

/** Read-only filesystem observations for a POSIX checkout. @api */
final class Checkout
{
    /**
     * @param  array<string, string>  $inputPaths  Host input identity names mapped to relative files.
     * @param  array<string, string>  $compatibility  Trusted host compatibility results.
     *
     * @api
     */
    public static function inspect(string $root, string $appId, Artifact $baseline, Artifact $target, array $inputPaths, array $compatibility): FilePlan
    {
        // Validate the pure ownership contract before reading any declared path.
        FilePlan::between($appId, $root, $baseline, $target, [], [], $compatibility);
        SafeFilesystem::root($root);
        SafeFilesystem::metadata($root);
        Artifact::validateSelection($inputPaths, false);
        $current = [];
        foreach ($target->files + $baseline->files as $path => $bytes) {
            $observed = SafeFilesystem::read($root, $path);
            if ($observed !== null) {
                $current[$path] = $observed;
            }
        }
        $inputs = [];
        foreach ($inputPaths as $name => $path) {
            $inputs[$name] = Fingerprint::of([$path, SafeFilesystem::read($root, $path)]);
        }

        return FilePlan::between($appId, $root, $baseline, $target, $current, $inputs, $compatibility);
    }
}
