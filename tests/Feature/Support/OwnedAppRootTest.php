<?php

use Symfony\Component\Process\Process;
use Tey\Mod\Support\Path;
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

it('removes directory links without traversing their targets', function () {
    OwnedAppRoot::using(function (OwnedAppRoot $target) {
        file_put_contents($target->path('keep.txt'), 'Keep the external target.');
        $path = OwnedAppRoot::using(function (OwnedAppRoot $root) use ($target): string {
            if (PHP_OS_FAMILY === 'Windows') {
                $process = new Process(['cmd', '/D', '/C', 'mklink', '/J', $root->path('linked'), $target->path]);
                $process->mustRun();
            } else {
                symlink($target->path, $root->path('linked'));
            }
            expect(Path::isLink($root->path('linked')))->toBeTrue(json_encode(['is_link' => is_link($root->path('linked')), 'realpath' => realpath($root->path('linked')), 'parent' => realpath($root->path), 'lstat' => lstat($root->path('linked'))], flags: JSON_THROW_ON_ERROR));

            return $root->path;
        });
        expect(is_dir($path))->toBeFalse()->and(file_get_contents($target->path('keep.txt')))->toBe('Keep the external target.')
            ->and($target->exists())->toBeTrue();
    });
});
