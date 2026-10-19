<?php

use PHPUnit\Framework\Assert;
use Tey\Mod\Maintenance\Artifact;
use Tey\Mod\Maintenance\Checkout;
use Tey\Mod\Tests\Support\OwnedAppRoot;

beforeEach(function () {
    if (PHP_OS_FAMILY === 'Windows') {
        Assert::markTestSkipped('Maintenance supports POSIX checkouts only.');
    }
});

function checkoutArtifact(string $version = 'A'): Artifact
{
    return new Artifact('example/recipe', $version, ['app/Owned.php' => $version], ['core'], ['auth' => 'session'], ['protocol']);
}

it('observes only declared paths and host inputs without writing', function () {
    OwnedAppRoot::using(function ($root) {
        file_put_contents($root->path('app/Owned.php'), 'A');
        file_put_contents($root->path('app/Host.php'), 'host');
        file_put_contents($root->path('composer.lock'), 'dependency-one');
        $before = hash_file('sha256', $root->path('app/Host.php'));
        $plan = Checkout::inspect($root->path, 'one', checkoutArtifact(), checkoutArtifact('B'), ['lock' => 'composer.lock'], ['framework' => 'passed']);
        expect($plan->files['app/Owned.php']['state'])->toBe('replace')
            ->and($plan->files)->toHaveCount(1)
            ->and($plan->inputs['lock'])->toStartWith('sha256:')
            ->and(hash_file('sha256', $root->path('app/Host.php')))->toBe($before)
            ->and(is_dir($root->path('.mod-maintenance')))->toBeFalse();
        $same = Checkout::inspect($root->path, 'one', checkoutArtifact(), checkoutArtifact('B'), ['lock' => 'composer.lock'], ['framework' => 'passed']);
        expect($same->identity())->toBe($plan->identity());
        file_put_contents($root->path('composer.lock'), 'dependency-two');
        expect(Checkout::inspect($root->path, 'one', checkoutArtifact(), checkoutArtifact('B'), ['lock' => 'composer.lock'], ['framework' => 'passed'])->identity())->not->toBe($plan->identity());
    });
});

it('refuses linked owned files parents inputs roots and metadata', function (string $kind) {
    OwnedAppRoot::using(function ($root) use ($kind) {
        OwnedAppRoot::using(function ($outside) use ($root, $kind) {
            file_put_contents($outside->path('app/Owned.php'), 'A');
            file_put_contents($root->path('app/Owned.php'), 'A');
            file_put_contents($root->path('composer.lock'), 'lock');
            $path = $root->path;
            if ($kind === 'file') {
                unlink($root->path('app/Owned.php'));
                symlink($outside->path('app/Owned.php'), $root->path('app/Owned.php'));
            } elseif ($kind === 'parent') {
                unlink($root->path('app/Owned.php'));
                rmdir($root->path('app'));
                symlink($outside->path('app'), $root->path('app'));
            } elseif ($kind === 'input') {
                unlink($root->path('composer.lock'));
                symlink($outside->path('composer.json'), $root->path('composer.lock'));
            } elseif ($kind === 'root') {
                symlink($outside->path, $root->path('linked-root'));
                $path = $root->path('linked-root');
            } elseif ($kind === 'journal') {
                mkdir($root->path('.mod-maintenance'));
                symlink($outside->path('composer.json'), $root->path('.mod-maintenance/journal.json'));
            } else {
                symlink($outside->path, $root->path('.mod-maintenance'));
            }
            expect(fn () => Checkout::inspect($path, 'one', checkoutArtifact(), checkoutArtifact('B'), ['lock' => 'composer.lock'], ['framework' => 'passed']))->toThrow(RuntimeException::class);
            expect(file_get_contents($outside->path('app/Owned.php')))->toBe('A');
        });
    });
})->with(['file', 'parent', 'input', 'root', 'metadata', 'journal']);

it('refuses directories at file paths and case aliases on disk', function (string $kind) {
    OwnedAppRoot::using(function ($root) use ($kind) {
        if ($kind === 'directory') {
            mkdir($root->path('app/Owned.php'));
        } else {
            file_put_contents($root->path('app/owned.php'), 'host');
        }
        expect(fn () => Checkout::inspect($root->path, 'one', checkoutArtifact(), checkoutArtifact('B'), [], ['framework' => 'passed']))->toThrow(RuntimeException::class);
    });
})->with(['directory', 'case']);

it('binds missing host inputs and app roots independently', function () {
    OwnedAppRoot::using(function ($one) {
        OwnedAppRoot::using(function ($two) use ($one) {
            file_put_contents($one->path('app/Owned.php'), 'A');
            file_put_contents($two->path('app/Owned.php'), 'A');
            $a = Checkout::inspect($one->path, 'one', checkoutArtifact(), checkoutArtifact('B'), ['lock' => 'composer.lock'], ['framework' => 'passed']);
            $b = Checkout::inspect($two->path, 'two', checkoutArtifact(), checkoutArtifact('B'), ['lock' => 'composer.lock'], ['framework' => 'passed']);
            expect($a->identity())->not->toBe($b->identity());
            file_put_contents($one->path('composer.lock'), 'new');
            expect(Checkout::inspect($one->path, 'one', checkoutArtifact(), checkoutArtifact('B'), ['lock' => 'composer.lock'], ['framework' => 'passed'])->identity())->not->toBe($a->identity());
        });
    });
});
