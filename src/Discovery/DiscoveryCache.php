<?php

namespace Tey\Mod\Discovery;

use Tey\Mod\Exceptions\InvalidDiscoveryCache;
use Throwable;
use UnexpectedValueException;

/**
 * A versioned PHP cache file holding one inventory for one preset and one
 * set of discovery definitions.
 *
 *     return ['schema' => 1, 'preset' => sha256, 'definitions' => sha256, 'inventory' => [...]];
 *
 * Replay never scans or reflects. A file that does not match is never used.
 *
 * @internal used by Discovery; read, write and clear the cache through Discovery.
 */
final readonly class DiscoveryCache
{
    public const SCHEMA = 1;

    public function __construct(public string $path) {}

    public function exists(): bool
    {
        return is_file($this->path);
    }

    public function write(Inventory $inventory, string $preset, string $definitions): void
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
