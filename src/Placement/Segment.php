<?php

namespace Tey\Mod\Placement;

/**
 * One segment of a placement template: a literal folder or a dimension slot.
 */
final readonly class Segment
{
    private function __construct(
        public ?string $literal,
        public ?string $dimension,
        public bool $required,
    ) {}

    public static function literal(string $literal): self
    {
        return new self($literal, null, true);
    }

    public static function dimension(string $dimension, bool $required = true): self
    {
        return new self(null, $dimension, $required);
    }

    /**
     * Parse "{feature}", "{feature?}" or a literal.
     */
    public static function parse(string $spec): self
    {
        if (preg_match('/^\{([A-Za-z_][A-Za-z0-9_]*)(\?)?\}$/', $spec, $m) === 1) {
            return self::dimension($m[1], ! isset($m[2]));
        }

        return self::literal($spec);
    }

    public function isDimension(): bool
    {
        return $this->dimension !== null;
    }

    public function describe(): string
    {
        return $this->literal ?? '{'.$this->dimension.($this->required ? '' : '?').'}';
    }
}
