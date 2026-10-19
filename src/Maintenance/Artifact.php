<?php

namespace Tey\Mod\Maintenance;

use InvalidArgumentException;

/** Recoverable deterministic whole-file output of a trusted recipe. @api */
final readonly class Artifact
{
    /**
     * @param  array<string, string>  $files  Explicit relative paths mapped to exact bytes.
     * @param  list<string>  $groups
     * @param  array<string, string>  $bindings
     * @param  list<string>  $checks  Trusted host check identities, never command strings.
     *
     * @api
     */
    public function __construct(
        /** @api */ public string $name,
        /** @api */ public string $version,
        /** @api */ public array $files,
        /** @api */ public array $groups,
        /** @api */ public array $bindings,
        /** @api */ public array $checks,
    ) {
        if ($name === '' || $version === '' || $checks === []) {
            throw new InvalidArgumentException('Artifact identity and required checks must be explicit.');
        }
        self::validateFiles($files);
        self::validateSelection($groups, true);
        self::validateSelection($checks, true);
        self::validateSelection($bindings, false);
    }

    /** @api */
    public function identity(): string
    {
        return Fingerprint::of([$this->name, $this->version, $this->files, $this->groups, $this->bindings, $this->checks]);
    }

    /**
     * @internal
     *
     * @param  array<array-key, mixed>  $values
     */
    public static function validateSelection(array $values, bool $list): void
    {
        foreach ($values as $key => $value) {
            if (! is_string($value) || $value === '' || (! $list && (! is_string($key) || $key === ''))) {
                throw new InvalidArgumentException('Selection identities must be named nonempty strings.');
            }
        }
        if ($list && (! array_is_list($values) || count(array_unique($values)) !== count($values))) {
            throw new InvalidArgumentException('Selections must be unique lists.');
        }
    }

    /**
     * @internal
     *
     * @param  array<array-key, mixed>  $files
     */
    public static function validateFiles(array $files): void
    {
        $seen = [];
        foreach ($files as $path => $bytes) {
            if (! is_string($path) || ! is_string($bytes) || ! preg_match('~\A[A-Za-z0-9_-][A-Za-z0-9_./-]*\z~D', $path)) {
                throw new InvalidArgumentException('Files require portable relative paths and string bytes.');
            }
            foreach (explode('/', $path) as $segment) {
                if ($segment === '' || $segment === '.' || $segment === '..' || str_ends_with($segment, '.')) {
                    throw new InvalidArgumentException('Unsafe relative file path.');
                }
            }
            $folded = strtolower($path);
            if ($folded === '.mod-maintenance' || str_starts_with($folded, '.mod-maintenance/')) {
                throw new InvalidArgumentException('Maintenance metadata cannot be a generated file.');
            }
            foreach ($seen as $other) {
                if ($folded === $other || str_starts_with($folded.'/', $other.'/') || str_starts_with($other.'/', $folded.'/')) {
                    throw new InvalidArgumentException('File paths collide by case or parent ownership.');
                }
            }
            $seen[] = $folded;
        }
    }
}
