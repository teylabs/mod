<?php

namespace Tey\Mod\Maintenance;

use InvalidArgumentException;
use RuntimeException;

/** Read-only filesystem observations for a POSIX checkout. @api */
final class Checkout
{
    /**
     * @param  array<string, string>  $inputPaths  Host input identity names mapped to relative files.
     * @param  array<string, string>  $compatibility  Trusted host compatibility results.
     * @param  list<string>  $claim  Reviewed new target paths to adopt when their existing bytes are identical. Never written.
     *
     * @api
     */
    public static function inspect(string $root, string $appId, Artifact $baseline, Artifact $target, array $inputPaths, array $compatibility, array $claim = []): FilePlan
    {
        // Validate the pure ownership contract before reading any declared path.
        FilePlan::between($appId, $root, $baseline, $target, [], [], $compatibility, claim: $claim);
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

        return FilePlan::between($appId, $root, $baseline, $target, $current, $inputs, $compatibility, refusals: self::refusals($root, $appId, $target), claim: $claim);
    }

    /**
     * Other installed lineages decide which target paths this lineage may never own.
     * Host-owned paths are only compared, never read.
     *
     * @return array<string, string>
     */
    private static function refusals(string $root, string $appId, Artifact $target): array
    {
        // Conservative: another lineage's unfinished update may own any path until it completes.
        $pending = Journal::pending($root, $target);
        if ($pending !== null) {
            throw new RuntimeException("Another lineage has an unfinished update (pending:{$pending}).");
        }
        $others = InstalledRecord::lineages($root, $appId, except: $target->name);
        foreach ($target->requires as $name => $version) {
            if (! isset($others[$name]) || ($version !== '*' && $others[$name]->version !== $version)) {
                throw new RuntimeException($version === '*' ? "Required lineage {$name} is not installed." : "Required lineage {$name} is not installed at version {$version}.");
            }
        }
        foreach ($others as $name => $other) {
            $required = $other->requires[$target->name] ?? '*';
            if ($required !== '*' && $required !== $target->version) {
                throw new RuntimeException("Lineage {$target->name} must stay at version {$required} (required_by:{$name}).");
            }
        }
        $refusals = [];
        foreach ($others as $name => $other) {
            foreach (array_keys($other->files) as $owned) {
                if (Artifact::hostOwnedBy($target->hostOwned, $owned)) {
                    throw new RuntimeException("Host-owned prefixes cover a path owned by lineage {$name}.");
                }
            }
        }
        foreach ($target->files as $path => $bytes) {
            $folded = strtolower($path);
            foreach ($others as $name => $other) {
                if (Artifact::hostOwnedBy($other->hostOwned, $path)) {
                    $refusals[$path] ??= 'host_owned:'.$name;
                }
                foreach (array_keys($other->files) as $owned) {
                    $owned = strtolower($owned);
                    if ($folded === $owned || str_starts_with($folded, $owned.'/') || str_starts_with($owned, $folded.'/')) {
                        $refusals[$path] ??= 'owned_by:'.$name;
                    }
                }
            }
        }

        return $refusals;
    }

    /**
     * Every installed lineage in the checkout: the whole app is all of them. Reads only maintenance records.
     *
     * @return array<string, array{identity: string, version: string, files: array<string, string>}> Lineage names mapped to installed versions and owned paths with their installed sha256.
     *
     * @api
     */
    public static function installed(string $root, string $appId): array
    {
        if ($appId === '') {
            throw new InvalidArgumentException('App identity is required.');
        }

        return array_map(static fn (Artifact $artifact): array => [
            'identity' => $artifact->identity(),
            'version' => $artifact->version,
            'files' => array_map(static fn (string $bytes): string => hash('sha256', $bytes), $artifact->files),
        ], InstalledRecord::lineages($root, $appId));
    }
}
