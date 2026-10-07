<?php

namespace Tey\Mod\Placement;

use Tey\Mod\Artifact\Identifier;
use Tey\Mod\Exceptions\InvalidPlacementOption;
use Tey\Mod\Preset\Preset;

/**
 * Where an artifact is placed: an ordered map of dimension name to value.
 *
 * An empty context is ordinary Laravel with no grouping at all.
 */
final readonly class PlacementContext
{
    /**
     * @param  array<string, string>  $values
     */
    private function __construct(private array $values) {}

    public static function none(): self
    {
        return new self([]);
    }

    /**
     * @param  array<string, string>  $values
     */
    public static function of(array $values): self
    {
        return new self($values);
    }

    /**
     * Parse the value of the placement CLI option (`--in=Billing/CreateInvoice`):
     * dimension values in the preset's declared order, separated by "/".
     */
    public static function fromOption(string $option, Preset $preset): self
    {
        $option = trim($option);

        if ($option === '') {
            return self::none();
        }

        $dimensions = $preset->dimensionNames();

        if ($dimensions === []) {
            throw InvalidPlacementOption::noDimensions($option);
        }

        $parts = explode('/', $option);

        if (count($parts) > count($dimensions)) {
            throw InvalidPlacementOption::tooManyValues($option, $dimensions);
        }

        $values = [];

        foreach ($parts as $index => $value) {
            $value = trim($value);

            if (! Identifier::isClassSegment($value)) {
                throw InvalidPlacementOption::malformedValue($option, $value);
            }

            $values[$dimensions[$index]] = $value;
        }

        return new self($values);
    }

    public function with(string $dimension, string $value): self
    {
        return new self([...$this->values, $dimension => $value]);
    }

    public function without(string $dimension): self
    {
        $values = $this->values;
        unset($values[$dimension]);

        return new self($values);
    }

    /**
     * @param  list<string>  $dimensions
     */
    public function only(array $dimensions): self
    {
        return new self(array_intersect_key($this->values, array_flip($dimensions)));
    }

    public function get(string $dimension): ?string
    {
        return $this->values[$dimension] ?? null;
    }

    public function has(string $dimension): bool
    {
        return array_key_exists($dimension, $this->values);
    }

    public function isEmpty(): bool
    {
        return $this->values === [];
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->values);
    }

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return $this->values;
    }

    public function equals(self $other): bool
    {
        return $this->values === $other->values;
    }

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
