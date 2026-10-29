<?php

use PHPUnit\Framework\Assert;
use Tey\Mod\Maintenance\Artifact;
use Tey\Mod\Maintenance\Checkout;
use Tey\Mod\Maintenance\FileRepair;
use Tey\Mod\Maintenance\Finalization;
use Tey\Mod\Maintenance\Installation;
use Tey\Mod\Maintenance\Journal;
use Tey\Mod\Maintenance\Orphans;
use Tey\Mod\Tests\Support\OwnedAppRoot;

// The apply loop runs the full checkout inspection twice (before the loop, before finalization) and only cheap claim and per-file checks in between; progress is
// an append-only, fsynced log beside the journal, so the journal is never rewritten per file and a crash resumes exactly.

beforeEach(function () {
    if (PHP_OS_FAMILY === 'Windows') {
        Assert::markTestSkipped('Maintenance supports POSIX checkouts only.');
    }
});

/** @param array<string, string> $files */
function loopArtifact(string $version, array $files): Artifact
{
    return new Artifact('example/recipe', $version, $files, ['core'], ['auth' => 'session'], ['protocol']);
}

/**
 * An installed A (three files), and an upgrade journal to B that changes One and Three and leaves Two as it is.
 *
 * @return array{0: Artifact, 1: Artifact, 2: array<string, string>, 3: Journal}
 */
function loopUpgrade(OwnedAppRoot $root, string $intent = 'upgrade'): array
{
    file_put_contents($root->path('composer.lock'), 'lock');
    file_put_contents($root->path('verification-policy.json'), '{"checks":["protocol"]}');
    file_put_contents($root->path('verification.php'), '<?php return true;');
    $inputs = ['lock' => 'composer.lock', 'verification-policy' => 'verification-policy.json', 'verification-code' => 'verification.php'];
    $a = loopArtifact('A', ['app/One.php' => 'A1', 'app/Three.php' => 'A3', 'app/Two.php' => 'same']);
    $b = loopArtifact('B', ['app/One.php' => 'B1', 'app/Three.php' => 'B3', 'app/Two.php' => 'same']);
    $install = Installation::prepare($root->path, 'one', $a, $inputs, ['framework' => 'passed'], 'install', true);
    FileRepair::apply($install);
    expect(Finalization::record($install, ['protocol' => fn () => true])['status'])->toBe('completed');
    $install->close();

    return [$a, $b, $inputs, Installation::upgrade($root->path, 'one', $a, $b, $inputs, ['framework' => 'passed'], $intent, true)];
}

function loopProgress(OwnedAppRoot $root, string $intent = 'upgrade'): string
{
    return $root->path('.mod-maintenance/journal-'.hash('sha256', $intent).'.progress');
}

it('holds the claim for the whole loop: a second apply on the same checkout is refused while the first runs', function () {
    OwnedAppRoot::using(function ($root) {
        [$a, $b, $inputs, $journal] = loopUpgrade($root);
        try {
            $second = null;
            FileRepair::apply($journal, afterReplace: function () use ($root, $a, $b, $inputs, &$second) {
                try {
                    Journal::prepare(Checkout::inspect($root->path, 'one', $a, $b, $inputs, ['framework' => 'passed']), $inputs, 'another', exclusiveCheckout: true)->close();
                    $second = 'claimed';
                } catch (RuntimeException $exception) {
                    $second = $exception->getMessage();
                }
            });
            expect($second)->toBe('Maintenance checkout is already claimed.');
        } finally {
            $journal->close();
        }
    });
});

it('checks each file against its planned bytes right before writing it; a drifted file aborts with the journal resumable', function () {
    OwnedAppRoot::using(function ($root) {
        [$a, $b, $inputs, $journal] = loopUpgrade($root);
        try {
            expect(fn () => FileRepair::apply($journal, afterReplace: function (string $path) use ($root) {
                if ($path === 'app/One.php') {
                    file_put_contents($root->path('app/Three.php'), 'drifted');
                }
            }))->toThrow(RuntimeException::class, 'Generated file changed before replacement.');
            expect(file_get_contents($root->path('app/One.php')))->toBe('B1')
                ->and(file_get_contents($root->path('app/Three.php')))->toBe('drifted')
                ->and($journal->receipt()['completed_files'])->toBe(['app/One.php' => hash('sha256', 'B1')]);
        } finally {
            $journal->close();
        }
        // The drift is the host's to settle; put back the planned bytes and the same intent resumes where it stopped.
        file_put_contents($root->path('app/Three.php'), 'A3');
        $resumed = Journal::reopen($root->path, 'one', $a, $b, $inputs, ['framework' => 'passed'], 'upgrade', exclusiveCheckout: true);
        try {
            expect(FileRepair::apply($resumed)['written'])->toBe(['app/Three.php']);
        } finally {
            $resumed->close();
        }
    });
});

it('resumes a crash mid-loop exactly: every file is written once and the final verification is green', function () {
    OwnedAppRoot::using(function ($root) {
        [$a, $b, $inputs, $journal] = loopUpgrade($root);
        $writes = [];
        try {
            // A kill after the first write: its progress line is already fsynced, nothing after it ran.
            $kill = function (string $path) use (&$writes) {
                $writes[] = $path;
                throw new RuntimeException('killed');
            };
            expect(fn () => FileRepair::apply($journal, afterReplace: $kill))->toThrow(RuntimeException::class, 'killed');
        } finally {
            $journal->close();
        }
        expect(file_exists(loopProgress($root)))->toBeFalse()
            ->and($writes)->toBe(['app/One.php']);
        // The kill landed between the write and its progress line: the file is on disk, unrecorded; a torn half-line from a later crash is ignored too.
        file_put_contents(loopProgress($root), '{"path":"app/Thr', FILE_APPEND);
        $resumed = Journal::reopen($root->path, 'one', $a, $b, $inputs, ['framework' => 'passed'], 'upgrade', exclusiveCheckout: true);
        try {
            $result = FileRepair::apply($resumed, afterReplace: function (string $path) use (&$writes) {
                $writes[] = $path;
            });
            expect($result['written'])->toBe(['app/Three.php'])
                ->and($result['reconciled'])->toBe(['app/One.php', 'app/Two.php'])
                ->and($writes)->toBe(['app/One.php', 'app/Three.php'])
                ->and(Finalization::record($resumed, ['protocol' => fn () => true])['status'])->toBe('completed')
                ->and(file_exists(loopProgress($root)))->toBeFalse();
        } finally {
            $resumed->close();
        }
        expect(array_map('file_get_contents', [$root->path('app/One.php'), $root->path('app/Two.php'), $root->path('app/Three.php')]))->toBe(['B1', 'same', 'B3']);
    });
});

it('records progress append-only, fsynced per file, without rewriting the journal or recording unchanged files', function () {
    OwnedAppRoot::using(function ($root) {
        [$a, $b, $inputs, $journal] = loopUpgrade($root);
        $journalPath = $root->path('.mod-maintenance/journal-'.hash('sha256', 'upgrade').'.json');
        $before = hash_file('sha256', $journalPath);
        try {
            FileRepair::apply($journal);
            $lines = array_map(fn (string $line) => json_decode($line, true), array_filter(explode("\n", file_get_contents(loopProgress($root)))));
            expect(hash_file('sha256', $journalPath))->toBe($before)
                ->and(array_column($lines, 'path'))->toBe(['app/One.php', 'app/Three.php'])
                ->and($journal->receipt()['completed_files'])->toBe(['app/One.php' => hash('sha256', 'B1'), 'app/Three.php' => hash('sha256', 'B3')]);
        } finally {
            $journal->close();
        }
    });
});

it('still runs the full checkout check before finalization: a host input changed mid-loop stops the record', function () {
    OwnedAppRoot::using(function ($root) {
        [$a, $b, $inputs, $journal] = loopUpgrade($root);
        try {
            FileRepair::apply($journal, afterReplace: function () use ($root) {
                file_put_contents($root->path('composer.lock'), 'changed mid-loop');
            });
            expect(fn () => Finalization::record($journal, ['protocol' => fn () => true]))->toThrow(RuntimeException::class);
        } finally {
            $journal->close();
        }
    });
});

it('treats a progress log as known maintenance data, and drops a stray one when a new journal is prepared', function () {
    OwnedAppRoot::using(function ($root) {
        [$a, $b, $inputs, $journal] = loopUpgrade($root);
        $journal->close();
        file_put_contents(loopProgress($root, 'fresh'), '{"path":"app/One.php","sha256":"'.hash('sha256', 'B1').'"}'."\n");
        expect(Orphans::inspect($root->path, $b))->toBe([]);
        $fresh = Journal::prepare(Checkout::inspect($root->path, 'one', $a, $b, $inputs, ['framework' => 'passed']), $inputs, 'fresh', exclusiveCheckout: true);
        try {
            expect(file_exists(loopProgress($root, 'fresh')))->toBeFalse()
                ->and($fresh->receipt()['completed_files'])->toBe([]);
        } finally {
            $fresh->close();
        }
    });
});
