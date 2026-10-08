<?php

namespace Tey\Mod\Artifact;

use Tey\Mod\Exceptions\InvalidName;

/**
 * How a requested name becomes a basename, and back.
 *
 * Every policy is invertible: stem() recovers the requested name from a
 * basename, or returns null when the basename cannot have come from it.
 */
final readonly class NamePolicy
{
    public const TIMESTAMP_PATTERN = '/^\d{4}_\d{2}_\d{2}_\d{6}$/';

    private const TIMESTAMPED_BASENAME = '/^(\d{4}_\d{2}_\d{2}_\d{6})_([a-z0-9_]+)$/';

    private function __construct(
        public NamePolicyKind $kind,
        public ?string $value = null,
    ) {}

    public static function asGiven(): self
    {
        return new self(NamePolicyKind::AsGiven);
    }

    public static function suffix(string $suffix): self
    {
        return new self(NamePolicyKind::Suffix, $suffix);
    }

    public static function fixed(string $basename): self
    {
        return new self(NamePolicyKind::Fixed, $basename);
    }

    public static function timestamped(): self
    {
        return new self(NamePolicyKind::Timestamped);
    }

    /**
     * @param  array<string, string|int|float|bool|null>  $attributes
     */
    public function basename(string $kindId, IdentityShape $shape, string $name, array $attributes): string
    {
        if ($this->kind !== NamePolicyKind::Fixed && Identifier::isNested($name)) {
            // Thrown with the dimension hint by the resolver, which knows the layout.
            throw InvalidName::nested($name, []);
        }

        return match ($this->kind) {
            NamePolicyKind::Fixed => (string) $this->value,
            NamePolicyKind::AsGiven => $this->validated($shape, $name),
            NamePolicyKind::Suffix => $this->suffixed($this->validated($shape, $name)),
            NamePolicyKind::Timestamped => $this->timestampedBasename($kindId, $name, $attributes),
        };
    }

    /**
     * The requested name a basename corresponds to, or null when it cannot match this policy.
     */
    public function stem(IdentityShape $shape, string $basename): ?string
    {
        return match ($this->kind) {
            NamePolicyKind::AsGiven => $this->isValid($shape, $basename) ? $basename : null,
            NamePolicyKind::Suffix => $this->unsuffixed($basename),
            NamePolicyKind::Fixed => $basename === $this->value ? $basename : null,
            NamePolicyKind::Timestamped => preg_match(self::TIMESTAMPED_BASENAME, $basename, $m) === 1 ? $m[2] : null,
        };
    }

    public function describe(): string
    {
        return match ($this->kind) {
            NamePolicyKind::AsGiven => 'as-given',
            NamePolicyKind::Suffix => 'suffix:'.$this->value,
            NamePolicyKind::Fixed => 'fixed:'.$this->value,
            NamePolicyKind::Timestamped => 'timestamped',
        };
    }

    private function suffixed(string $name): string
    {
        $suffix = (string) $this->value;

        return str_ends_with($name, $suffix) && $name !== $suffix ? $name : $name.$suffix;
    }

    private function unsuffixed(string $basename): ?string
    {
        $suffix = (string) $this->value;

        if (! str_ends_with($basename, $suffix) || $basename === $suffix) {
            return null;
        }

        $stem = substr($basename, 0, -strlen($suffix));

        return Identifier::isClassSegment($stem) ? $stem : null;
    }

    /**
     * @param  array<string, string|int|float|bool|null>  $attributes
     */
    private function timestampedBasename(string $kindId, string $name, array $attributes): string
    {
        $timestamp = $attributes['timestamp'] ?? null;

        if (! is_string($timestamp) || preg_match(self::TIMESTAMP_PATTERN, $timestamp) !== 1) {
            throw InvalidName::missingAttribute($kindId, 'timestamp', 'format YYYY_MM_DD_HHMMSS');
        }

        if (! Identifier::isFileStem($name) || preg_match('/^[a-z0-9_]+$/', $name) !== 1) {
            throw InvalidName::malformed($name, 'a snake_case migration name');
        }

        return $timestamp.'_'.$name;
    }

    private function validated(IdentityShape $shape, string $name): string
    {
        if (! $this->isValid($shape, $name)) {
            throw InvalidName::malformed(
                $name,
                $shape === IdentityShape::PhpClass ? 'a PHP class name' : 'a lowercase file name',
            );
        }

        return $name;
    }

    private function isValid(IdentityShape $shape, string $name): bool
    {
        return $shape === IdentityShape::PhpClass
            ? Identifier::isClassSegment($name)
            : Identifier::isFileStem($name);
    }
}
