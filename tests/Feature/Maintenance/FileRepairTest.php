<?php

use Composer\Autoload\ClassLoader;
use PHPUnit\Framework\Assert;
use Tey\Mod\Maintenance\Artifact;
use Tey\Mod\Maintenance\Checkout;
use Tey\Mod\Maintenance\FileRepair;
use Tey\Mod\Maintenance\Journal;
use Tey\Mod\Tests\Support\OwnedAppRoot;

beforeEach(function () {
    if (PHP_OS_FAMILY === 'Windows') {
        Assert::markTestSkipped('Maintenance supports POSIX checkouts only.');
    }
});

function repairArtifacts(): array
{
    return [new Artifact('example/recipe', 'A', ['app/One.php' => 'A1', 'app/Two.php' => 'A2'], ['core'], ['auth' => 'session'], ['protocol']), new Artifact('example/recipe', 'B', ['app/One.php' => 'B1', 'app/Two.php' => 'B2'], ['core'], ['auth' => 'session'], ['protocol'])];
}

function repairPrepare($root): array
{
    [$a, $b] = repairArtifacts();
    foreach ($a->files as $path => $bytes) {
        file_put_contents($root->path($path), $bytes);
    }
    file_put_contents($root->path('composer.lock'), 'lock');
    file_put_contents($root->path('app/Host.php'), 'host');
    $inputs = ['lock' => 'composer.lock'];
    $plan = Checkout::inspect($root->path, 'one', $a, $b, $inputs, ['framework' => 'passed']);

    return [$a, $b, $inputs, Journal::prepare($plan, $inputs, 'upgrade', exclusiveCheckout: true)];
}

it('replaces only explicit untouched files and records readback without recording a version', function () {
    OwnedAppRoot::using(function ($root) {
        [$a, $b, $inputs, $journal] = repairPrepare($root);
        try {
            $result = FileRepair::apply($journal);
            expect($result['written'])->toBe(['app/One.php', 'app/Two.php'])
                ->and(file_get_contents($root->path('app/One.php')))->toBe('B1')
                ->and(file_get_contents($root->path('app/Two.php')))->toBe('B2')
                ->and(file_get_contents($root->path('app/Host.php')))->toBe('host')
                ->and($journal->receipt()['state'])->toBe('prepared')
                ->and($journal->receipt()['completed_files']['app/One.php'])->toBe(hash('sha256', 'B1'))
                ->and(file_exists($root->path('.mod-maintenance/manifest.json')))->toBeFalse();
        } finally {
            $journal->close();
        }
    });
});

it('cold-reopens a write-before-receipt interruption and reconciles target bytes', function () {
    OwnedAppRoot::using(function ($root) {
        [$a, $b, $inputs, $journal] = repairPrepare($root);
        try {
            expect(fn () => FileRepair::apply($journal, afterReplace: function () {
                throw new RuntimeException('interrupted');
            }))->toThrow(RuntimeException::class, 'interrupted');
            expect(file_get_contents($root->path('app/One.php')))->toBe('B1')
                ->and($journal->receipt()['completed_files'])->toBe([]);
        } finally {
            $journal->close();
        }
        clearstatcache(true, $root->path('app/One.php'));
        $before = fileinode($root->path('app/One.php'));
        $resumed = Journal::reopen($root->path, 'one', $a, $b, $inputs, ['framework' => 'passed'], 'upgrade', exclusiveCheckout: true);
        try {
            $result = FileRepair::apply($resumed);
            expect($result['written'])->toBe(['app/Two.php'])
                ->and($result['reconciled'])->toBe(['app/One.php'])
                ->and(fileinode($root->path('app/One.php')))->toBe($before)
                ->and(file_get_contents($root->path('app/Two.php')))->toBe('B2');
        } finally {
            $resumed->close();
        }
    });
});

it('refuses every third value or stale host input before more writes during recovery', function (string $path) {
    OwnedAppRoot::using(function ($root) use ($path) {
        [$a, $b, $inputs, $journal] = repairPrepare($root);
        try {
            expect(fn () => FileRepair::apply($journal, afterReplace: fn () => throw new RuntimeException('interrupted')))->toThrow(RuntimeException::class);
        } finally {
            $journal->close();
        }
        file_put_contents($root->path($path), 'custom');
        expect(fn () => Journal::reopen($root->path, 'one', $a, $b, $inputs, ['framework' => 'passed'], 'upgrade', exclusiveCheckout: true))->toThrow(RuntimeException::class)
            ->and(file_get_contents($root->path($path)))->toBe('custom')
            ->and(file_get_contents($root->path('app/Two.php')))->toBe($path === 'app/Two.php' ? 'custom' : 'A2');
    });
})->with(['app/One.php', 'app/Two.php', 'composer.lock']);

it('rechecks all remaining paths immediately before replacement', function () {
    OwnedAppRoot::using(function ($root) {
        [$a, $b, $inputs, $journal] = repairPrepare($root);
        try {
            expect(fn () => FileRepair::apply($journal, afterReplace: function () use ($root) {
                file_put_contents($root->path('app/Two.php'), 'intervening edit');
            }))->toThrow(RuntimeException::class)
                ->and(file_get_contents($root->path('app/Two.php')))->toBe('intervening edit');
        } finally {
            $journal->close();
        }
    });
});

it('refuses an expired claim and never treats prepared bytes as permission without the lock', function () {
    OwnedAppRoot::using(function ($root) {
        [$a, $b, $inputs, $journal] = repairPrepare($root);
        $journal->close();
        expect(fn () => FileRepair::apply($journal))->toThrow(RuntimeException::class)
            ->and(file_get_contents($root->path('app/One.php')))->toBe('A1');
    });
});

it('preserves executable mode and repeats ready files without writes', function () {
    OwnedAppRoot::using(function ($root) {
        [$a, $b, $inputs, $journal] = repairPrepare($root);
        chmod($root->path('app/One.php'), 0755);
        try {
            FileRepair::apply($journal);
            $again = FileRepair::apply($journal);
            expect($again['written'])->toBe([])->and(fileperms($root->path('app/One.php')) & 0777)->toBe(0755);
        } finally {
            $journal->close();
        }
    });
});

it('refuses reconciliation mode without the preexisting prepared intent', function () {
    OwnedAppRoot::using(function ($root) {
        [$a, $b] = repairArtifacts();
        file_put_contents($root->path('app/One.php'), 'A1');
        file_put_contents($root->path('app/Two.php'), 'A2');
        $plan = Checkout::inspect($root->path, 'one', $a, $b, [], ['framework' => 'passed']);
        expect(fn () => Journal::prepare($plan, [], 'forged-recovery', exclusiveCheckout: true, recovery: true))->toThrow(RuntimeException::class)
            ->and(file_exists($root->path('.mod-maintenance')))->toBeFalse();
    });
});

it('restores an explicitly owned missing file and creates only absent declared new paths', function () {
    OwnedAppRoot::using(function ($root) {
        [$a, $b] = repairArtifacts();
        file_put_contents($root->path('app/Two.php'), 'A2');
        $b = new Artifact($b->name, $b->version, $b->files + ['generated/Nested.php' => 'new'], $b->groups, $b->bindings, $b->checks);
        $plan = Checkout::inspect($root->path, 'one', $a, $b, [], ['framework' => 'passed']);
        $journal = Journal::prepare($plan, [], 'upgrade', exclusiveCheckout: true);
        try {
            FileRepair::apply($journal);
            expect(file_get_contents($root->path('app/One.php')))->toBe('B1')
                ->and(file_get_contents($root->path('generated/Nested.php')))->toBe('new');
        } finally {
            $journal->close();
        }
    });
});

it('recovers after the writing child is killed before the journal receipt', function () {
    if (! function_exists('posix_kill')) {
        $this->markTestSkipped('A POSIX self-signalled child is required.');
    }
    OwnedAppRoot::using(function ($root) {
        [$a, $b, $inputs, $journal] = repairPrepare($root);
        $journal->close();
        $autoload = null;
        foreach (ClassLoader::getRegisteredLoaders() as $vendor => $loader) {
            if (realpath((string) $loader->findFile(Artifact::class)) === realpath((string) (new ReflectionClass(Artifact::class))->getFileName())) {
                $autoload = $vendor.'/autoload.php';
                break;
            }
        }
        expect($autoload)->toBeString();
        $source = dirname((new ReflectionClass(FileRepair::class))->getFileName(), 3);
        $script = <<<'CHILD'
$loader = require $argv[1];
$loader->addPsr4('Tey\\Mod\\', $argv[2].'/src', true);
$a = new Tey\Mod\Maintenance\Artifact('example/recipe', 'A', ['app/One.php'=>'A1', 'app/Two.php'=>'A2'], ['core'], ['auth'=>'session'], ['protocol']);
$b = new Tey\Mod\Maintenance\Artifact('example/recipe', 'B', ['app/One.php'=>'B1', 'app/Two.php'=>'B2'], ['core'], ['auth'=>'session'], ['protocol']);
$journal = Tey\Mod\Maintenance\Journal::reopen($argv[3], 'one', $a, $b, ['lock'=>'composer.lock'], ['framework'=>'passed'], 'upgrade', exclusiveCheckout: true);
Tey\Mod\Maintenance\FileRepair::apply($journal, afterReplace: static function () { posix_kill(getmypid(), 9); });
exit(97);
CHILD;
        $process = proc_open([PHP_BINARY, '-r', $script, $autoload, $source, $root->path], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
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
        expect($err)->toBe('');
        expect($out)->toBe('');
        expect($status)->toMatchArray(['running' => false, 'signaled' => true, 'termsig' => 9])
            ->and(file_get_contents($root->path('app/One.php')))->toBe('B1')
            ->and(file_get_contents($root->path('app/Two.php')))->toBe('A2');
        $saved = json_decode(file_get_contents($root->path('.mod-maintenance/journal-'.hash('sha256', 'upgrade').'.json')), true);
        expect($saved['completed_files'])->toBe([]);
        $resumed = Journal::reopen($root->path, 'one', $a, $b, $inputs, ['framework' => 'passed'], 'upgrade', exclusiveCheckout: true);
        try {
            expect(FileRepair::apply($resumed)['written'])->toBe(['app/Two.php']);
        } finally {
            $resumed->close();
        }
    });
});
