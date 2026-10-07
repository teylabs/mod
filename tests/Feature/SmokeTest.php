<?php

use Tey\Mod\ModServiceProvider;
use Tey\Mod\Tests\Support\OwnedAppRoot;

it('boots the service provider in testbench', function () {
    expect(app()->getProvider(ModServiceProvider::class))
        ->toBeInstanceOf(ModServiceProvider::class);
});

it('creates and removes an owned app root', function () {
    $path = OwnedAppRoot::using(function (OwnedAppRoot $root) {
        expect($root->path)
            ->toStartWith(realpath(sys_get_temp_dir()).DIRECTORY_SEPARATOR.OwnedAppRoot::PREFIX)
            ->and(is_link($root->path))->toBeFalse()
            ->and(is_dir($root->path('app')))->toBeTrue()
            ->and(is_link($root->path('app')))->toBeFalse()
            ->and(is_dir($root->path('database/migrations')))->toBeTrue()
            ->and(is_file($root->path('composer.json')))->toBeTrue();

        return $root->path;
    });

    expect(file_exists($path))->toBeFalse();
});

it('gives every root a fresh directory', function () {
    $first = OwnedAppRoot::create();
    $second = OwnedAppRoot::create();

    try {
        expect($first->path)->not->toBe($second->path);
    } finally {
        $first->destroy();
        $second->destroy();
    }

    expect($first->exists())->toBeFalse()
        ->and($second->exists())->toBeFalse();
});

it('removes the root even when the callback throws', function () {
    $seen = new stdClass;

    expect(fn () => OwnedAppRoot::using(function (OwnedAppRoot $root) use ($seen) {
        $seen->path = $root->path;

        throw new RuntimeException('boom');
    }))->toThrow(RuntimeException::class, 'boom');

    expect($seen->path)
        ->toStartWith(realpath(sys_get_temp_dir()).DIRECTORY_SEPARATOR.OwnedAppRoot::PREFIX)
        ->and(file_exists($seen->path))->toBeFalse();
});

it('does not follow links out of the root when destroying', function () {
    $outside = OwnedAppRoot::create();
    file_put_contents($outside->path('keep.txt'), 'keep');

    try {
        OwnedAppRoot::using(function (OwnedAppRoot $root) use ($outside) {
            symlink($outside->path, $root->path('linked'));
        });

        expect(file_get_contents($outside->path('keep.txt')))->toBe('keep');
    } finally {
        $outside->destroy();
    }
});
