<?php

use PHPUnit\Framework\Assert;
use Tey\Mod\Maintenance\SafeFilesystem;

it('names the declared path and the existing one when it refuses a case alias', function () {
    $root = sys_get_temp_dir().'/mod-case-'.bin2hex(random_bytes(4));
    mkdir($root.'/resources/js/pages/auth', 0777, true);

    try {
        expect(fn () => SafeFilesystem::path($root, 'resources/js/pages/Auth/Login.vue'))
            ->toThrow(RuntimeException::class, 'Case alias or collision at declared path resources/js/pages/Auth: the checkout already has resources/js/pages/auth.');
    } finally {
        rmdir($root.'/resources/js/pages/auth');
        rmdir($root.'/resources/js/pages');
        rmdir($root.'/resources/js');
        rmdir($root.'/resources');
        rmdir($root);
    }
});

it('reuses a directory listing only inside one snapshot pass, and lists afresh after it, even when the pass throws or nests', function () {
    $root = sys_get_temp_dir().'/mod-snapshot-'.bin2hex(random_bytes(4));
    mkdir($root.'/app', 0777, true);
    $alias = function (string $name) use ($root): void {
        file_put_contents($root.'/app/'.strtolower($name), 'alias');
    };
    $unalias = function (string $name) use ($root): void {
        unlink($root.'/app/'.strtolower($name));
    };

    try {
        file_put_contents($root.'/app/Probe', '');
        $caseSensitive = ! file_exists($root.'/app/probe');
        unlink($root.'/app/Probe');
        if (! $caseSensitive) {
            Assert::markTestSkipped('Case aliases need a case-sensitive filesystem (CI runs this on Linux).');
        }
        file_put_contents($root.'/app/Owned.php', 'A');
        $refused = fn () => SafeFilesystem::path($root, 'app/Owned.php');

        // Inside one pass the first listing is reused: an alias made mid-pass is not seen there (the documented limit; apply lists afresh).
        SafeFilesystem::snapshot(function () use ($root, $alias): void {
            SafeFilesystem::path($root, 'app/Owned.php');
            $alias('Owned.php');
            expect(SafeFilesystem::path($root, 'app/Owned.php'))->toBe($root.'/app/Owned.php');
        });
        // After the pass every check lists again, so the alias is refused.
        expect($refused)->toThrow(RuntimeException::class, 'Case alias or collision at declared path app/Owned.php');
        $unalias('Owned.php');

        // A pass that throws leaves no listing behind.
        expect(fn () => SafeFilesystem::snapshot(function () use ($root, $alias): never {
            SafeFilesystem::path($root, 'app/Owned.php');
            $alias('Owned.php');
            throw new LogicException('stop');
        }))->toThrow(LogicException::class)
            ->and($refused)->toThrow(RuntimeException::class, 'Case alias or collision');
        $unalias('Owned.php');

        // A nested pass returns to the outer one, and leaving the outer pass clears it too.
        SafeFilesystem::snapshot(function () use ($root, $alias): void {
            SafeFilesystem::snapshot(fn () => SafeFilesystem::path($root, 'app/Owned.php'));
            $alias('Owned.php');
            expect(SafeFilesystem::path($root, 'app/Owned.php'))->toBe($root.'/app/Owned.php');
        });
        expect($refused)->toThrow(RuntimeException::class, 'Case alias or collision');
    } finally {
        foreach (array_diff(scandir($root.'/app') ?: [], ['.', '..']) as $entry) {
            unlink($root.'/app/'.$entry);
        }
        rmdir($root.'/app');
        rmdir($root);
    }
});
