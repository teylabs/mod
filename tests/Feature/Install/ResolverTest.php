<?php

it('ships the stack-neutral resolver with an additive folder-file declaration', function () {
    $root = dirname(__DIR__, 3);
    foreach (['inertia.js', 'inertia.d.ts'] as $file) {
        expect(is_file($root.'/resources/js/'.$file))->toBeTrue();
    }
    $declaration = file_get_contents(__DIR__.'/../../Fixtures/inertia/inertia.d.ts').<<<'TS'

/** Return the nearest glob value without invoking a loader or component. @api */
export declare function nearestPageFile<T>(
    name: string,
    files: Record<string, T>,
): T | undefined;
TS;
    expect(file_get_contents($root.'/resources/js/inertia.d.ts'))->toEqualText($declaration."\n");
});
