<?php

namespace Tey\Mod\Placement;

/**
 * One segment of a placement template: a literal folder or a dimension slot.
 *
 * A dimension slot is `{name}` (required) or `{name?}` (optional). With a
 * plus, `{name+}` / `{name+?}`, the slot spans one or more folders, so a
 * value such as "Billing/Invoicing" places a nested group.
 */
final readonly class Segment
{
    private function __construct(
        public ?string $literal,
        public ?string $dimension,
        public bool $required,
        public bool $multi,
    ) {}

    public static function literal(string $literal): self
    {
        return new self($literal, null, true, false);
    }

    public static function dimension(string $dimension, bool $required = true, bool $multi = false): self
    {
        return new self(null, $dimension, $required, $multi);
    }

    /**
     * Parse "{feature}", "{feature?}", "{feature+}", "{feature+?}" or a literal.
     */
    public static function parse(string $spec): self
    {
        if (preg_match('/^\{([A-Za-z_][A-Za-z0-9_]*)(\+)?(\?)?\}$/', $spec, $m) === 1) {
            return self::dimension($m[1], ($m[3] ?? '') !== '?', ($m[2] ?? '') === '+');
        }

        return self::literal($spec);
    }

    public function isDimension(): bool
    {
        return $this->dimension !== null;
    }

    public function describe(): string
    {
        return $this->literal ?? '{'.$this->dimension.($this->multi ? '+' : '').($this->required ? '' : '?').'}';
    }
}
