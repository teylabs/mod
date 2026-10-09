<?php

namespace Tey\Mod\Templates;

use RuntimeException;
use Tey\Mod\Support\Path;

/** @internal Writes only validated destinations under the application's template folder. */
final readonly class TemplateWriter
{
    public function __construct(private string $basePath) {}

    public function write(string $relative, string $contents, bool $overwrite = false): void
    {
        $folder = Path::join($this->basePath, 'stubs/mod');
        $file = Path::join($folder, $relative);
        if (Path::relative($folder, $file) === null) {
            throw new RuntimeException('The template path points outside its folder. Nothing was written.');
        }
        $ancestor = dirname($file);
        while (! file_exists($ancestor) && dirname($ancestor) !== $ancestor) {
            $ancestor = dirname($ancestor);
        }
        $real = realpath($ancestor);
        $base = realpath($this->basePath);
        if (is_link($file) || ($real !== false && $base !== false && Path::relative($base, $real) === null)) {
            throw new RuntimeException('The template path follows a link outside the application. Nothing was written.');
        }
        if (! is_dir(dirname($file)) && ! mkdir(dirname($file), 0755, true) && ! is_dir(dirname($file))) {
            throw new RuntimeException('mod:template could not create the template folder. Check its permissions.');
        }
        $handle = fopen($file, $overwrite ? 'wb' : 'xb');
        if ($handle === false) {
            throw new RuntimeException("mod:template could not write [stubs/mod/{$relative}]. Check its permissions or pass --force if it exists.");
        }
        try {
            if (fwrite($handle, $contents) !== strlen($contents)) {
                throw new RuntimeException('mod:template could not finish writing the template. Check available disk space.');
            }
        } finally {
            fclose($handle);
        }
    }
}
