<?php

use PHPUnit\Framework\Assert;
use Tey\Mod\Maintenance\Artifact;
use Tey\Mod\Maintenance\Checkout;
use Tey\Mod\Maintenance\FileRepair;
use Tey\Mod\Maintenance\Journal;
use Tey\Mod\Maintenance\Verification;
use Tey\Mod\Tests\Support\OwnedAppRoot;

beforeEach(function () {
    if (PHP_OS_FAMILY === 'Windows') {
        Assert::markTestSkipped('Maintenance supports POSIX checkouts only.');
    }
});

function verificationClaim($root, bool $repair = true): Journal
{
    $a = new Artifact('example/recipe', 'A', ['app/One.php' => 'A'], [], [], ['protocol', 'isolation']);
    $b = new Artifact('example/recipe', 'B', ['app/One.php' => 'B'], [], [], ['protocol', 'isolation']);
    file_put_contents($root->path('app/One.php'), 'A');
    file_put_contents($root->path('composer.lock'), 'lock');
    $inputs = ['lock' => 'composer.lock'];
    $journal = Journal::prepare(Checkout::inspect($root->path, 'one', $a, $b, $inputs, ['framework' => 'passed']), $inputs, 'verify', true);
    if ($repair) {
        FileRepair::apply($journal);
    }

    return $journal;
}

it('runs every declared trusted check and returns a nonpersistent plan-bound result', function () {
    OwnedAppRoot::using(function ($root) {
        $journal = verificationClaim($root);
        $called = [];
        try {
            $checks = [];
            foreach (['protocol', 'isolation'] as $id) {
                $checks[$id] = function () use (&$called, $id) {
                    $called[] = $id;

                    return true;
                };
            }
            $result = Verification::run($journal, $checks);
            expect($called)->toBe(['protocol', 'isolation'])
                ->and($result)->toBe(['passed' => true, 'plan' => $journal->plan()->identity(), 'checks' => ['protocol' => 'passed', 'isolation' => 'passed']])
                ->and($journal->receipt()['state'])->toBe('prepared')
                ->and(file_exists($root->path('.mod-maintenance/manifest.json')))->toBeFalse();
        } finally {
            $journal->close();
        }
    });
});

it('fails explicitly for missing false nonboolean and throwing checks', function ($callback, string $expected) {
    OwnedAppRoot::using(function ($root) use ($callback, $expected) {
        $journal = verificationClaim($root);
        try {
            $result = Verification::run($journal, $callback === null ? ['isolation' => fn () => true] : ['protocol' => $callback, 'isolation' => fn () => true]);
            expect($result['passed'])->toBeFalse()->and($result['checks'])->toBe(['protocol' => $expected, 'isolation' => 'passed'])
                ->and(str_contains(json_encode($result), 'private failure'))->toBeFalse()
                ->and(file_exists($root->path('.mod-maintenance/manifest.json')))->toBeFalse();
        } finally {
            $journal->close();
        }
    });
})->with([
    'missing' => [null, 'missing'],
    'failed' => [fn () => false, 'failed'],
    'truthy unsupported result' => [fn () => 'passed', 'invalid'],
    'exception' => [fn () => throw new RuntimeException('private failure'), 'error'],
]);

it('rejects unregistered extra checks and nonclosure command payloads before callbacks', function ($registry) {
    OwnedAppRoot::using(function ($root) use ($registry) {
        $journal = verificationClaim($root);
        try {
            expect(fn () => Verification::run($journal, $registry))->toThrow(InvalidArgumentException::class);
        } finally {
            $journal->close();
        }
    });
})->with([
    'extra identity' => [['protocol' => fn () => true, 'isolation' => fn () => true, 'undeclared' => fn () => true]],
    'command string' => [['protocol' => 'php artisan test', 'isolation' => fn () => true]],
]);

it('requires every target file before executing checks', function () {
    OwnedAppRoot::using(function ($root) {
        $journal = verificationClaim($root, false);
        $calls = 0;
        try {
            expect(fn () => Verification::run($journal, ['protocol' => function () use (&$calls) {
                $calls++;

                return true;
            }, 'isolation' => fn () => true]))->toThrow(RuntimeException::class)
                ->and($calls)->toBe(0);
        } finally {
            $journal->close();
        }
    });
});

it('refuses verifier drift before any later check and preserves observed bytes', function (string $path, string $bytes) {
    OwnedAppRoot::using(function ($root) use ($path, $bytes) {
        $journal = verificationClaim($root);
        $later = 0;
        try {
            expect(fn () => Verification::run($journal, ['protocol' => function () use ($root, $path, $bytes) {
                file_put_contents($root->path($path), $bytes);

                return true;
            }, 'isolation' => function () use (&$later) {
                $later++;

                return true;
            }]))->toThrow(RuntimeException::class)
                ->and($later)->toBe(0)->and(file_get_contents($root->path($path)))->toBe($bytes)
                ->and(file_exists($root->path('.mod-maintenance/manifest.json')))->toBeFalse();
        } finally {
            $journal->close();
        }
    });
})->with(['custom target' => ['app/One.php', 'custom'], 'reverted original' => ['app/One.php', 'A'], 'changed input' => ['composer.lock', 'changed']]);

it('requires a live claim and never caches verification across cold reopen', function () {
    OwnedAppRoot::using(function ($root) {
        $journal = verificationClaim($root);
        $plan = $journal->plan();
        expect(Verification::run($journal, ['protocol' => fn () => true, 'isolation' => fn () => true])['passed'])->toBeTrue();
        $journal->close();
        expect(fn () => Verification::run($journal, []))->toThrow(RuntimeException::class);
        $cold = Journal::reopen($root->path, 'one', $plan->baseline, $plan->target, ['lock' => 'composer.lock'], ['framework' => 'passed'], 'verify', true);
        try {
            expect(Verification::run($cold, [])['checks'])->toBe(['protocol' => 'missing', 'isolation' => 'missing']);
        } finally {
            $cold->close();
        }
    });
});
