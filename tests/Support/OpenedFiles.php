<?php

namespace Tey\Mod\Tests\Support;

/**
 * Records every path PHP opens or lists through the file wrapper while a
 * callback runs, then hands the real wrapper back. Stat calls are not opens.
 */
final class OpenedFiles
{
    /** @var resource|null */
    public $context;

    /** @var list<string> */
    private static array $paths = [];

    /** @var resource|null */
    private $handle = null;

    /**
     * @param  callable(): mixed  $callback
     * @return list<string> the opened paths
     */
    public static function during(callable $callback): array
    {
        self::$paths = [];
        stream_wrapper_unregister('file');
        stream_wrapper_register('file', self::class);
        try {
            $callback();
        } finally {
            @stream_wrapper_unregister('file');
            stream_wrapper_restore('file');
        }

        return self::$paths;
    }

    /** Run a filesystem call against PHP's own wrapper. */
    private static function real(callable $call): mixed
    {
        stream_wrapper_restore('file');
        try {
            return $call();
        } finally {
            stream_wrapper_unregister('file');
            stream_wrapper_register('file', self::class);
        }
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$opened): bool
    {
        self::$paths[] = $path;
        $this->handle = self::real(fn () => @fopen($path, $mode)) ?: null;

        return $this->handle !== null;
    }

    public function stream_read(int $count): string|false
    {
        return fread($this->handle, $count);
    }

    public function stream_write(string $data): int
    {
        return (int) fwrite($this->handle, $data);
    }

    public function stream_eof(): bool
    {
        return feof($this->handle);
    }

    public function stream_tell(): int
    {
        return (int) ftell($this->handle);
    }

    public function stream_seek(int $offset, int $whence): bool
    {
        return fseek($this->handle, $offset, $whence) === 0;
    }

    public function stream_flush(): bool
    {
        return fflush($this->handle);
    }

    /** @return array<int|string, int>|false */
    public function stream_stat(): array|false
    {
        return fstat($this->handle);
    }

    public function stream_close(): void
    {
        fclose($this->handle);
    }

    public function stream_set_option(int $option, int $arg1, ?int $arg2): bool
    {
        return false;
    }

    public function stream_lock(int $operation): bool
    {
        return flock($this->handle, $operation);
    }

    /** @return array<int|string, int>|false */
    public function url_stat(string $path, int $flags): array|false
    {
        return self::real(fn () => ($flags & STREAM_URL_STAT_LINK) ? @lstat($path) : @stat($path));
    }

    public function dir_opendir(string $path, int $options): bool
    {
        self::$paths[] = $path;
        $this->handle = self::real(fn () => @opendir($path)) ?: null;

        return $this->handle !== null;
    }

    public function dir_readdir(): string|false
    {
        return readdir($this->handle);
    }

    public function dir_rewinddir(): bool
    {
        rewinddir($this->handle);

        return true;
    }

    public function dir_closedir(): bool
    {
        closedir($this->handle);

        return true;
    }
}
