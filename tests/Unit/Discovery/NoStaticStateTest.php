<?php

/*
 * Discovery state belongs to an application instance. No class in
 * src/Discovery may declare static properties (inventories, registration
 * records, caches) that would leak between applications in one process.
 */
it('declares no static properties anywhere in src/Discovery', function () {
    $root = dirname(__DIR__, 3).'/src/Discovery';
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    $checked = 0;

    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $class = 'Tey\\Mod\\Discovery\\'.str_replace(['/', '.php'], ['\\', ''], substr($file->getPathname(), strlen($root) + 1));
        $reflection = new ReflectionClass($class);

        $declared = array_filter(
            $reflection->getProperties(ReflectionProperty::IS_STATIC),
            fn (ReflectionProperty $property): bool => $property->getDeclaringClass()->name === $class,
        );

        expect($declared)->toBe([], $class);
        $checked++;
    }

    expect($checked)->toBeGreaterThan(10);
});
