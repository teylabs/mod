<?php

namespace Tey\Mod\Templates;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Symfony\Component\Filesystem\Path as FilesystemPath;
use Tey\Mod\Support\Path;

/** @internal A literal directory walk, including @anchor and [slot] folders. */
final class TemplateScanner
{
    /** @return array<string, string> relative path => absolute file */
    public function files(string $folder): array
    {
        $folder = FilesystemPath::canonicalize(Path::normalize($folder));
        $folder = Path::normalize(realpath($folder) ?: $folder);
        if (! is_dir($folder)) {
            return [];
        }
        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($folder, FilesystemIterator::SKIP_DOTS));
        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            // Do not follow symlinks outside the registered folder.
            if ($file->isFile() && ! $file->isLink() && $file->getExtension() === 'stub') {
                $files[(string) Path::relative($folder, $file->getPathname())] = Path::normalize($file->getPathname());
            }
        }
        ksort($files);

        return $files;
    }
}
