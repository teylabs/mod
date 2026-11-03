<?php

use PHPUnit\Framework\Assert;
use Tey\Mod\Maintenance\Artifact;
use Tey\Mod\Maintenance\Checkout;
use Tey\Mod\Maintenance\DirectoryListings;
use Tey\Mod\Maintenance\Journal;
use Tey\Mod\Maintenance\SafeFilesystem;
use Tey\Mod\Tests\Support\OwnedAppRoot;

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

it('reuses a directory listing only through the pass that holds it; any other check lists afresh', function () {
    $root = sys_get_temp_dir().'/mod-listings-'.bin2hex(random_bytes(4));
    mkdir($root.'/app', 0777, true);

    try {
        file_put_contents($root.'/app/Probe', '');
        $caseSensitive = ! file_exists($root.'/app/probe');
        unlink($root.'/app/Probe');
        if (! $caseSensitive) {
            Assert::markTestSkipped('Case aliases need a case-sensitive filesystem (CI runs this on Linux).');
        }
        file_put_contents($root.'/app/Owned.php', 'A');
        $pass = new DirectoryListings;
        expect(SafeFilesystem::read($root, 'app/Owned.php', $pass))->toBe('A');
        file_put_contents($root.'/app/owned.php', 'alias');

        // The pass keeps the listing it took first: the alias made after it is not seen there (the documented limit; writes never use one).
        expect(SafeFilesystem::read($root, 'app/Owned.php', $pass))->toBe('A')
            // Without a pass, and with a new pass, the directory is listed again and the alias is refused.
            ->and(fn () => SafeFilesystem::path($root, 'app/Owned.php'))->toThrow(RuntimeException::class, 'Case alias or collision at declared path app/Owned.php')
            ->and(fn () => SafeFilesystem::read($root, 'app/Owned.php'))->toThrow(RuntimeException::class, 'Case alias or collision')
            ->and(fn () => SafeFilesystem::read($root, 'app/Owned.php', new DirectoryListings))->toThrow(RuntimeException::class, 'Case alias or collision');
    } finally {
        foreach (array_diff(scandir($root.'/app') ?: [], ['.', '..']) as $entry) {
            unlink($root.'/app/'.$entry);
        }
        rmdir($root.'/app');
        rmdir($root);
    }
});

it('refuses a case alias that appears after inspection, before applying writes anything', function () {
    $root = sys_get_temp_dir().'/mod-listings-'.bin2hex(random_bytes(4));
    mkdir($root.'/app', 0777, true);
    try {
        file_put_contents($root.'/app/Probe', '');
        $caseSensitive = ! file_exists($root.'/app/probe');
        unlink($root.'/app/Probe');
        if (! $caseSensitive) {
            Assert::markTestSkipped('Case aliases need a case-sensitive filesystem (CI runs this on Linux).');
        }
    } finally {
        rmdir($root.'/app');
        rmdir($root);
    }
    OwnedAppRoot::using(function ($root) {
        file_put_contents($root->path('composer.lock'), 'locked');
        $target = new Artifact('example/recipe', 'A', ['app/Owned.php' => 'A'], ['core'], ['auth' => 'session'], ['protocol']);
        $plan = Checkout::inspect($root->path, 'one', new Artifact('example/recipe', '0', [], ['core'], ['auth' => 'session'], ['protocol']), $target, ['lock' => 'composer.lock'], ['framework' => 'passed']);
        file_put_contents($root->path('app/owned.php'), 'alias');

        expect(fn () => Journal::prepare($plan, ['lock' => 'composer.lock'], 'install', exclusiveCheckout: true))->toThrow(RuntimeException::class, 'Case alias or collision at declared path app/Owned.php: the checkout already has app/owned.php.')
            ->and(file_exists($root->path('app/Owned.php')))->toBeFalse()
            ->and(file_get_contents($root->path('app/owned.php')))->toBe('alias');
    });
});
