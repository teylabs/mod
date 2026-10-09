<?php

namespace Tey\Mod\Rename;

use LogicException;

/** @internal Shipped scan policy, separate from layout placement vocabulary. */
final readonly class ExcludedPaths
{
    /** @var list<string> */
    private array $dependencies;

    /** @var list<string> */
    private array $all;

    public function __construct()
    {
        $policy = json_decode((string) file_get_contents(__DIR__.'/../../resources/rename/excluded-paths.json'), true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($policy)) {
            throw new LogicException('Rename exclusion policy must be an object.');
        }
        $this->dependencies = $this->strings($policy['dependencies'] ?? null);
        $this->all = [...$this->dependencies, ...$this->strings($policy['metadata'] ?? null)];
    }

    public function contains(string $path, bool $dependenciesOnly = false): bool
    {
        return array_intersect(explode('/', str_replace('\\', '/', $path)), $dependenciesOnly ? $this->dependencies : $this->all) !== [];
    }

    /** @return list<string> */
    private function strings(mixed $values): array
    {
        if (! is_array($values) || ! array_is_list($values)) {
            throw new LogicException('Rename exclusions must be lists of directory names.');
        }
        $strings = [];
        foreach ($values as $value) {
            if (! is_string($value) || $value === '' || str_contains($value, '/')) {
                throw new LogicException('Rename exclusions must contain directory names.');
            }
            $strings[] = $value;
        }

        return $strings;
    }
}
