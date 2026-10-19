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
