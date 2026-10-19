<?php

use PHPUnit\Framework\Assert;
use Tey\Mod\Maintenance\Artifact;
use Tey\Mod\Maintenance\Checkout;
use Tey\Mod\Maintenance\Maintenance;
use Tey\Mod\Tests\Support\OwnedAppRoot;

beforeEach(function () {
    if (PHP_OS_FAMILY === 'Windows') {
        Assert::markTestSkipped('Maintenance supports POSIX checkouts only.');
    }
});

/**
 * @param  array<string, string>  $files
 */
function lineageArtifact(string $name, string $version, array $files): Artifact
{
    return new Artifact($name, $version, $files, ['core'], ['auth' => 'session'], ['check']);
}

/**
 * @return array<string, string>
 */
function lineageInputs(OwnedAppRoot $root): array
{
    file_put_contents($root->path('policy.json'), '{"checks":["check"]}');
    file_put_contents($root->path('verifier.php'), '<?php return true;');

    return ['verification-policy' => 'policy.json', 'verification-code' => 'verifier.php'];
}

/**
 * @return array<string, mixed>
 */
function lineageInstall(OwnedAppRoot $root, Artifact $target, string $intent): array
{
    $inputs = lineageInputs($root);
    $empty = new Artifact($target->name, $target->version, [], $target->groups, $target->bindings, $target->checks);
    $plan = Checkout::inspect($root->path, 'one', $empty, $target, $inputs, ['php' => 'passed']);

    return Maintenance::apply($plan, $inputs, $intent, ['check' => fn () => true], true);
}

/**
 * Write a 0.8 single-lineage checkout by hand: owner, installed.json and the owned file.
 */
function legacyCheckout(OwnedAppRoot $root, Artifact $installed): void
{
    mkdir($root->path('.mod-maintenance'), 0700);
    file_put_contents($root->path('.mod-maintenance/owner.json'), json_encode(['format' => 1, 'owner' => 'tey/mod:maintenance', 'app_id' => 'one', 'root' => $root->path]));
    file_put_contents($root->path('.mod-maintenance/installed.json'), json_encode([
        'format' => 1,
        'owner' => 'tey/mod:installed',
        'app_id' => 'one',
        'root' => $root->path,
        'artifact' => [
            'identity' => $installed->identity(),
            'name' => $installed->name,
            'version' => $installed->version,
            'files' => array_map(base64_encode(...), $installed->files),
            'groups' => $installed->groups,
            'bindings' => $installed->bindings,
            'checks' => $installed->checks,
        ],
        'commit' => [
            'intent' => 'install',
            'plan' => 'sha256:'.str_repeat('a', 64),
            'inputs' => ['verification-policy' => 'sha256:'.str_repeat('b', 64)],
            'compatibility' => ['php' => 'passed'],
            'checks' => ['check' => 'passed'],
        ],
    ]));
    foreach ($installed->files as $path => $bytes) {
        file_put_contents($root->path($path), $bytes);
    }
}

it('installs lineages side by side, each under its own record', function () {
    OwnedAppRoot::using(function ($root) {
        expect(Checkout::installed($root->path, 'one'))->toBe([]);
        $billing = lineageArtifact('example/blueprint:billing', '1.0.0', ['app/Billing.php' => 'billing']);
        $teams = lineageArtifact('example/blueprint:teams', '2.0.0', ['app/Teams.php' => 'teams']);
        expect(lineageInstall($root, $billing, 'billing')['status'])->toBe('completed')
            ->and(lineageInstall($root, $teams, 'teams')['status'])->toBe('completed');
        expect(Checkout::installed($root->path, 'one'))->toBe([
            'example/blueprint:billing' => ['identity' => $billing->identity(), 'version' => '1.0.0', 'files' => ['app/Billing.php' => hash('sha256', 'billing')]],
            'example/blueprint:teams' => ['identity' => $teams->identity(), 'version' => '2.0.0', 'files' => ['app/Teams.php' => hash('sha256', 'teams')]],
        ])->and(file_exists(installedRecordPath($root, 'example/blueprint:billing')))->toBeTrue()
            ->and(file_exists(installedRecordPath($root, 'example/blueprint:teams')))->toBeTrue()
            ->and(file_exists($root->path('.mod-maintenance/installed.json')))->toBeFalse();
    });
});

it('reads a 0.8 checkout and migrates its record on the first upgrade write', function () {
    OwnedAppRoot::using(function ($root) {
        $a = lineageArtifact('example/recipe', 'A', ['app/One.php' => 'A']);
        $b = lineageArtifact('example/recipe', 'B', ['app/One.php' => 'B']);
        legacyCheckout($root, $a);
        expect(Checkout::installed($root->path, 'one'))->toBe(['example/recipe' => ['identity' => $a->identity(), 'version' => 'A', 'files' => ['app/One.php' => hash('sha256', 'A')]]]);
        $inputs = lineageInputs($root);
        $plan = Checkout::inspect($root->path, 'one', $a, $b, $inputs, ['php' => 'passed']);
        $result = Maintenance::apply($plan, $inputs, 'upgrade', ['check' => fn () => true], true);
        expect($result['status'])->toBe('completed')->and($result['written'])->toBe(['app/One.php'])
            ->and(file_exists($root->path('.mod-maintenance/installed.json')))->toBeFalse()
            ->and(json_decode(file_get_contents(installedRecordPath($root, 'example/recipe')), true)['artifact']['version'])->toBe('B')
            ->and(Checkout::installed($root->path, 'one')['example/recipe']['version'])->toBe('B');
        $again = Maintenance::apply($plan, $inputs, 'upgrade', [], true);
        expect($again['no_op'])->toBeTrue()->and($again['receipt'])->toBe($result['receipt']);
    });
});

it('keeps a 0.8 record of another lineage in place when a new lineage installs', function () {
    OwnedAppRoot::using(function ($root) {
        $a = lineageArtifact('example/recipe', 'A', ['app/One.php' => 'A']);
        legacyCheckout($root, $a);
        $before = file_get_contents($root->path('.mod-maintenance/installed.json'));
        expect(lineageInstall($root, lineageArtifact('example/other', '1', ['app/Other.php' => 'other']), 'other')['status'])->toBe('completed')
            ->and(file_get_contents($root->path('.mod-maintenance/installed.json')))->toBe($before)
            ->and(array_keys(Checkout::installed($root->path, 'one')))->toBe(['example/other', 'example/recipe']);
    });
});

it('prefers the lineage record over a superseded 0.8 copy and retires it on the next write', function () {
    OwnedAppRoot::using(function ($root) {
        $a = lineageArtifact('example/recipe', 'A', ['app/One.php' => 'A']);
        $b = lineageArtifact('example/recipe', 'B', ['app/One.php' => 'B']);
        $c = lineageArtifact('example/recipe', 'C', ['app/One.php' => 'C']);
        expect(lineageInstall($root, $a, 'install')['status'])->toBe('completed');
        $inputs = lineageInputs($root);
        expect(Maintenance::apply(Checkout::inspect($root->path, 'one', $a, $b, $inputs, ['php' => 'passed']), $inputs, 'upgrade-b', ['check' => fn () => true], true)['status'])->toBe('completed');
        // An interrupted migration: the 0.8 copy of version A survived next to the lineage record.
        $stale = json_decode(file_get_contents(installedRecordPath($root, 'example/recipe')), true);
        $stale['artifact'] = ['identity' => $a->identity(), 'name' => 'example/recipe', 'version' => 'A', 'files' => ['app/One.php' => base64_encode('A')], 'groups' => ['core'], 'bindings' => ['auth' => 'session'], 'checks' => ['check']];
        file_put_contents($root->path('.mod-maintenance/installed.json'), json_encode($stale));
        expect(Checkout::installed($root->path, 'one')['example/recipe']['version'])->toBe('B');
        $result = Maintenance::apply(Checkout::inspect($root->path, 'one', $b, $c, $inputs, ['php' => 'passed']), $inputs, 'upgrade-c', ['check' => fn () => true], true);
        expect($result['status'])->toBe('completed')
            ->and(file_exists($root->path('.mod-maintenance/installed.json')))->toBeFalse();
    });
});

it('refuses foreign or misfiled lineage records', function (string $kind) {
    OwnedAppRoot::using(function ($root) use ($kind) {
        lineageInstall($root, lineageArtifact('example/recipe', 'A', ['app/One.php' => 'A']), 'install');
        $path = installedRecordPath($root, 'example/recipe');
        $record = json_decode(file_get_contents($path), true);
        match ($kind) {
            'app' => $record['app_id'] = 'foreign',
            'bytes' => $record['artifact']['files']['app/One.php'] = base64_encode('forged'),
            'encoding' => $record['artifact']['files']['app/One.php'] = '%%%',
            'misfiled' => rename($path, installedRecordPath($root, 'example/elsewhere')),
            default => throw new InvalidArgumentException("Unknown kind {$kind}."),
        };
        if ($kind !== 'misfiled') {
            file_put_contents($path, json_encode($record));
        }
        expect(fn () => Checkout::installed($root->path, 'one'))->toThrow(RuntimeException::class);
    });
})->with(['app', 'bytes', 'encoding', 'misfiled']);

it('reports interrupted lineage record writes as orphans', function () {
    OwnedAppRoot::using(function ($root) {
        $a = lineageArtifact('example/recipe', 'A', ['app/One.php' => 'A']);
        lineageInstall($root, $a, 'install');
        $partial = '.mod-maintenance/installed/'.hash('sha256', 'example/recipe').'.json.tmp-abc';
        file_put_contents($root->path($partial), 'partial');
        $inputs = lineageInputs($root);
        $plan = Checkout::inspect($root->path, 'one', $a, lineageArtifact('example/recipe', 'B', ['app/One.php' => 'B']), $inputs, ['php' => 'passed']);
        expect(Maintenance::diagnose($plan, $inputs)['orphans'])->toBe([['path' => $partial, 'kind' => 'file']])
            ->and(Checkout::installed($root->path, 'one'))->toHaveKey('example/recipe');
    });
});

it('refuses a path another lineage owns as a conflict naming that lineage', function () {
    OwnedAppRoot::using(function ($root) {
        lineageInstall($root, lineageArtifact('example/base', '1', ['app/Shared.php' => 'base']), 'base');
        $inputs = lineageInputs($root);
        $other = lineageArtifact('example/other', '1', ['app/Shared.php' => 'base', 'app/Other.php' => 'other']);
        $empty = new Artifact($other->name, $other->version, [], $other->groups, $other->bindings, $other->checks);
        $plan = Checkout::inspect($root->path, 'one', $empty, $other, $inputs, ['php' => 'passed']);
        expect($plan->files['app/Shared.php'])->toMatchArray(['state' => 'conflict', 'reason' => 'owned_by:example/base'])
            ->and($plan->files['app/Other.php']['state'])->toBe('create')->and($plan->files['app/Other.php'])->not->toHaveKey('reason')
            ->and($plan->executable())->toBeFalse()
            ->and(fn () => Maintenance::apply($plan, $inputs, 'other', ['check' => fn () => true], true))->toThrow(RuntimeException::class)
            ->and(file_get_contents($root->path('app/Shared.php')))->toBe('base')
            ->and(file_exists($root->path('app/Other.php')))->toBeFalse()
            ->and(array_keys(Checkout::installed($root->path, 'one')))->toBe(['example/base']);
    });
});

it('refuses ownership by case alias of another lineage path', function () {
    OwnedAppRoot::using(function ($root) {
        lineageInstall($root, lineageArtifact('example/base', '1', ['app/Shared.php' => 'base']), 'base');
        $other = lineageArtifact('example/other', '1', ['app/shared.php' => 'other']);
        $empty = new Artifact($other->name, $other->version, [], $other->groups, $other->bindings, $other->checks);
        expect(fn () => Checkout::inspect($root->path, 'one', $empty, $other, lineageInputs($root), ['php' => 'passed']))->toThrow(RuntimeException::class);
    });
});

it('refuses an in-flight install once another lineage takes the path', function () {
    OwnedAppRoot::using(function ($root) {
        $inputs = lineageInputs($root);
        $other = lineageArtifact('example/other', '1', ['app/Shared.php' => 'other']);
        $empty = new Artifact($other->name, $other->version, [], $other->groups, $other->bindings, $other->checks);
        $plan = Checkout::inspect($root->path, 'one', $empty, $other, $inputs, ['php' => 'passed']);
        expect(Maintenance::apply($plan, $inputs, 'other', ['check' => fn () => false], true)['status'])->toBe('verification_failed');
        // Hand-place a competing lineage record that owns the same path.
        $base = lineageArtifact('example/base', '1', ['app/Shared.php' => 'other']);
        @mkdir($root->path('.mod-maintenance/installed'), 0700);
        file_put_contents(installedRecordPath($root, 'example/base'), json_encode(['format' => 1, 'owner' => 'tey/mod:installed', 'app_id' => 'one', 'root' => $root->path, 'artifact' => ['identity' => $base->identity(), 'name' => 'example/base', 'version' => '1', 'files' => ['app/Shared.php' => base64_encode('other')], 'groups' => ['core'], 'bindings' => ['auth' => 'session'], 'checks' => ['check']], 'commit' => ['intent' => 'base', 'plan' => 'sha256:'.str_repeat('a', 64), 'inputs' => [], 'compatibility' => ['php' => 'passed'], 'checks' => ['check' => 'passed']]]));
        expect(fn () => Maintenance::resume($root->path, 'one', $empty, $other, $inputs, ['php' => 'passed'], 'other', ['check' => fn () => true], true))->toThrow(RuntimeException::class)
            ->and(file_exists(installedRecordPath($root, 'example/other')))->toBeFalse();
    });
});

it('requires named lineages to be installed, at the exact version when one is given', function (array $requires, bool $passes) {
    OwnedAppRoot::using(function ($root) use ($requires, $passes) {
        lineageInstall($root, lineageArtifact('example/base', '1.0.0', ['app/Base.php' => 'base']), 'base');
        $inputs = lineageInputs($root);
        $other = new Artifact('example/other', '1', ['app/Other.php' => 'other'], ['core'], ['auth' => 'session'], ['check'], requires: $requires);
        $empty = new Artifact($other->name, $other->version, [], $other->groups, $other->bindings, $other->checks);
        if ($passes) {
            $plan = Checkout::inspect($root->path, 'one', $empty, $other, $inputs, ['php' => 'passed']);
            expect(Maintenance::apply($plan, $inputs, 'other', ['check' => fn () => true], true)['status'])->toBe('completed');
            $saved = json_decode(file_get_contents(installedRecordPath($root, 'example/other')), true);
            expect($saved['artifact']['requires'])->toBe($requires)
                ->and(Checkout::installed($root->path, 'one')['example/other']['identity'])->toBe($other->identity());
        } else {
            expect(fn () => Checkout::inspect($root->path, 'one', $empty, $other, $inputs, ['php' => 'passed']))->toThrow(RuntimeException::class)
                ->and(file_exists($root->path('app/Other.php')))->toBeFalse();
        }
    });
})->with([
    'any version' => [['example/base' => '*'], true],
    'exact version' => [['example/base' => '1.0.0'], true],
    'other version' => [['example/base' => '2.0.0'], false],
    'missing lineage' => [['example/absent' => '*'], false],
]);

it('refuses upgrading a lineage away from the exact version another lineage requires', function (string $constraint, bool $passes) {
    OwnedAppRoot::using(function ($root) use ($constraint, $passes) {
        $base = lineageArtifact('example/base', '1.0.0', ['app/Base.php' => 'one']);
        lineageInstall($root, $base, 'base');
        $other = new Artifact('example/other', '1', ['app/Other.php' => 'other'], ['core'], ['auth' => 'session'], ['check'], requires: ['example/base' => $constraint]);
        lineageInstall($root, $other, 'other');
        $inputs = lineageInputs($root);
        $next = lineageArtifact('example/base', '2.0.0', ['app/Base.php' => 'two']);
        if ($passes) {
            $plan = Checkout::inspect($root->path, 'one', $base, $next, $inputs, ['php' => 'passed']);
            expect(Maintenance::apply($plan, $inputs, 'upgrade', ['check' => fn () => true], true)['status'])->toBe('completed');
        } else {
            expect(fn () => Checkout::inspect($root->path, 'one', $base, $next, $inputs, ['php' => 'passed']))->toThrow(RuntimeException::class, 'required_by:example/other')
                ->and(file_get_contents($root->path('app/Base.php')))->toBe('one')
                ->and(Checkout::installed($root->path, 'one')['example/base']['version'])->toBe('1.0.0');
        }
    });
})->with(['exact version blocks' => ['1.0.0', false], 'any version never blocks' => ['*', true]]);

it('allows an upgrade that keeps the exact version another lineage requires', function () {
    OwnedAppRoot::using(function ($root) {
        $base = lineageArtifact('example/base', '1.0.0', ['app/Base.php' => 'one']);
        lineageInstall($root, $base, 'base');
        lineageInstall($root, new Artifact('example/other', '1', ['app/Other.php' => 'other'], ['core'], ['auth' => 'session'], ['check'], requires: ['example/base' => '1.0.0']), 'other');
        $inputs = lineageInputs($root);
        $plan = Checkout::inspect($root->path, 'one', $base, lineageArtifact('example/base', '1.0.0', ['app/Base.php' => 'patched']), $inputs, ['php' => 'passed']);
        expect(Maintenance::apply($plan, $inputs, 'patch', ['check' => fn () => true], true)['status'])->toBe('completed');
    });
});
