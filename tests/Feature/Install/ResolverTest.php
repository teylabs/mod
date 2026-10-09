<?php

it('ships the reviewed stack-neutral resolver and declaration without a Node dependency', function () {
    $root = dirname(__DIR__, 3);
    foreach (['inertia.js', 'inertia.d.ts'] as $file) {
        expect(is_file($root.'/resources/js/'.$file))->toBeTrue();
        expect(file_get_contents($root.'/resources/js/'.$file))
            ->toEqualText(file_get_contents(__DIR__.'/../../Fixtures/inertia/'.$file));
    }
    expect(count(explode("\n", trim(file_get_contents($root.'/resources/js/inertia.js')))))->toBeLessThan(40);
});
