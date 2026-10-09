<?php

use Tey\Mod\Tests\Support\OwnedAppRoot;

it('destroys owned roots containing read-only files including Git objects', function (string $relative) {
    $path = OwnedAppRoot::using(function (OwnedAppRoot $root) use ($relative): string {
        $file = $root->path($relative);
        if (! is_dir(dirname($file))) {
            mkdir(dirname($file), 0700, true);
        }
        file_put_contents($file, 'Read-only fixture');
        expect(chmod($file, 0444))->toBeTrue()->and(is_file($file))->toBeTrue();

        return $root->path;
    });

    expect(is_dir($path))->toBeFalse();
})->with(['.git/objects/ab/cdef', 'app/readonly.php']);
