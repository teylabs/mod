<?php

namespace Tey\Mod\Tests\Feature\Install;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;

final class Kit
{
    public static function setup(Workspace $w, string $kit = 'vue-laravel12'): void
    {
        config()->set('mod.layout', 'modules');
        putenv('COLUMNS=72');
        foreach (self::files($kit, 'before') as $path => $contents) {
            $w->write($path, $contents);
        }
        $w->write('resources/js/pages/.gitkeep', '');
    }

    /** @return array<string, string> */
    public static function files(string $kit, string $state): array
    {
        $root = dirname(__DIR__, 2).'/Fixtures/kits/'.$kit.'/'.$state;
        $files = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            /** @var SplFileInfo $file */
            if ($file->isFile()) {
                $files[str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1))] = str_replace("\r\n", "\n", (string) file_get_contents($file->getPathname()));
            }
        }

        return $files;
    }

    public static function assertAfter(Workspace $w, string $kit): void
    {
        foreach (self::files($kit, 'after') as $path => $contents) {
            expect($w->read($path))->toEqualText($contents, $path);
        }
    }
}
