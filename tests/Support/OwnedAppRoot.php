<?php

namespace Tey\Mod\Tests\Support;

use FilesystemIterator;
use RuntimeException;
use SplFileInfo;
use Tey\Mod\Support\Path;
use UnexpectedValueException;

/**
 * A throwaway Laravel application root owned by a single test.
 *
 * The root is a fresh, real (non-symlinked) directory under the system temp
 * dir. Only roots created by this class, carrying its marker file, are ever
 * deleted, and deletion never follows links out of the root.
 */
final class OwnedAppRoot
{
    public const PREFIX = 'tey-mod-root-';

    private const MARKER = '.tey-mod-owned';

    private const DIRECTORIES = [
        'app',
        'bootstrap/cache',
        'config',
        'database/factories',
        'database/migrations',
        'database/seeders',
        'routes',
        'storage/framework',
    ];

    /** @var array<string, self> */
    private static array $live = [];

    private bool $destroyed = false;

    private function __construct(public readonly string $path) {}

    public static function create(): self
    {
        $base = realpath(sys_get_temp_dir());

        if ($base === false || ! is_dir($base)) {
            throw new RuntimeException('System temp directory is not available.');
        }

        $path = $base.DIRECTORY_SEPARATOR.self::PREFIX.bin2hex(random_bytes(8));

        if (file_exists($path) || is_link($path) || ! mkdir($path, 0700)) {
            throw new RuntimeException("Unable to create a fresh owned root at [{$path}].");
        }

        $root = new self($path);
        self::$live[$path] = $root;

        file_put_contents($root->path(self::MARKER), (string) getmypid());

        foreach (self::DIRECTORIES as $directory) {
            mkdir($root->path($directory), 0700, true);
        }

        file_put_contents($root->path('composer.json'), json_encode([
            'name' => 'tey-mod/owned-app',
            'autoload' => ['psr-4' => ['App\\' => 'app/']],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

        return $root;
    }

    /**
     * Run a callback against a fresh root, destroying it afterwards.
     *
     * @template TReturn
     *
     * @param  callable(self): TReturn  $callback
     * @return TReturn
     */
    public static function using(callable $callback): mixed
    {
        $root = self::create();

        try {
            return $callback($root);
        } finally {
            $root->destroy();
        }
    }

    /**
     * Destroy every root that is still alive and return their paths.
     *
     * @return list<string>
     */
    public static function destroyAll(): array
    {
        $leaked = array_keys(self::$live);

        foreach (self::$live as $root) {
            $root->destroy();
        }

        return $leaked;
    }

    public function path(string $relative = ''): string
    {
        return $relative === ''
            ? $this->path
            : $this->path.DIRECTORY_SEPARATOR.ltrim($relative, '/\\');
    }

    public function destroy(): void
    {
        if ($this->destroyed) {
            return;
        }

        $this->guardOwnership();

        $this->removeContents($this->path);

        self::remove($this->path, rmdir(...));

        $this->destroyed = true;
        unset(self::$live[$this->path]);
    }

    public function exists(): bool
    {
        return is_dir($this->path);
    }

    private function removeContents(string $directory): void
    {
        // Probe each child before descending: RecursiveDirectoryIterator follows
        // Windows junctions even without FOLLOW_SYMLINKS, deleting their targets.
        try {
            $items = new FilesystemIterator($directory, FilesystemIterator::SKIP_DOTS);
        } catch (UnexpectedValueException $e) {
            if (file_exists($directory) || Path::isLink($directory)) {
                throw $e;
            }

            return;
        }

        /** @var SplFileInfo $item */
        foreach ($items as $item) {
            $pathname = $item->getPathname();
            if (! str_starts_with($pathname, $this->path.DIRECTORY_SEPARATOR)) {
                throw new RuntimeException("Refusing to delete [{$pathname}] outside the owned root.");
            }
            $link = Path::isLink($pathname);
            if ($link && PHP_OS_FAMILY === 'Windows') {
                // Directory links (including dangling ones) need rmdir; file
                // links need unlink. Neither operation follows the target.
                if (! @rmdir($pathname)) {
                    self::remove($pathname, unlink(...));
                }
            } elseif ($link) {
                self::remove($pathname, unlink(...));
            } elseif ($item->isDir()) {
                $this->removeContents($pathname);
                self::remove($pathname, rmdir(...));
            } else {
                if (PHP_OS_FAMILY === 'Windows') {
                    chmod($pathname, 0666);
                }
                self::remove($pathname, unlink(...));
            }
        }
    }

    /**
     * Run a delete, skipping a path that vanished since it was listed (git's
     * background writer removes its temp files mid-teardown). Any other
     * failure still throws.
     *
     * @param  callable(string): bool  $delete
     */
    private static function remove(string $pathname, callable $delete): void
    {
        if (@$delete($pathname) || (! file_exists($pathname) && ! Path::isLink($pathname))) {
            return;
        }

        $delete($pathname);

        throw new RuntimeException("Unable to delete [{$pathname}].");
    }

    private function guardOwnership(): void
    {
        if (Path::isLink($this->path)
            || ! is_dir($this->path)
            || ! str_starts_with(basename($this->path), self::PREFIX)
            || ! is_file($this->path(self::MARKER))
        ) {
            throw new RuntimeException("Refusing to destroy [{$this->path}]: not an owned root.");
        }
    }
}
