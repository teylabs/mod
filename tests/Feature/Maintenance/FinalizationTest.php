<?php

use Composer\Autoload\ClassLoader;
use PHPUnit\Framework\Assert;
use Tey\Mod\Maintenance\Artifact;
use Tey\Mod\Maintenance\Checkout;
use Tey\Mod\Maintenance\FileRepair;
use Tey\Mod\Maintenance\Finalization;
use Tey\Mod\Maintenance\Installation;
use Tey\Mod\Maintenance\Journal;
use Tey\Mod\Tests\Support\OwnedAppRoot;

beforeEach(function () {
    if (PHP_OS_FAMILY === 'Windows') {
        Assert::markTestSkipped('Maintenance supports POSIX checkouts only.');
    }
});

function installArtifact(string $version = 'A'): Artifact
{
    return new Artifact('example/recipe', $version, ['app/One.php' => $version], ['core'], ['auth' => 'session'], ['protocol']);
}

function finalizationInputs($root): array
{
    file_put_contents($root->path('verification-policy.json'), '{"checks":["protocol"]}');
    file_put_contents($root->path('verification.php'), '<?php return true;');

    return ['lock' => 'composer.lock', 'verification-policy' => 'verification-policy.json', 'verification-code' => 'verification.php'];
}

function installedFixture($root): array
{
    file_put_contents($root->path('composer.lock'), 'lock');
    $a = installArtifact();
    $journal = Installation::prepare($root->path, 'one', $a, finalizationInputs($root), ['framework' => 'passed'], 'install', true);
    FileRepair::apply($journal);
    $result = Finalization::record($journal, ['protocol' => fn () => true]);
    $journal->close();

    return [$a, $result];
}

it('installs only absent destinations and records the recoverable artifact after verification', function () {
    OwnedAppRoot::using(function ($root) {
        [$a, $result] = installedFixture($root);
        $saved = json_decode(file_get_contents($root->path('.mod-maintenance/installed.json')), true);
        expect($saved['artifact']['identity'])->toBe($a->identity())
            ->and($saved['artifact']['files'])->toBe(['app/One.php' => base64_encode('A')])
            ->and($saved['artifact']['version'])->toBe('A')
            ->and($saved['commit']['checks'])->toBe(['protocol' => 'passed'])
            ->and($result['status'])->toBe('completed')->and($result['no_op'])->toBeFalse();
    });
});

it('refuses adopting even matching preexisting app bytes', function () {
    OwnedAppRoot::using(function ($root) {
        file_put_contents($root->path('app/One.php'), 'A');
        expect(fn () => Installation::prepare($root->path, 'one', installArtifact(), [], ['framework' => 'passed'], 'install', true))->toThrow(RuntimeException::class)
            ->and(file_get_contents($root->path('app/One.php')))->toBe('A')
            ->and(file_exists($root->path('.mod-maintenance')))->toBeFalse();
    });
});

it('keeps installation unrecorded on failed checks and can retry under the same live claim', function () {
    OwnedAppRoot::using(function ($root) {
        $journal = Installation::prepare($root->path, 'one', installArtifact(), finalizationInputs($root), ['framework' => 'passed'], 'install', true);
        try {
            FileRepair::apply($journal);
            expect(Finalization::record($journal, ['protocol' => fn () => false])['status'])->toBe('verification_failed')
                ->and(file_exists($root->path('.mod-maintenance/installed.json')))->toBeFalse();
            expect(Finalization::record($journal, ['protocol' => fn () => true])['status'])->toBe('completed');
        } finally {
            $journal->close();
        }
    });
});

it('requires installed A ownership and keeps A when B checks fail', function () {
    OwnedAppRoot::using(function ($root) {
        [$a] = installedFixture($root);
        $before = file_get_contents($root->path('.mod-maintenance/installed.json'));
        $b = installArtifact('B');
        $inputs = finalizationInputs($root);
        $journal = Journal::prepare(Checkout::inspect($root->path, 'one', $a, $b, $inputs, ['framework' => 'passed']), $inputs, 'upgrade', true);
        try {
            FileRepair::apply($journal);
            expect(Finalization::record($journal, [])['status'])->toBe('verification_failed')
                ->and(file_get_contents($root->path('.mod-maintenance/installed.json')))->toBe($before)
                ->and(file_get_contents($root->path('app/One.php')))->toBe('B');
            expect(Finalization::record($journal, ['protocol' => fn () => true])['status'])->toBe('completed');
        } finally {
            $journal->close();
        }
        expect(json_decode(file_get_contents($root->path('.mod-maintenance/installed.json')), true)['artifact']['version'])->toBe('B');
    });
});

it('refuses version recording without a matching original installation record', function () {
    OwnedAppRoot::using(function ($root) {
        $a = installArtifact();
        $b = installArtifact('B');
        file_put_contents($root->path('app/One.php'), 'A');
        $inputs = finalizationInputs($root);
        $journal = Journal::prepare(Checkout::inspect($root->path, 'one', $a, $b, $inputs, ['framework' => 'passed']), $inputs, 'upgrade', true);
        try {
            FileRepair::apply($journal);
            expect(fn () => Finalization::record($journal, ['protocol' => fn () => true]))->toThrow(RuntimeException::class)
                ->and(file_exists($root->path('.mod-maintenance/installed.json')))->toBeFalse();
        } finally {
            $journal->close();
        }
    });
});

it('rechecks target and host bytes after successful checks immediately before recording', function (string $path, string $bytes) {
    OwnedAppRoot::using(function ($root) use ($path, $bytes) {
        [$a] = installedFixture($root);
        $before = file_get_contents($root->path('.mod-maintenance/installed.json'));
        $b = installArtifact('B');
        $inputs = finalizationInputs($root);
        $journal = Journal::prepare(Checkout::inspect($root->path, 'one', $a, $b, $inputs, ['framework' => 'passed']), $inputs, 'upgrade', true);
        try {
            FileRepair::apply($journal);
            expect(fn () => Finalization::record($journal, ['protocol' => fn () => true], beforeRecord: function () use ($root, $path, $bytes) {
                file_put_contents($root->path($path), $bytes);
            }))->toThrow(RuntimeException::class)
                ->and(file_get_contents($root->path('.mod-maintenance/installed.json')))->toBe($before)
                ->and(file_get_contents($root->path($path)))->toBe($bytes);
        } finally {
            $journal->close();
        }
    });
})->with(['reverted A' => ['app/One.php', 'A'], 'custom value' => ['app/One.php', 'custom'], 'lock drift' => ['composer.lock', 'drift']]);

it('cold retries before and after the installed record commit with no stale verification reuse', function (bool $after) {
    OwnedAppRoot::using(function ($root) use ($after) {
        [$a] = installedFixture($root);
        $b = installArtifact('B');
        $inputs = finalizationInputs($root);
        $journal = Journal::prepare(Checkout::inspect($root->path, 'one', $a, $b, $inputs, ['framework' => 'passed']), $inputs, 'upgrade', true);
        $calls = 0;
        $check = function () use (&$calls) {
            $calls++;

            return true;
        };
        try {
            FileRepair::apply($journal);
            expect(fn () => Finalization::record($journal, ['protocol' => $check], beforeRecord: $after ? null : fn () => throw new RuntimeException('stopped'), afterRecord: $after ? fn () => throw new RuntimeException('stopped') : null))->toThrow(RuntimeException::class, 'stopped');
        } finally {
            $journal->close();
        }
        $before = file_get_contents($root->path('.mod-maintenance/installed.json'));
        $cold = Journal::reopen($root->path, 'one', $a, $b, $inputs, ['framework' => 'passed'], 'upgrade', true);
        try {
            $result = Finalization::record($cold, ['protocol' => $check]);
            $receipt = file_get_contents($root->path('.mod-maintenance/installed.json'));
            expect($result['no_op'])->toBe($after)->and($calls)->toBe($after ? 1 : 2);
            if ($after) {
                expect($receipt)->toBe($before);
            }
            $again = Finalization::record($cold, ['protocol' => $check]);
            expect($again['receipt'])->toBe($result['receipt'])->and($again['no_op'])->toBeTrue()
                ->and(file_get_contents($root->path('.mod-maintenance/installed.json')))->toBe($receipt);
        } finally {
            $cold->close();
        }
    });
})->with([false, true]);

it('refuses changed or foreign installed state without replacing it', function (string $field) {
    OwnedAppRoot::using(function ($root) use ($field) {
        [$a] = installedFixture($root);
        $path = $root->path('.mod-maintenance/installed.json');
        $saved = json_decode(file_get_contents($path), true);
        match ($field) {
            'app' => $saved['app_id'] = 'foreign',
            'format' => $saved['format'] = 99,
            'bytes' => $saved['artifact']['files']['app/One.php'] = base64_encode('custom'),
            'bindings' => $saved['artifact']['bindings']['auth'] = 'foreign',
            'extra' => $saved['extra'] = 'unknown',
        };
        file_put_contents($path, json_encode($saved));
        $before = file_get_contents($path);
        $b = installArtifact('B');
        $inputs = finalizationInputs($root);
        $journal = Journal::prepare(Checkout::inspect($root->path, 'one', $a, $b, $inputs, ['framework' => 'passed']), $inputs, 'upgrade', true);
        try {
            FileRepair::apply($journal);
            expect(fn () => Finalization::record($journal, ['protocol' => fn () => true]))->toThrow(RuntimeException::class)
                ->and(file_get_contents($path))->toBe($before);
        } finally {
            $journal->close();
        }
    });
})->with(['app', 'format', 'bytes', 'bindings', 'extra']);

it('refuses missing verification policy or code before version recording', function () {
    OwnedAppRoot::using(function ($root) {
        $journal = Installation::prepare($root->path, 'one', installArtifact(), [], ['framework' => 'passed'], 'install', true);
        try {
            FileRepair::apply($journal);
            expect(fn () => Finalization::record($journal, ['protocol' => fn () => true]))->toThrow(RuntimeException::class, 'verification policy and code')
                ->and(file_exists($root->path('.mod-maintenance/installed.json')))->toBeFalse();
        } finally {
            $journal->close();
        }
    });
});

it('checks installed ownership before exposing an upgrade claim for file effects', function () {
    OwnedAppRoot::using(function ($root) {
        file_put_contents($root->path('app/One.php'), 'A');
        expect(fn () => Installation::upgrade($root->path, 'one', installArtifact(), installArtifact('B'), finalizationInputs($root), ['framework' => 'passed'], 'upgrade', true))->toThrow(RuntimeException::class, 'installed baseline')
            ->and(file_get_contents($root->path('app/One.php')))->toBe('A');
    });
});

it('refuses changed installed bytes or unsafe metadata introduced after verification', function (string $kind) {
    OwnedAppRoot::using(function ($root) use ($kind) {
        [$a] = installedFixture($root);
        $inputs = finalizationInputs($root);
        $journal = Installation::upgrade($root->path, 'one', $a, installArtifact('B'), $inputs, ['framework' => 'passed'], 'upgrade', true);
        $path = $root->path('.mod-maintenance/installed.json');
        $foreign = $root->path('host-receipt.json');
        file_put_contents($foreign, 'host');
        try {
            FileRepair::apply($journal);
            expect(fn () => Finalization::record($journal, ['protocol' => fn () => true], beforeRecord: function () use ($path, $foreign, $kind) {
                if ($kind === 'bytes') {
                    file_put_contents($path, 'changed');
                } else {
                    unlink($path);
                    $kind === 'link' ? symlink($foreign, $path) : link($foreign, $path);
                }
            }))->toThrow(RuntimeException::class)
                ->and(file_get_contents($foreign))->toBe('host')
                ->and(file_get_contents($path))->toBe($kind === 'bytes' ? 'changed' : 'host');
        } finally {
            $journal->close();
        }
    });
})->with(['bytes', 'link', 'hardlink']);

it('cold reopens an interrupted initial install and never adopts a new initial intent', function () {
    OwnedAppRoot::using(function ($root) {
        $inputs = finalizationInputs($root);
        $a = installArtifact();
        $journal = Installation::prepare($root->path, 'one', $a, $inputs, ['framework' => 'passed'], 'install', true);
        FileRepair::apply($journal);
        $journal->close();
        $empty = new Artifact($a->name, $a->version, [], $a->groups, $a->bindings, $a->checks);
        $cold = Journal::reopen($root->path, 'one', $empty, $a, $inputs, ['framework' => 'passed'], 'install', true);
        try {
            expect(Finalization::record($cold, ['protocol' => fn () => true])['status'])->toBe('completed');
        } finally {
            $cold->close();
        }
        expect(fn () => Installation::prepare($root->path, 'one', $a, $inputs, ['framework' => 'passed'], 'other-install', true))->toThrow(RuntimeException::class);
    });
});

it('recovers version finalisation when a separate verifying process is killed around commit', function (bool $after) {
    if (! function_exists('posix_kill')) {
        $this->markTestSkipped('A POSIX self-signalled child is required.');
    }
    OwnedAppRoot::using(function ($root) use ($after) {
        [$a] = installedFixture($root);
        $b = installArtifact('B');
        $inputs = finalizationInputs($root);
        $journal = Installation::upgrade($root->path, 'one', $a, $b, $inputs, ['framework' => 'passed'], 'upgrade', true);
        FileRepair::apply($journal);
        $journal->close();
        $autoload = null;
        foreach (ClassLoader::getRegisteredLoaders() as $vendor => $loader) {
            if (realpath((string) $loader->findFile(Artifact::class)) === realpath((string) (new ReflectionClass(Artifact::class))->getFileName())) {
                $autoload = $vendor.'/autoload.php';
                break;
            }
        }
        expect($autoload)->toBeString();
        $source = dirname((new ReflectionClass(Finalization::class))->getFileName(), 3);
        $script = <<<'CHILD'
$loader = require $argv[1];
$loader->addPsr4('Tey\\Mod\\', $argv[2].'/src', true);
$a = new Tey\Mod\Maintenance\Artifact('example/recipe', 'A', ['app/One.php'=>'A'], ['core'], ['auth'=>'session'], ['protocol']);
$b = new Tey\Mod\Maintenance\Artifact('example/recipe', 'B', ['app/One.php'=>'B'], ['core'], ['auth'=>'session'], ['protocol']);
$inputs = ['lock'=>'composer.lock', 'verification-policy'=>'verification-policy.json', 'verification-code'=>'verification.php'];
$journal = Tey\Mod\Maintenance\Journal::reopen($argv[3], 'one', $a, $b, $inputs, ['framework'=>'passed'], 'upgrade', true);
$check = static function () use ($argv) { file_put_contents($argv[3].'/check-runs', 'x', FILE_APPEND); return true; };
$kill = static function () { posix_kill(getmypid(), 9); };
Tey\Mod\Maintenance\Finalization::record($journal, ['protocol'=>$check], beforeRecord: $argv[4]==='after' ? null : $kill, afterRecord: $argv[4]==='after' ? $kill : null);
exit(97);
CHILD;
        $process = proc_open([PHP_BINARY, '-r', $script, $autoload, $source, $root->path, $after ? 'after' : 'before'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        expect(is_resource($process))->toBeTrue();
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_get_status($process);
        $deadline = microtime(true) + 2;
        while ($status['running'] && microtime(true) < $deadline) {
            usleep(1000);
            $status = proc_get_status($process);
        }
        if ($status['running']) {
            proc_terminate($process, 9);
        }
        proc_close($process);
        expect($out)->toBe('')->and($err)->toBe('')
            ->and($status)->toMatchArray(['running' => false, 'signaled' => true, 'termsig' => 9]);
        $installed = $root->path('.mod-maintenance/installed.json');
        $before = file_get_contents($installed);
        expect(json_decode($before, true)['artifact']['version'])->toBe($after ? 'B' : 'A');
        $cold = Journal::reopen($root->path, 'one', $a, $b, $inputs, ['framework' => 'passed'], 'upgrade', true);
        try {
            $result = Finalization::record($cold, ['protocol' => function () use ($root) {
                file_put_contents($root->path('check-runs'), 'x', FILE_APPEND);

                return true;
            }]);
            expect($result['no_op'])->toBe($after)
                ->and(file_get_contents($root->path('check-runs')))->toBe($after ? 'x' : 'xx')
                ->and(json_decode(file_get_contents($installed), true)['artifact']['version'])->toBe('B');
            if ($after) {
                expect(file_get_contents($installed))->toBe($before);
            }
        } finally {
            $cold->close();
        }
    });
})->with([false, true]);
