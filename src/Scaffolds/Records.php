<?php

namespace Tey\Mod\Scaffolds;

use JsonException;
use Tey\Mod\Exceptions\GenerationRefused;

/** @internal Validates and normalizes a records answer against its declared shape. */
final readonly class Records
{
    /** @param array<string, string> $shape */
    public function __construct(private array $shape) {}

    /**
     * Records arrive as JSON text from a flag, or as an already decoded list.
     *
     * @return list<array<string, mixed>>
     *
     * @throws GenerationRefused
     */
    public function validate(mixed $value, string $where): array
    {
        if (is_string($value)) {
            try {
                $value = json_decode($value, true, 16, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                throw GenerationRefused::because("{$where} must be a JSON list of records, such as ".$this->example().'.');
            }
        }
        if (! is_array($value) || ! array_is_list($value)) {
            throw GenerationRefused::because("{$where} must be a list of records, such as ".$this->example().'.');
        }
        $records = [];
        foreach ($value as $index => $record) {
            $number = $index + 1;
            if (! is_array($record) || ($record !== [] && array_is_list($record))) {
                throw GenerationRefused::because("{$where} record {$number} must be an object with ".implode(', ', array_keys($this->shape)).'.');
            }
            $unknown = array_values(array_diff(array_map('strval', array_keys($record)), array_keys($this->shape)));
            if ($unknown !== []) {
                throw GenerationRefused::because("{$where} record {$number} has unknown ".(count($unknown) === 1 ? 'field' : 'fields').' '.implode(', ', $unknown).'. Declared fields: '.implode(', ', array_keys($this->shape)).'.');
            }
            $normalized = [];
            foreach ($this->shape as $field => $type) {
                if (! array_key_exists($field, $record)) {
                    throw GenerationRefused::because("{$where} record {$number} needs {$field}.");
                }
                $normalized[$field] = $this->field($record[$field], $type, "{$where} record {$number}: {$field}");
            }
            $records[] = $normalized;
        }

        return $records;
    }

    private function field(mixed $value, string $type, string $where): string|bool|int
    {
        return match ($type) {
            'bool' => is_bool($value) ? $value : throw GenerationRefused::because("{$where} must be true or false."),
            'int' => is_int($value) ? $value : throw GenerationRefused::because("{$where} must be a whole number."),
            default => is_string($value) && trim($value) !== '' ? $value : throw GenerationRefused::because("{$where} must be a non-empty string."),
        };
    }

    private function example(): string
    {
        $record = [];
        foreach ($this->shape as $field => $type) {
            $record[$field] = match ($type) {
                'bool' => true,
                'int' => 1,
                default => $field,
            };
        }

        return json_encode([$record], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
