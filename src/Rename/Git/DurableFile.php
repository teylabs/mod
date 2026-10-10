<?php

namespace Tey\Mod\Rename\Git;

use RuntimeException;
use Tey\Mod\Rename\Diagnostic;

/** @internal Atomic replacement with a flushed file before its name becomes visible. */
final class DurableFile
{
    public static function write(string $path, string $bytes, int $mode = 0600, ?\Closure $flushed = null, ?string $temporary = null): void
    {
        $temporary ??= $path.'.mod-rename-tmp';
        $handle = @fopen($temporary, 'xb');
        if ($handle === false) {
            throw new RuntimeException("mod:rename cannot exclusively create {$temporary}. Preserve it and resolve the collision before retrying.");
        }
        try {
            chmod($temporary, $mode);
            $offset = 0;
            while ($offset < strlen($bytes)) {
                $written = fwrite($handle, substr($bytes, $offset));
                if ($written === false || $written === 0) {
                    throw new RuntimeException("mod:rename cannot write {$temporary}.");
                }
                $offset += $written;
            }
            if (! Diagnostic::measure('flush '.$temporary, fn () => fflush($handle) && fsync($handle))) {
                throw new RuntimeException("mod:rename cannot flush {$temporary}.");
            }
        } finally {
            fclose($handle);
        }
        if ($flushed !== null) {
            $flushed();
        }
        if (! Diagnostic::measure('replace '.$path, fn () => rename($temporary, $path))) {
            throw new RuntimeException("mod:rename cannot replace {$path}.");
        }
        self::directory(dirname($path));
    }

    public static function directory(string $path): void
    {
        // Windows does not expose directory handles through PHP streams.
        if (PHP_OS_FAMILY === 'Windows') {
            return;
        }
        $handle = @fopen($path, 'r');
        if ($handle === false) {
            throw new RuntimeException('mod:rename cannot open metadata directory for durability: '.$path);
        }
        try {
            if (! fsync($handle)) {
                throw new RuntimeException('mod:rename cannot flush metadata directory: '.$path);
            }
        } finally {
            fclose($handle);
        }
    }
}
