<?php

namespace Tey\Mod\Discovery;

use Tey\Mod\Exceptions\InvalidDiscoveryCache;
use Throwable;
use UnexpectedValueException;

/**
 * A versioned PHP cache file holding one inventory for one preset and one
 * set of discovery definitions.
 *
 *     return ['schema' => 2, 'preset' => sha256, 'definitions' => sha256, 'inventory' => [...]];
 *
 * Replay never scans or reflects. A file that does not match is never used.
 *
 * @internal used by Discovery; read, write and clear the cache through Discovery.
 */
final readonly class DiscoveryCache
{
    public const SCHEMA = 2;

    public function __construct(public string $path) {}

    public function exists(): bool
    {
        return is_file($this->path);
    }

    /** @param array<string, array{file: string, path: string, source: string, slots: list<string>, groups: list<string>, digest: string, relative: string, uses_base: bool}> $templates */
    public function write(Inventory $inventory, string $preset, string $definitions, array $templates = []): void
    {
        $directory = dirname($this->path);

        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw InvalidDiscoveryCache::because($this->path, 'its directory cannot be created');
        }

        $payload = [
            'schema' => self::SCHEMA,
            'preset' => $preset,
            'definitions' => $definitions,
            'inventory' => $inventory->toArray(),
            'templates' => $templates,
        ];

        $temporary = $this->path.'.'.bin2hex(random_bytes(6)).'.tmp';

        if (file_put_contents($temporary, '<?php return '.var_export($payload, true).';'.PHP_EOL, LOCK_EX) === false
            || ! rename($temporary, $this->path)
        ) {
            @unlink($temporary);

            throw InvalidDiscoveryCache::because($this->path, 'it cannot be written');
        }

        $this->forgetCompiled();
    }

    /**
     * @throws InvalidDiscoveryCache
     */
    public function read(string $preset, string $definitions): Inventory
    {
        if (! $this->exists()) {
            throw InvalidDiscoveryCache::because($this->path, 'the file does not exist');
        }

        try {
            $payload = (static fn (string $file): mixed => require $file)($this->path);
        } catch (Throwable $exception) {
            throw InvalidDiscoveryCache::because($this->path, 'it is not valid PHP ('.$exception->getMessage().')');
        }

        if (! is_array($payload)) {
            throw InvalidDiscoveryCache::because($this->path, 'it does not return an array');
        }

        if (($payload['schema'] ?? null) !== self::SCHEMA) {
            throw InvalidDiscoveryCache::because($this->path, sprintf(
                'schema version [%s] is not the supported version [%d]',
                is_scalar($payload['schema'] ?? null) ? (string) $payload['schema'] : 'missing',
                self::SCHEMA,
            ));
        }

        if (($payload['preset'] ?? null) !== $preset) {
            throw InvalidDiscoveryCache::because($this->path, 'it was built for a different layout');
        }

        if (($payload['definitions'] ?? null) !== $definitions) {
            throw InvalidDiscoveryCache::because($this->path, 'it was built with different discovery settings');
        }

        try {
            return Inventory::fromArray($payload['inventory'] ?? null);
        } catch (UnexpectedValueException $exception) {
            throw InvalidDiscoveryCache::because($this->path, $exception->getMessage());
        }
    }

    /**
     * The saved template list lets web requests compile without walking folders.
     * An older or invalid cache falls back to the ordinary scan policy.
     *
     * @return array<string, array{file: string, path: string, source: string, slots: list<string>, groups: list<string>, digest: string, relative: string, uses_base: bool}>|null
     */
    public function templates(): ?array
    {
        if (! $this->exists()) {
            return null;
        }
        try {
            $payload = (static fn (string $file): mixed => require $file)($this->path);
        } catch (Throwable) {
            return null;
        }
        if (! is_array($payload) || ($payload['schema'] ?? null) !== self::SCHEMA || ! is_array($payload['templates'] ?? null)) {
            return null;
        }
        $templates = [];
        foreach ($payload['templates'] as $id => $record) {
            if (! is_string($id) || ! is_array($record)) {
                return null;
            }
            if (! is_string($record['file'] ?? null) || ! is_string($record['path'] ?? null)
                || ! is_string($record['source'] ?? null) || ! is_string($record['digest'] ?? null)
                || ! is_string($record['relative'] ?? null) || ! is_bool($record['uses_base'] ?? null)) {
                return null;
            }
            $groups = self::strings($record['groups'] ?? null);
            $slots = self::strings($record['slots'] ?? null);
            if ($groups === null || $slots === null) {
                return null;
            }
            $templates[$id] = ['file' => $record['file'], 'path' => $record['path'], 'source' => $record['source'], 'digest' => $record['digest'], 'relative' => $record['relative'], 'uses_base' => $record['uses_base'], 'groups' => $groups, 'slots' => $slots];
        }

        return $templates;
    }

    /** @return list<string>|null */
    private static function strings(mixed $value): ?array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            return null;
        }
        $strings = array_values(array_filter($value, is_string(...)));

        return count($strings) === count($value) ? $strings : null;
    }

    public function clear(): bool
    {
        if (! $this->exists()) {
            return false;
        }

        unlink($this->path);
        $this->forgetCompiled();

        return true;
    }

    private function forgetCompiled(): void
    {
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($this->path, true);
        }
    }
}
