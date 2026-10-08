<?php

/*
 * The engine carries no DDD doctrine. Dimension
 * names such as "feature" or "module" are preset data in the fixtures, never
 * engine vocabulary. The built-in layouts (src/Layout/BuiltIn, which ships the
 * ddd layout) are data, not engine, and are exempt.
 */
it('keeps DDD vocabulary out of the engine source', function () {
    $offenders = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 2).'/src')) as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php' || str_contains(str_replace('\\', '/', $file->getPathname()), '/src/Layout/BuiltIn/')) {
            continue;
        }

        foreach (file($file->getPathname()) as $line => $text) {
            if (preg_match('/\b(domain|domains|layer|layers|bounded|aggregate)\b/i', $text) === 1) {
                $offenders[] = $file->getFilename().':'.($line + 1).' '.trim($text);
            }
        }
    }

    expect($offenders)->toBe([]);
});

it('has no static mutable state in the engine source', function () {
    $offenders = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 2).'/src')) as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        foreach (file($file->getPathname()) as $line => $text) {
            if (preg_match('/\b(private|protected|public)\s+static\s+(?!function)/', $text) === 1) {
                $offenders[] = $file->getFilename().':'.($line + 1).' '.trim($text);
            }
        }
    }

    expect($offenders)->toBe([]);
});
