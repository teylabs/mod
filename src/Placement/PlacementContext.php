<?php

namespace Tey\Mod\Placement;

use Tey\Mod\Artifact\Identifier;
use Tey\Mod\Exceptions\InvalidPlacementOption;
use Tey\Mod\Layout\CompiledLayout;

/**
 * Where an artifact is placed: an ordered map of dimension name to value.
 *
 * An empty context is ordinary Laravel with no grouping at all. A
 * multi-segment dimension's value is a '/'-joined chain of folders.
 *
 * @api
 */
final readonly class PlacementContext
{
    /**
     * @param  array<string, string>  $values
     */
    private function __construct(private array $values) {}

    /** @api */
    public static function none(): self
    {
        return new self([]);
    }

    /**
     * @param  array<string, string>  $values
     *
     * @api
     */
    public static function of(array $values): self
    {
        return new self($values);
    }

    /**
     * Parse the value of the placement CLI option (`--in=Billing/CreateInvoice`):
     * dimension values in the preset's declared order, separated by "/". Inside
     * a multi-segment dimension the folders are separated by "." instead
     * (`--in=Billing.Invoicing/CreateInvoice`).
     *
     * @api
     */
    public static function fromOption(string $option, CompiledLayout $layout): self
    {
        $option = trim($option);

        if ($option === '') {
            return self::none();
        }

        $dimensions = $layout->dimensions();

        if ($dimensions === []) {
            throw InvalidPlacementOption::noDimensions($option);
        }

        $parts = count($dimensions) === 1 && $dimensions[0]->multi ? [str_replace('/', '.', $option)] : explode('/', $option);

        if (count($parts) > count($dimensions)) {
            throw InvalidPlacementOption::tooManyValues($option, $layout->dimensionNames());
        }

        $values = [];

        foreach ($parts as $index => $value) {
            $value = trim($value);
            $dimension = $dimensions[$index];

            if ($dimension->multi) {
                $chain = str_replace('.', '/', $value);

                if (! Identifier::isSegmentChain($chain)) {
                    throw InvalidPlacementOption::malformedValue($option, $value);
                }

                $values[$dimension->name] = $chain;

                continue;
            }

            if (! Identifier::isClassSegment($value)) {
                throw InvalidPlacementOption::malformedValue($option, $value);
            }

            $values[$dimension->name] = $value;
        }

        return new self($values);
    }

    /** @api */
    public function with(string $dimension, string $value): self
    {
        return new self([...$this->values, $dimension => $value]);
    }

    /** @api */
    public function without(string $dimension): self
    {
        $values = $this->values;
        unset($values[$dimension]);

        return new self($values);
    }

    /**
     * @param  list<string>  $dimensions
     *
     * @api
     */
    public function only(array $dimensions): self
    {
        return new self(array_intersect_key($this->values, array_flip($dimensions)));
    }

    /** @api */
    public function get(string $dimension): ?string
    {
        return $this->values[$dimension] ?? null;
    }

    /** @api */
    public function has(string $dimension): bool
    {
        return array_key_exists($dimension, $this->values);
    }

    /** @api */
    public function isEmpty(): bool
    {
        return $this->values === [];
    }

    /**
     * @return list<string>
     *
     * @api
     */
    public function names(): array
    {
        return array_keys($this->values);
    }

    /**
     * @return array<string, string>
     *
     * @api
     */
    public function toArray(): array
    {
        return $this->values;
    }

    /** @api */
    public function equals(self $other): bool
    {
        return $this->values === $other->values;
    }

    /** @api */
    public function describe(): string
    {
        if ($this->values === []) {
            return '-';
        }

        return implode(', ', array_map(
            static fn (string $name, string $value): string => $name.':'.$value,
            array_keys($this->values),
            $this->values,
        ));
    }
}
