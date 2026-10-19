<?php

namespace Tey\Mod\Maintenance;

use InvalidArgumentException;
use JsonException;
use ReflectionReference;

/** Recoverable deterministic whole-file output of a trusted recipe. @api */
final readonly class Artifact
{
    /**
     * @param  array<string, string>  $files  Explicit relative paths mapped to exact bytes.
     * @param  list<string>  $groups
     * @param  array<string, string>  $bindings
     * @param  list<string>  $checks  Trusted host check identities, never command strings.
     * @param  array<string, mixed>  $metadata  Inert provenance only; never file or verification authority.
     * @param  array<string, string>  $requires  Lineage names that must be installed first, mapped to an exact version or '*'.
     * @param  list<string>  $hostOwned  Path prefixes left to the host: no installed lineage may own, write or claim at or under them.
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
        /** @api @var array<string, mixed> */ public array $metadata = [],
        /** @api @var array<string, string> */ public array $requires = [],
        /** @api @var list<string> */ public array $hostOwned = [],
    ) {
        if ($name === '' || $version === '' || $checks === []) {
            throw new InvalidArgumentException('Artifact identity and required checks must be explicit.');
        }
        self::validateFiles($files);
        self::validateSelection($groups, true);
        self::validateSelection($checks, true);
        self::validateSelection($bindings, false);
        self::validateMetadata($metadata);
        self::validateSelection($requires, false);
        if (isset($requires[$name])) {
            throw new InvalidArgumentException('A lineage cannot require itself.');
        }
        self::validateSelection($hostOwned, true);
        self::validateFiles(array_fill_keys($hostOwned, ''));
        foreach (array_keys($files) as $path) {
            if (self::hostOwnedBy($hostOwned, $path)) {
                throw new InvalidArgumentException('A lineage cannot own files under its own host-owned prefixes.');
            }
        }
    }

    /**
     * @internal
     *
     * @param  list<string>  $prefixes
     */
    public static function hostOwnedBy(array $prefixes, string $path): bool
    {
        $path = strtolower($path);
        foreach ($prefixes as $prefix) {
            $prefix = strtolower($prefix);
            if ($path === $prefix || str_starts_with($path, $prefix.'/')) {
                return true;
            }
        }

        return false;
    }

    /** @api */
    public function identity(): string
    {
        $identity = [$this->name, $this->version, $this->files, $this->groups, $this->bindings, $this->checks];
        if ($this->metadata !== []) {
            $identity[] = ['metadata' => $this->metadata];
        }
        if ($this->requires !== []) {
            $identity[] = ['requires' => $this->requires];
        }
        if ($this->hostOwned !== []) {
            $identity[] = ['host_owned' => $this->hostOwned];
        }

        return Fingerprint::of($identity);
    }

    /** @param array<array-key, mixed> $metadata */
    private static function validateMetadata(array $metadata): void
    {
        foreach (array_keys($metadata) as $key) {
            if (! is_string($key)) {
                throw new InvalidArgumentException('Metadata requires named provenance fields.');
            }
        }
        $nodes = 0;
        self::metadataValue($metadata, 0, $nodes);
        try {
            $json = json_encode($metadata, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Metadata must be plain JSON data.', previous: $exception);
        }
        if (strlen($json) > 1024 * 1024) {
            throw new InvalidArgumentException('Metadata exceeds the supported byte bound.');
        }
    }

    private static function metadataValue(mixed $value, int $depth, int &$nodes): void
    {
        if ($depth > 16 || ++$nodes > 4096) {
            throw new InvalidArgumentException('Metadata exceeds the supported structural bound.');
        }
        if (is_array($value)) {
            self::withoutReferences($value);
            $list = array_is_list($value);
            foreach ($value as $key => $item) {
                if (! $list && ! is_string($key)) {
                    throw new InvalidArgumentException('Metadata objects require string keys.');
                }
                self::metadataValue($item, $depth + 1, $nodes);
            }
        } elseif (! is_null($value) && ! is_string($value) && ! is_int($value) && ! is_bool($value) && ! (is_float($value) && is_finite($value))) {
            throw new InvalidArgumentException('Metadata cannot contain executable or non-JSON values.');
        }
    }

    /** @param array<array-key, mixed> $values */
    private static function withoutReferences(array $values): void
    {
        foreach (array_keys($values) as $key) {
            if (ReflectionReference::fromArrayElement($values, $key) !== null) {
                throw new InvalidArgumentException('Artifact and plan arrays cannot retain PHP reference aliases.');
            }
        }
    }

    /**
     * @internal
     *
     * @param  array<array-key, mixed>  $values
     */
    public static function validateSelection(array $values, bool $list): void
    {
        self::withoutReferences($values);
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
        self::withoutReferences($files);
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
