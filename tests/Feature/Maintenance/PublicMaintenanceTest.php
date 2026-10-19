<?php

use PHPUnit\Framework\Assert;
use Tey\Mod\Maintenance\Artifact;
use Tey\Mod\Maintenance\Checkout;
use Tey\Mod\Maintenance\FilePlan;
use Tey\Mod\Maintenance\Maintenance;
use Tey\Mod\Tests\Support\OwnedAppRoot;

beforeEach(function () {
    if (PHP_OS_FAMILY === 'Windows') {
        Assert::markTestSkipped('Maintenance supports POSIX checkouts only.');
    }
});

function publicArtifacts(): array
{
    return [new Artifact('example/recipe', 'A', ['app/One.php' => 'A'], ['core'], ['auth' => 'session'], ['check']), new Artifact('example/recipe', 'B', ['app/One.php' => 'B'], ['core'], ['auth' => 'session'], ['check'])];
}

function publicInputs($root): array
{
    file_put_contents($root->path('policy.json'), '{"checks":["check"]}');
    file_put_contents($root->path('verifier.php'), '<?php return true;');
    file_put_contents($root->path('composer.lock'), 'locked');

    return ['verification-policy' => 'policy.json', 'verification-code' => 'verifier.php', 'lock' => 'composer.lock'];
}

function publicInstallPlan($root): array
{
    [$a, $b] = publicArtifacts();
    $empty = new Artifact($a->name, $a->version, [], $a->groups, $a->bindings, $a->checks);
    $inputs = publicInputs($root);

    return [$a, $b, $inputs, Checkout::inspect($root->path, 'one', $empty, $a, $inputs, ['php' => 'passed'])];
}

it('uses public entrypoints to install upgrade and repeat with no callback or receipt effects', function () {
    OwnedAppRoot::using(function ($root) {
        [$a, $b, $inputs, $install] = publicInstallPlan($root);
        $calls = 0;
        $checks = ['check' => function () use (&$calls) {
            $calls++;

            return true;
        }];
        expect(Maintenance::apply($install, $inputs, 'install', $checks, true)['status'])->toBe('completed');
        file_put_contents($root->path('app/Host.php'), 'host');
        $upgrade = Checkout::inspect($root->path, 'one', $a, $b, $inputs, ['php' => 'passed']);
        $result = Maintenance::apply($upgrade, $inputs, 'upgrade', $checks, true);
        $snapshots = [];
        foreach (glob($root->path('.mod-maintenance/*')) as $file) {
            $snapshots[$file] = [file_get_contents($file), fileinode($file)];
        }
        expect($result['status'])->toBe('completed')->and($result['written'])->toBe(['app/One.php']);
        $again = Maintenance::apply($upgrade, $inputs, 'upgrade', [], true);
        $cold = Maintenance::resume($root->path, 'one', $a, $b, $inputs, ['php' => 'passed'], 'upgrade', [], true);
        expect($again['no_op'])->toBeTrue()->and($cold['no_op'])->toBeTrue()
            ->and($cold['receipt'])->toBe($result['receipt'])->and($again['written'])->toBe([])->and($calls)->toBe(2)
            ->and(file_get_contents($root->path('app/Host.php')))->toBe('host');
        foreach ($snapshots as $file => $snapshot) {
            expect([file_get_contents($file), fileinode($file)])->toBe($snapshot);
        }
    });
});

it('validates every required closure before unfinished generated effects', function (array $registry) {
    OwnedAppRoot::using(function ($root) use ($registry) {
        [$a, $b, $inputs, $plan] = publicInstallPlan($root);
        expect(fn () => Maintenance::apply($plan, $inputs, 'install', $registry, true))->toThrow(InvalidArgumentException::class)
            ->and(file_exists($root->path('app/One.php')))->toBeFalse();
        expect(Maintenance::apply($plan, $inputs, 'install', ['check' => fn () => true], true)['status'])->toBe('completed');
    });
})->with(['missing' => [[]], 'command' => [['check' => 'php artisan test']], 'extra' => [['check' => fn () => true, 'other' => fn () => true]]]);

it('keeps failed verification pending and resumes through public trusted artifacts', function () {
    OwnedAppRoot::using(function ($root) {
        [$a, $b, $inputs, $plan] = publicInstallPlan($root);
        expect(Maintenance::apply($plan, $inputs, 'install', ['check' => fn () => false], true)['status'])->toBe('verification_failed')
            ->and(file_exists($root->path('.mod-maintenance/installed.json')))->toBeFalse();
        expect(Maintenance::resume($root->path, 'one', $plan->baseline, $a, $inputs, ['php' => 'passed'], 'install', ['check' => fn () => true], true)['status'])->toBe('completed');
    });
});

it('refuses missing policy code or exclusive ownership before generated effects', function (string $missing) {
    OwnedAppRoot::using(function ($root) use ($missing) {
        [$a, $b, $inputs, $plan] = publicInstallPlan($root);
        if ($missing !== 'exclusive') {
            unlink($root->path($inputs[$missing]));
        }
        expect(fn () => Maintenance::apply($plan, $inputs, 'install', ['check' => fn () => true], $missing !== 'exclusive'))->toThrow(RuntimeException::class)
            ->and(file_exists($root->path('app/One.php')))->toBeFalse();
    });
})->with(['verification-policy', 'verification-code', 'exclusive']);

it('refuses identical unowned bytes incompatible inputs and stale caller-made pure plans', function (string $kind) {
    OwnedAppRoot::using(function ($root) use ($kind) {
        [$a, $b, $inputs, $plan] = publicInstallPlan($root);
        if ($kind === 'unowned') {
            file_put_contents($root->path('app/One.php'), 'A');
        }
        if ($kind === 'input') {
            file_put_contents($root->path('composer.lock'), 'drift');
        }
        if ($kind === 'incompatible') {
            $plan = Checkout::inspect($root->path, 'one', $plan->baseline, $a, $inputs, ['php' => 'failed']);
        }
        if ($kind === 'pure') {
            file_put_contents($root->path('app/One.php'), 'custom');
            $plan = FilePlan::between('one', $root->path, $a, $b, ['app/One.php' => 'A'], $plan->inputs, ['php' => 'passed']);
        }
        expect(fn () => Maintenance::apply($plan, $inputs, 'install', ['check' => fn () => true], true))->toThrow(RuntimeException::class)
            ->and(file_exists($root->path('.mod-maintenance/installed.json')))->toBeFalse();
        if ($kind === 'pure') {
            expect(file_get_contents($root->path('app/One.php')))->toBe('custom');
        }
    });
})->with(['unowned', 'input', 'incompatible', 'pure']);

it('refuses upgrade ownership and foreign roots before writing generated files', function () {
    OwnedAppRoot::using(function ($root) {
        [$a, $b, $inputs] = publicInstallPlan($root);
        file_put_contents($root->path('app/One.php'), 'A');
        $plan = Checkout::inspect($root->path, 'one', $a, $b, $inputs, ['php' => 'passed']);
        expect(fn () => Maintenance::apply($plan, $inputs, 'upgrade', ['check' => fn () => true], true))->toThrow(RuntimeException::class)
            ->and(file_get_contents($root->path('app/One.php')))->toBe('A');
        OwnedAppRoot::using(function ($other) use ($a, $b) {
            $inputs = publicInputs($other);
            expect(fn () => Maintenance::resume($other->path, 'one', $a, $b, $inputs, ['php' => 'passed'], 'upgrade', ['check' => fn () => true], true))->toThrow(RuntimeException::class)
                ->and(file_exists($other->path('app/One.php')))->toBeFalse();
        });
    });
});

it('preserves third values changed intent and stale recovery policy', function (string $kind) {
    OwnedAppRoot::using(function ($root) use ($kind) {
        [$a, $b, $inputs, $plan] = publicInstallPlan($root);
        Maintenance::apply($plan, $inputs, 'install', ['check' => fn () => false], true);
        if ($kind === 'third') {
            file_put_contents($root->path('app/One.php'), 'custom');
        }
        if ($kind === 'policy') {
            file_put_contents($root->path('policy.json'), 'changed');
        }
        $target = $kind === 'target' ? $b : $a;
        expect(fn () => Maintenance::resume($root->path, 'one', $plan->baseline, $target, $inputs, ['php' => 'passed'], 'install', ['check' => fn () => true], true))->toThrow(RuntimeException::class)
            ->and(file_exists($root->path('.mod-maintenance/installed.json')))->toBeFalse();
    });
})->with(['third', 'policy', 'target']);

it('reports bounded no-follow orphan diagnostics and preserves all candidate bytes', function (string $kind) {
    OwnedAppRoot::using(function ($root) use ($kind) {
        [$a, $b, $inputs, $plan] = publicInstallPlan($root);
        $candidate = $kind === 'file' ? 'app/One.php.mod-maintenance-tmp-unknown' : '.mod-maintenance-stage-unknown';
        if ($kind === 'file') {
            file_put_contents($root->path($candidate), 'partial bytes');
        } elseif ($kind === 'directory') {
            mkdir($root->path($candidate));
            file_put_contents($root->path($candidate.'/host'), 'host bytes');
        } else {
            symlink('/path/not-to-follow', $root->path($candidate));
        }
        $diagnosis = Maintenance::diagnose($plan, $inputs);
        expect($diagnosis['orphans'])->toBe([['path' => $candidate, 'kind' => $kind]])
            ->and(Maintenance::apply($plan, $inputs, 'install', ['check' => fn () => true], true)['status'])->toBe('orphaned')
            ->and(file_exists($root->path('app/One.php')))->toBeFalse()
            ->and(file_exists($root->path('.mod-maintenance')))->toBeFalse();
        if ($kind === 'directory') {
            expect(file_get_contents($root->path($candidate.'/host')))->toBe('host bytes');
        }
        if ($kind === 'file') {
            expect(file_get_contents($root->path($candidate)))->toBe('partial bytes');
        }
        if ($kind === 'link') {
            expect(readlink($root->path($candidate)))->toBe('/path/not-to-follow');
        }
    });
})->with(['file', 'directory', 'link']);

it('never calls completed when a metadata orphan remains and refuses verifier-created orphans', function () {
    OwnedAppRoot::using(function ($root) {
        [$a, $b, $inputs, $plan] = publicInstallPlan($root);
        Maintenance::apply($plan, $inputs, 'install', ['check' => fn () => true], true);
        $before = file_get_contents($root->path('.mod-maintenance/installed.json'));
        file_put_contents($root->path('.mod-maintenance/installed.json.tmp-unknown'), 'partial');
        expect(Maintenance::apply($plan, $inputs, 'install', [], true)['status'])->toBe('orphaned')
            ->and(file_get_contents($root->path('.mod-maintenance/installed.json')))->toBe($before);
        unlink($root->path('.mod-maintenance/installed.json.tmp-unknown'));
        $upgrade = Checkout::inspect($root->path, 'one', $a, $b, $inputs, ['php' => 'passed']);
        $result = Maintenance::apply($upgrade, $inputs, 'upgrade', ['check' => function () use ($root) {
            file_put_contents($root->path('app/One.php.mod-maintenance-tmp-new'), 'partial');

            return true;
        }], true);
        expect($result['status'])->toBe('orphaned')->and(file_get_contents($root->path('.mod-maintenance/installed.json')))->toBe($before);
    });
});

it('rejects invalid supplied callbacks even for exact completed retries', function () {
    OwnedAppRoot::using(function ($root) {
        [$a, $b, $inputs, $plan] = publicInstallPlan($root);
        Maintenance::apply($plan, $inputs, 'install', ['check' => fn () => true], true);
        expect(fn () => Maintenance::apply($plan, $inputs, 'install', ['check' => 'command'], true))->toThrow(InvalidArgumentException::class);
    });
});

it('does not recursively traverse unknown metadata or follow a linked metadata root', function (bool $linked) {
    OwnedAppRoot::using(function ($root) use ($linked) {
        [$a, $b, $inputs, $plan] = publicInstallPlan($root);
        if ($linked) {
            symlink('/path/not-to-follow', $root->path('.mod-maintenance'));
            $expected = [['path' => '.mod-maintenance', 'kind' => 'link']];
        } else {
            Maintenance::apply($plan, $inputs, 'install', ['check' => fn () => true], true);
            mkdir($root->path('.mod-maintenance/unknown'));
            symlink('/path/not-to-follow', $root->path('.mod-maintenance/unknown/link'));
            $expected = [['path' => '.mod-maintenance/unknown', 'kind' => 'directory']];
        }
        expect(Maintenance::diagnose($plan, $inputs)['orphans'])->toBe($expected)
            ->and(Maintenance::apply($plan, $inputs, 'install', [], true)['status'])->toBe('orphaned');
    });
})->with([false, true]);

it('refuses forged installed state before unfinished upgrade writes', function () {
    OwnedAppRoot::using(function ($root) {
        [$a, $b, $inputs, $install] = publicInstallPlan($root);
        Maintenance::apply($install, $inputs, 'install', ['check' => fn () => true], true);
        $path = $root->path('.mod-maintenance/installed.json');
        $record = json_decode(file_get_contents($path), true);
        $record['app_id'] = 'foreign';
        file_put_contents($path, json_encode($record));
        $before = file_get_contents($path);
        $plan = Checkout::inspect($root->path, 'one', $a, $b, $inputs, ['php' => 'passed']);
        expect(fn () => Maintenance::apply($plan, $inputs, 'upgrade', ['check' => fn () => true], true))->toThrow(RuntimeException::class)
            ->and(file_get_contents($path))->toBe($before)->and(file_get_contents($root->path('app/One.php')))->toBe('A');
    });
});

it('never reinterprets current target observations as the saved original intent plan', function () {
    OwnedAppRoot::using(function ($root) {
        [$a, $b, $inputs, $plan] = publicInstallPlan($root);
        Maintenance::apply($plan, $inputs, 'install', ['check' => fn () => false], true);
        $different = Checkout::inspect($root->path, 'one', $plan->baseline, $a, $inputs, ['php' => 'passed']);
        expect(fn () => Maintenance::apply($different, $inputs, 'install', ['check' => fn () => true], true))->toThrow(RuntimeException::class, 'caller original plan');
        expect(Maintenance::apply($plan, $inputs, 'install', ['check' => fn () => true], true)['status'])->toBe('completed');
    });
});

it('refuses linked generated parents while preserving the outside host file', function () {
    OwnedAppRoot::using(function ($root) {
        OwnedAppRoot::using(function ($host) use ($root) {
            $inputs = publicInputs($root);
            file_put_contents($host->path('One.php'), 'host');
            symlink($host->path, $root->path('app/Linked'));
            $a = new Artifact('example/recipe', 'A', [], [], [], ['check']);
            $b = new Artifact('example/recipe', 'B', ['app/Linked/One.php' => 'B'], [], [], ['check']);
            $plan = FilePlan::between('one', $root->path, $a, $b, [], [], ['php' => 'passed']);
            expect(fn () => Maintenance::apply($plan, $inputs, 'install', ['check' => fn () => true], true))->toThrow(RuntimeException::class)
                ->and(file_get_contents($host->path('One.php')))->toBe('host');
        });
    });
});

it('refuses root aliases and reports metadata hardlinks without changing their host inode', function () {
    OwnedAppRoot::using(function ($root) {
        [$a, $b, $inputs, $plan] = publicInstallPlan($root);
        expect(fn () => Maintenance::resume($root->path.'/.', 'one', $plan->baseline, $a, $inputs, ['php' => 'passed'], 'install', [], true))->toThrow(InvalidArgumentException::class);
        Maintenance::apply($plan, $inputs, 'install', ['check' => fn () => true], true);
        $lock = $root->path('.mod-maintenance/lock');
        link($lock, $root->path('host-lock'));
        expect(Maintenance::diagnose($plan, $inputs)['orphans'])->toBe([['path' => '.mod-maintenance/lock', 'kind' => 'hardlinked_file']])
            ->and(Maintenance::apply($plan, $inputs, 'install', [], true)['status'])->toBe('orphaned')
            ->and(fileinode($lock))->toBe(fileinode($root->path('host-lock')));
    });
});
