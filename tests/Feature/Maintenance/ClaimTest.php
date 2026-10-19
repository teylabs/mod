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
 * @param  array<string, string>  $requires
 */
function claimArtifact(string $name, string $version, array $files, array $requires = []): Artifact
{
    return new Artifact($name, $version, $files, ['core'], ['auth' => 'session'], ['check'], requires: $requires);
}

function claimEmpty(Artifact $target): Artifact
{
    return new Artifact($target->name, $target->version, [], $target->groups, $target->bindings, $target->checks);
}

/**
 * @return array<string, string>
 */
function claimInputs(OwnedAppRoot $root): array
{
    file_put_contents($root->path('policy.json'), '{"checks":["check"]}');
    file_put_contents($root->path('verifier.php'), '<?php return true;');

    return ['verification-policy' => 'policy.json', 'verification-code' => 'verifier.php'];
}

it('claims identical existing bytes on a first install without writing them', function () {
    OwnedAppRoot::using(function ($root) {
        file_put_contents($root->path('app/One.php'), 'one');
        $inode = fileinode($root->path('app/One.php'));
        $mtime = filemtime($root->path('app/One.php'));
        $target = claimArtifact('example/recipe', 'A', ['app/One.php' => 'one', 'app/Two.php' => 'two']);
        $inputs = claimInputs($root);
        $plan = Checkout::inspect($root->path, 'one', claimEmpty($target), $target, $inputs, ['php' => 'passed'], claim: ['app/One.php']);
        expect($plan->files['app/One.php']['state'])->toBe('claim')
            ->and($plan->files['app/Two.php']['state'])->toBe('create')
            ->and($plan->executable())->toBeTrue();
        $result = Maintenance::apply($plan, $inputs, 'install', ['check' => fn () => true], true);
        clearstatcache();
        expect($result['status'])->toBe('completed')
            ->and($result['written'])->toBe(['app/Two.php'])
            ->and($result['reconciled'])->toBe(['app/One.php'])
            ->and(fileinode($root->path('app/One.php')))->toBe($inode)
            ->and(filemtime($root->path('app/One.php')))->toBe($mtime)
            ->and(Checkout::installed($root->path, 'one')['example/recipe']['files'])->toBe(['app/One.php' => hash('sha256', 'one'), 'app/Two.php' => hash('sha256', 'two')]);
    });
});

it('treats an exact claimed retry as a no-op', function () {
    OwnedAppRoot::using(function ($root) {
        file_put_contents($root->path('app/One.php'), 'one');
        $target = claimArtifact('example/recipe', 'A', ['app/One.php' => 'one']);
        $inputs = claimInputs($root);
        $plan = Checkout::inspect($root->path, 'one', claimEmpty($target), $target, $inputs, ['php' => 'passed'], claim: ['app/One.php']);
        $first = Maintenance::apply($plan, $inputs, 'install', ['check' => fn () => true], true);
        $record = file_get_contents(installedRecordPath($root, 'example/recipe'));
        $again = Maintenance::apply($plan, $inputs, 'install', [], true);
        $cold = Maintenance::resume($root->path, 'one', claimEmpty($target), $target, $inputs, ['php' => 'passed'], 'install', [], true, claim: ['app/One.php']);
        expect($again['no_op'])->toBeTrue()->and($cold['no_op'])->toBeTrue()
            ->and($again['receipt'])->toBe($first['receipt'])->and($cold['receipt'])->toBe($first['receipt'])
            ->and(file_get_contents(installedRecordPath($root, 'example/recipe')))->toBe($record);
    });
});

it('refuses a claim whose bytes differ, and never overwrites them', function () {
    OwnedAppRoot::using(function ($root) {
        file_put_contents($root->path('app/One.php'), 'edited');
        $target = claimArtifact('example/recipe', 'A', ['app/One.php' => 'one']);
        $inputs = claimInputs($root);
        $plan = Checkout::inspect($root->path, 'one', claimEmpty($target), $target, $inputs, ['php' => 'passed'], claim: ['app/One.php']);
        expect($plan->files['app/One.php']['state'])->toBe('conflict')
            ->and($plan->executable())->toBeFalse()
            ->and(fn () => Maintenance::apply($plan, $inputs, 'install', ['check' => fn () => true], true))->toThrow(RuntimeException::class)
            ->and(file_get_contents($root->path('app/One.php')))->toBe('edited')
            ->and(Checkout::installed($root->path, 'one'))->toBe([]);
    });
});

it('keeps an unlisted existing path unowned even when its bytes are identical', function () {
    OwnedAppRoot::using(function ($root) {
        file_put_contents($root->path('app/One.php'), 'one');
        file_put_contents($root->path('app/Two.php'), 'two');
        $target = claimArtifact('example/recipe', 'A', ['app/One.php' => 'one', 'app/Two.php' => 'two']);
        $inputs = claimInputs($root);
        $plan = Checkout::inspect($root->path, 'one', claimEmpty($target), $target, $inputs, ['php' => 'passed'], claim: ['app/One.php']);
        expect($plan->files['app/Two.php']['state'])->toBe('unowned')
            ->and(fn () => Maintenance::apply($plan, $inputs, 'install', ['check' => fn () => true], true))->toThrow(RuntimeException::class)
            ->and(Checkout::installed($root->path, 'one'))->toBe([]);
    });
});

it('diagnoses which unowned paths are claimable without writing', function () {
    OwnedAppRoot::using(function ($root) {
        file_put_contents($root->path('app/One.php'), 'one');
        file_put_contents($root->path('app/Two.php'), 'custom');
        $target = claimArtifact('example/recipe', 'A', ['app/One.php' => 'one', 'app/Two.php' => 'two', 'app/Three.php' => 'three']);
        $inputs = claimInputs($root);
        $plan = Checkout::inspect($root->path, 'one', claimEmpty($target), $target, $inputs, ['php' => 'passed']);
        $diagnosis = Maintenance::diagnose($plan, $inputs);
        expect($diagnosis['claimable'])->toBe(['app/One.php'])
            ->and($diagnosis['executable'])->toBeFalse()
            ->and(file_exists($root->path('.mod-maintenance')))->toBeFalse();
        $claimed = Checkout::inspect($root->path, 'one', claimEmpty($target), $target, $inputs, ['php' => 'passed'], claim: $diagnosis['claimable']);
        expect(Maintenance::diagnose($claimed, $inputs)['claimable'])->toBe([]);
    });
});

it('refuses claims of paths another lineage owns or keeps host-owned', function (string $kind) {
    OwnedAppRoot::using(function ($root) use ($kind) {
        $inputs = claimInputs($root);
        $base = $kind === 'owned'
            ? claimArtifact('example/base', '1', ['app/Shared.php' => 'shared'])
            : new Artifact('example/base', '1', ['app/Base.php' => 'base'], ['core'], ['auth' => 'session'], ['check'], hostOwned: ['app/Shared.php']);
        Maintenance::apply(Checkout::inspect($root->path, 'one', claimEmpty($base), $base, $inputs, ['php' => 'passed']), $inputs, 'base', ['check' => fn () => true], true);
        file_put_contents($root->path('app/Shared.php'), 'shared');
        $target = claimArtifact('example/recipe', 'A', ['app/Shared.php' => 'shared']);
        $plan = Checkout::inspect($root->path, 'one', claimEmpty($target), $target, $inputs, ['php' => 'passed'], claim: ['app/Shared.php']);
        expect($plan->files['app/Shared.php'])->toMatchArray(['state' => 'conflict', 'reason' => ($kind === 'owned' ? 'owned_by:' : 'host_owned:').'example/base'])
            ->and(fn () => Maintenance::apply($plan, $inputs, 'install', ['check' => fn () => true], true))->toThrow(RuntimeException::class)
            ->and(array_keys(Checkout::installed($root->path, 'one')))->toBe(['example/base']);
    });
})->with(['owned', 'host']);

it('refuses a claim when a required lineage is missing', function () {
    OwnedAppRoot::using(function ($root) {
        file_put_contents($root->path('app/One.php'), 'one');
        $target = claimArtifact('example/recipe', 'A', ['app/One.php' => 'one'], ['example/base' => '*']);
        expect(fn () => Checkout::inspect($root->path, 'one', claimEmpty($target), $target, claimInputs($root), ['php' => 'passed'], claim: ['app/One.php']))->toThrow(RuntimeException::class);
    });
});

it('refuses claims that name owned or undeclared paths', function (string $path) {
    OwnedAppRoot::using(function ($root) use ($path) {
        $a = claimArtifact('example/recipe', 'A', ['app/One.php' => 'A']);
        $b = claimArtifact('example/recipe', 'B', ['app/One.php' => 'B', 'app/Two.php' => 'two']);
        expect(fn () => Checkout::inspect($root->path, 'one', $a, $b, claimInputs($root), ['php' => 'passed'], claim: [$path]))->toThrow(InvalidArgumentException::class);
    });
})->with(['baseline path' => ['app/One.php'], 'undeclared path' => ['app/Host.php']]);

it('migrates a 0.8 record and resumes an interrupted claim only with the same claim list', function () {
    OwnedAppRoot::using(function ($root) {
        $a = claimArtifact('example/recipe', 'A', ['app/One.php' => 'A']);
        $b = claimArtifact('example/recipe', 'B', ['app/One.php' => 'B', 'app/Two.php' => 'two']);
        $inputs = claimInputs($root);
        Maintenance::apply(Checkout::inspect($root->path, 'one', claimEmpty($a), $a, $inputs, ['php' => 'passed']), $inputs, 'install', ['check' => fn () => true], true);
        // Shape the checkout as 0.8 left it: one installed.json and no lineage directory.
        rename(installedRecordPath($root, 'example/recipe'), $root->path('.mod-maintenance/installed.json'));
        rmdir($root->path('.mod-maintenance/installed'));
        file_put_contents($root->path('app/Two.php'), 'two');
        $plan = Checkout::inspect($root->path, 'one', $a, $b, $inputs, ['php' => 'passed'], claim: ['app/Two.php']);
        expect($plan->files['app/Two.php']['state'])->toBe('claim')
            ->and(Maintenance::apply($plan, $inputs, 'upgrade', ['check' => fn () => false], true)['status'])->toBe('verification_failed')
            ->and(file_exists($root->path('.mod-maintenance/installed.json')))->toBeTrue();
        expect(fn () => Maintenance::resume($root->path, 'one', $a, $b, $inputs, ['php' => 'passed'], 'upgrade', ['check' => fn () => true], true))->toThrow(RuntimeException::class);
        $result = Maintenance::resume($root->path, 'one', $a, $b, $inputs, ['php' => 'passed'], 'upgrade', ['check' => fn () => true], true, claim: ['app/Two.php']);
        expect($result['status'])->toBe('completed')
            ->and($result['plan'])->toBe($plan->identity())
            ->and(file_get_contents($root->path('app/Two.php')))->toBe('two')
            ->and(file_exists($root->path('.mod-maintenance/installed.json')))->toBeFalse()
            ->and(Checkout::installed($root->path, 'one')['example/recipe'])->toMatchArray(['version' => 'B', 'files' => ['app/One.php' => hash('sha256', 'B'), 'app/Two.php' => hash('sha256', 'two')]]);
    });
});

it('keeps 0.8 plan identities when nothing is claimed', function () {
    OwnedAppRoot::using(function ($root) {
        $target = claimArtifact('example/recipe', 'A', ['app/One.php' => 'one']);
        $inputs = claimInputs($root);
        expect(Checkout::inspect($root->path, 'one', claimEmpty($target), $target, $inputs, ['php' => 'passed'], claim: [])->identity())
            ->toBe(Checkout::inspect($root->path, 'one', claimEmpty($target), $target, $inputs, ['php' => 'passed'])->identity());
    });
});

it('keeps plan identity independent of claim list order', function () {
    OwnedAppRoot::using(function ($root) {
        file_put_contents($root->path('app/One.php'), 'one');
        file_put_contents($root->path('app/Two.php'), 'two');
        $target = claimArtifact('example/recipe', 'A', ['app/One.php' => 'one', 'app/Two.php' => 'two']);
        $inputs = claimInputs($root);
        $forward = Checkout::inspect($root->path, 'one', claimEmpty($target), $target, $inputs, ['php' => 'passed'], claim: ['app/One.php', 'app/Two.php']);
        $reverse = Checkout::inspect($root->path, 'one', claimEmpty($target), $target, $inputs, ['php' => 'passed'], claim: ['app/Two.php', 'app/One.php']);
        expect($reverse->identity())->toBe($forward->identity())
            ->and($reverse->claim)->toBe(['app/One.php', 'app/Two.php']);
        Maintenance::apply($forward, $inputs, 'install', ['check' => fn () => false], true);
        expect(Maintenance::resume($root->path, 'one', claimEmpty($target), $target, $inputs, ['php' => 'passed'], 'install', ['check' => fn () => true], true, claim: ['app/Two.php', 'app/One.php'])['status'])->toBe('completed');
    });
});

it('never lets another lineage claim a file a pending journal wrote, and lets the pending lineage resume', function () {
    OwnedAppRoot::using(function ($root) {
        $inputs = claimInputs($root);
        $a = claimArtifact('example/a', '1', ['app/Shared.php' => 'same', 'app/A.php' => 'a']);
        expect(Maintenance::apply(Checkout::inspect($root->path, 'one', claimEmpty($a), $a, $inputs, ['php' => 'passed']), $inputs, 'a', ['check' => fn () => false], true)['status'])->toBe('verification_failed')
            ->and(file_get_contents($root->path('app/Shared.php')))->toBe('same');
        $b = claimArtifact('example/b', '1', ['app/Shared.php' => 'same']);
        expect(fn () => Checkout::inspect($root->path, 'one', claimEmpty($b), $b, $inputs, ['php' => 'passed'], claim: ['app/Shared.php']))->toThrow(RuntimeException::class, 'pending:example/a');
        expect(Maintenance::resume($root->path, 'one', claimEmpty($a), $a, $inputs, ['php' => 'passed'], 'a', ['check' => fn () => true], true)['status'])->toBe('completed');
        $plan = Checkout::inspect($root->path, 'one', claimEmpty($b), $b, $inputs, ['php' => 'passed'], claim: ['app/Shared.php']);
        expect($plan->files['app/Shared.php'])->toMatchArray(['state' => 'conflict', 'reason' => 'owned_by:example/a']);
    });
});
