<?php

namespace Tey\Mod\Tests\Support;

/** Validate the JSON Schema keywords used by mod's additive contract fixtures. */
final class JsonSchema
{
    /** @param array<string, mixed> $schema
     * @return list<string>
     */
    public static function errors(mixed $value, array $schema, string $path = '$'): array
    {
        $errors = [];
        $types = (array) ($schema['type'] ?? []);
        $type = match (true) {
            is_bool($value) => 'boolean', is_int($value) => 'integer', is_string($value) => 'string', $value === null => 'null',
            is_array($value) => array_is_list($value) ? 'array' : 'object', is_object($value) => 'object', default => 'number',
        };
        // Associative JSON is decoded as arrays; {} and [] both decode to [].
        if ($types !== [] && ! in_array($type, $types, true) && ! ($value === [] && in_array('object', $types, true))) {
            $errors[] = $path.' has the wrong type';
        }
        if (array_key_exists('const', $schema) && $value !== $schema['const']) {
            $errors[] = $path.' differs from the required value';
        }
        if (isset($schema['enum']) && ! in_array($value, $schema['enum'], true)) {
            $errors[] = $path.' has an unsupported value';
        }
        if (is_array($value)) {
            foreach ($schema['required'] ?? [] as $key) {
                if (! array_key_exists($key, $value)) {
                    $errors[] = $path.'.'.$key.' is required';
                }
            }
            foreach ($schema['properties'] ?? [] as $key => $child) {
                if (array_key_exists($key, $value)) {
                    array_push($errors, ...self::errors($value[$key], $child, $path.'.'.$key));
                }
            }
            if (isset($schema['additionalProperties']) && is_array($schema['additionalProperties'])) {
                foreach ($value as $key => $item) {
                    if (! array_key_exists($key, $schema['properties'] ?? [])) {
                        array_push($errors, ...self::errors($item, $schema['additionalProperties'], $path.'.'.$key));
                    }
                }
            }
            if (isset($schema['items'])) {
                foreach ($value as $key => $item) {
                    array_push($errors, ...self::errors($item, $schema['items'], $path.'['.$key.']'));
                }
            }
        }
        if (isset($schema['anyOf'])) {
            $matches = array_filter($schema['anyOf'], static fn (array $child): bool => self::errors($value, $child, $path) === []);
            if ($matches === []) {
                $errors[] = $path.' matches no identity schema';
            }
        }

        return $errors;
    }
}
