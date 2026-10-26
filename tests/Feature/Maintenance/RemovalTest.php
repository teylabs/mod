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

/** @param array<string, string> $files */
function removalArtifact(string $name, string $version, array $files): Artifact
{
    return new Artifact($name, $version, $files, ['core'], ['auth' => 'session'], ['check']);
}

/** @return array<string, string> */
function removalInputs(OwnedAppRoot $root): array
{
    file_put_contents($root->path('policy.json'), '{"checks":["check"]}');
    file_put_contents($root->path('verifier.php'), '<?php return true;');

    return ['verification-policy' => 'policy.json', 'verification-code' => 'verifier.php'];
}

/**
 * Install version A of a lineage owning both files.
 *
 * @param  array<string, string>  $inputs
 * @param  array<string, string>  $files
 */
function removalInstall(OwnedAppRoot $root, array $inputs, string $name = 'example/recipe', array $files = ['app/Keep.php' => 'keep', 'app/Gone.php' => 'gone']): Artifact
{
    $target = removalArtifact($name, 'A', $files);
    $empty = removalArtifact($name, 'A', []);
    $plan = Checkout::inspect($root->path, 'one', $empty, $target, $inputs, ['php' => 'passed']);
    expect(Maintenance::apply($plan, $inputs, 'install-'.$name, ['check' => fn () => true], true)['status'])->toBe('completed');

    return $target;
}

it('refuses a dropped baseline path without a policy, an extra policy and an unknown policy', function () {
    $baseline = removalArtifact('example/recipe', 'A', ['app/Keep.php' => 'keep', 'app/Gone.php' => 'gone']);
    $target = removalArtifact('example/recipe', 'B', ['app/Keep.php' => 'keep']);
    $plan = fn (array $removals) => FilePlan::between('one', '/app', $baseline, $target, [], [], ['php' => 'passed'], removals: $removals);

    expect(fn () => $plan([]))->toThrow(InvalidArgumentException::class, 'Removals require explicit policy')
        ->and(fn () => $plan(['app/Gone.php' => 'delete', 'app/Keep.php' => 'delete']))->toThrow(InvalidArgumentException::class, 'Removals require explicit policy')
        ->and(fn () => $plan(['app/Gone.php' => 'archive']))->toThrow(InvalidArgumentException::class, 'delete or release');
});

it('classifies removals and makes the policy part of plan identity', function () {
    $baseline = removalArtifact('example/recipe', 'A', ['app/One.php' => 'one', 'app/Two.php' => 'two', 'app/Three.php' => 'three']);
    $target = removalArtifact('example/recipe', 'B', []);
    $current = ['app/One.php' => 'one', 'app/Two.php' => 'edited'];
    $delete = FilePlan::between('one', '/app', $baseline, $target, $current, [], ['php' => 'passed'], removals: ['app/One.php' => 'delete', 'app/Two.php' => 'delete', 'app/Three.php' => 'delete']);
    $release = FilePlan::between('one', '/app', $baseline, $target, $current, [], ['php' => 'passed'], removals: ['app/Three.php' => 'release', 'app/Two.php' => 'release', 'app/One.php' => 'release']);

    expect(array_map(fn (array $entry) => $entry['state'], $delete->files))->toBe(['app/One.php' => 'remove', 'app/Three.php' => 'gone', 'app/Two.php' => 'conflict'])
        ->and($delete->files['app/Two.php']['reason'] ?? null)->toBe('modified')
        ->and($delete->executable())->toBeFalse()
        ->and(array_map(fn (array $entry) => $entry['state'], $release->files))->toBe(['app/One.php' => 'release', 'app/Three.php' => 'gone', 'app/Two.php' => 'release'])
        ->and($release->executable())->toBeTrue()
        ->and($release->identity())->not->toBe($delete->identity());
});

it('removes an untouched file through the journal and drops its ownership', function () {
    OwnedAppRoot::using(function ($root) {
        $inputs = removalInputs($root);
        $baseline = removalInstall($root, $inputs);
        $target = removalArtifact('example/recipe', 'B', ['app/Keep.php' => 'keep']);
        $plan = Checkout::inspect($root->path, 'one', $baseline, $target, $inputs, ['php' => 'passed'], removals: ['app/Gone.php' => 'delete']);
        expect($plan->files['app/Gone.php']['state'])->toBe('remove')
            ->and(Maintenance::diagnose($plan, $inputs)['removals'])->toBe([['path' => 'app/Gone.php', 'state' => 'remove']]);

        $result = Maintenance::apply($plan, $inputs, 'split', ['check' => fn () => true], true);

        expect($result['status'])->toBe('completed')
            ->and($result['removed'])->toBe(['app/Gone.php'])
            ->and(file_exists($root->path('app/Gone.php')))->toBeFalse()
            ->and(Checkout::installed($root->path, 'one')['example/recipe']['files'])->toBe(['app/Keep.php' => hash('sha256', 'keep')]);
    });
});

it('refuses to delete an edited file and reports its diff and the manual step', function () {
    OwnedAppRoot::using(function ($root) {
        $inputs = removalInputs($root);
        $baseline = removalInstall($root, $inputs);
        file_put_contents($root->path('app/Gone.php'), 'edited');
        $target = removalArtifact('example/recipe', 'B', ['app/Keep.php' => 'keep']);
        $plan = Checkout::inspect($root->path, 'one', $baseline, $target, $inputs, ['php' => 'passed'], removals: ['app/Gone.php' => 'delete']);
        $report = Maintenance::diagnose($plan, $inputs);

        expect($plan->files['app/Gone.php']['state'])->toBe('conflict')
            ->and($plan->files['app/Gone.php']['reason'] ?? null)->toBe('modified')
            ->and($report['executable'])->toBeFalse()
            ->and($report['removals'][0]['diff'] ?? null)->toBe("--- baseline\n+++ current\n-gone\n+edited")
            ->and($report['removals'][0]['step'] ?? null)->toContain('release')
            ->and(fn () => Maintenance::apply($plan, $inputs, 'split', ['check' => fn () => true], true))->toThrow(RuntimeException::class)
            ->and(file_get_contents($root->path('app/Gone.php')))->toBe('edited');
    });
});

it('releases a file, edited or not, keeping its bytes and dropping ownership', function () {
    OwnedAppRoot::using(function ($root) {
        $inputs = removalInputs($root);
        $baseline = removalInstall($root, $inputs);
        file_put_contents($root->path('app/Gone.php'), 'edited');
        $target = removalArtifact('example/recipe', 'B', ['app/Keep.php' => 'keep']);
        $plan = Checkout::inspect($root->path, 'one', $baseline, $target, $inputs, ['php' => 'passed'], removals: ['app/Gone.php' => 'release']);

        $result = Maintenance::apply($plan, $inputs, 'split', ['check' => fn () => true], true);

        expect($plan->files['app/Gone.php']['state'])->toBe('release')
            ->and($result['status'])->toBe('completed')
            ->and($result['removed'])->toBe([])
            ->and(file_get_contents($root->path('app/Gone.php')))->toBe('edited')
            ->and(Checkout::installed($root->path, 'one')['example/recipe']['files'])->toBe(['app/Keep.php' => hash('sha256', 'keep')]);
    });
});

it('moves a file between lineages by release then claim with identical bytes', function () {
    OwnedAppRoot::using(function ($root) {
        $inputs = removalInputs($root);
        $giver = removalInstall($root, $inputs);
        $released = removalArtifact('example/recipe', 'B', ['app/Keep.php' => 'keep']);
        $plan = Checkout::inspect($root->path, 'one', $giver, $released, $inputs, ['php' => 'passed'], removals: ['app/Gone.php' => 'release']);
        expect(Maintenance::apply($plan, $inputs, 'release', ['check' => fn () => true], true)['status'])->toBe('completed');

        $receiver = removalArtifact('example/other', 'A', ['app/Gone.php' => 'gone']);
        $claim = Checkout::inspect($root->path, 'one', removalArtifact('example/other', 'A', []), $receiver, $inputs, ['php' => 'passed'], claim: ['app/Gone.php']);
        $result = Maintenance::apply($claim, $inputs, 'claim', ['check' => fn () => true], true);

        expect($claim->files['app/Gone.php']['state'])->toBe('claim')
            ->and($result['written'])->toBe([])
            ->and(array_map(fn (array $lineage) => $lineage['files'], Checkout::installed($root->path, 'one')))->toBe([
                'example/other' => ['app/Gone.php' => hash('sha256', 'gone')],
                'example/recipe' => ['app/Keep.php' => hash('sha256', 'keep')],
            ]);
    });
});

it('refuses the receiving claim when the released file was edited', function () {
    OwnedAppRoot::using(function ($root) {
        $inputs = removalInputs($root);
        $giver = removalInstall($root, $inputs);
        $released = removalArtifact('example/recipe', 'B', ['app/Keep.php' => 'keep']);
        $plan = Checkout::inspect($root->path, 'one', $giver, $released, $inputs, ['php' => 'passed'], removals: ['app/Gone.php' => 'release']);
        Maintenance::apply($plan, $inputs, 'release', ['check' => fn () => true], true);
        file_put_contents($root->path('app/Gone.php'), 'edited');

        $receiver = removalArtifact('example/other', 'A', ['app/Gone.php' => 'gone']);
        $claim = Checkout::inspect($root->path, 'one', removalArtifact('example/other', 'A', []), $receiver, $inputs, ['php' => 'passed'], claim: ['app/Gone.php']);

        expect($claim->files['app/Gone.php']['state'])->toBe('conflict')
            ->and($claim->executable())->toBeFalse()
            ->and(file_get_contents($root->path('app/Gone.php')))->toBe('edited');
    });
});

it('treats a re-run as idempotent: a completed removal is a no-op and an absent file is gone', function () {
    OwnedAppRoot::using(function ($root) {
        $inputs = removalInputs($root);
        $baseline = removalInstall($root, $inputs);
        $target = removalArtifact('example/recipe', 'B', ['app/Keep.php' => 'keep']);
        $plan = Checkout::inspect($root->path, 'one', $baseline, $target, $inputs, ['php' => 'passed'], removals: ['app/Gone.php' => 'delete']);
        $first = Maintenance::apply($plan, $inputs, 'split', ['check' => fn () => true], true);
        $again = Maintenance::apply($plan, $inputs, 'split', [], true);
        $cold = Maintenance::resume($root->path, 'one', $baseline, $target, $inputs, ['php' => 'passed'], 'split', [], true, removals: ['app/Gone.php' => 'delete']);
        $fresh = FilePlan::between('one', $root->path, $baseline, $target, ['app/Keep.php' => 'keep'], [], ['php' => 'passed'], removals: ['app/Gone.php' => 'delete']);

        expect($again['no_op'])->toBeTrue()->and($cold['no_op'])->toBeTrue()
            ->and($again['receipt'])->toBe($first['receipt'])
            ->and($fresh->files['app/Gone.php']['state'])->toBe('gone');
    });
});

it('rolls a removal back when checks fail, and removes it again on resume', function () {
    OwnedAppRoot::using(function ($root) {
        $inputs = removalInputs($root);
        $baseline = removalInstall($root, $inputs);
        $target = removalArtifact('example/recipe', 'B', ['app/Keep.php' => 'keep']);
        $plan = Checkout::inspect($root->path, 'one', $baseline, $target, $inputs, ['php' => 'passed'], removals: ['app/Gone.php' => 'delete']);
        $seen = null;

        $failed = Maintenance::apply($plan, $inputs, 'split', ['check' => function () use ($root, &$seen) {
            $seen = file_exists($root->path('app/Gone.php'));

            return false;
        }], true);

        expect($failed['status'])->toBe('verification_failed')
            ->and($seen)->toBeFalse()
            ->and($failed['removed'])->toBe([])
            ->and(file_get_contents($root->path('app/Gone.php')))->toBe('gone')
            ->and(Checkout::installed($root->path, 'one')['example/recipe']['version'])->toBe('A');

        $resumed = Maintenance::resume($root->path, 'one', $baseline, $target, $inputs, ['php' => 'passed'], 'split', ['check' => fn () => true], true, removals: ['app/Gone.php' => 'delete']);

        expect($resumed['status'])->toBe('completed')
            ->and($resumed['removed'])->toBe(['app/Gone.php'])
            ->and(file_exists($root->path('app/Gone.php')))->toBeFalse();
    });
});

/**
 * Run one update of a lineage, expect it to complete, and expect an immediate retry to be a no-op.
 *
 * @param  array<string, string>  $inputs
 * @param  list<string>  $claim
 * @param  array<string, string>  $removals
 * @return array{status: string, no_op: bool, plan: ?string, receipt: ?string, written: list<string>, reconciled: list<string>, removed: list<string>, checks: array<string, string>, orphans: list<array{path: string, kind: string}>}
 */
function removalUpdate(OwnedAppRoot $root, array $inputs, Artifact $baseline, Artifact $target, string $intent, array $claim = [], array $removals = []): array
{
    $plan = Checkout::inspect($root->path, 'one', $baseline, $target, $inputs, ['php' => 'passed'], $claim, $removals);
    $result = Maintenance::apply($plan, $inputs, $intent, ['check' => fn () => true], true);
    expect($result['status'])->toBe('completed')
        ->and(Maintenance::apply($plan, $inputs, $intent, [], true)['no_op'])->toBeTrue();

    return $result;
}

it('moves a file whose bytes change to a lineage the giver will require, in four updates', function () {
    OwnedAppRoot::using(function ($root) {
        $inputs = removalInputs($root);
        $giver = removalInstall($root, $inputs, files: ['app/Keep.php' => 'keep', 'app/Moved.php' => 'moved']);
        $interim = removalArtifact('example/recipe', 'B-interim', ['app/Keep.php' => 'keep']);
        $final = new Artifact('example/recipe', 'B', ['app/Keep.php' => 'keep v2'], ['core'], ['auth' => 'session'], ['check'], requires: ['example/other' => 'B']);
        $empty = removalArtifact('example/other', 'A', []);
        $released = removalArtifact('example/other', 'A', ['app/Moved.php' => 'moved']);
        $receiver = removalArtifact('example/other', 'B', ['app/Moved.php' => 'moved v2']);

        // The final giver requires a receiver that isn't installed yet.
        expect(fn () => Checkout::inspect($root->path, 'one', $giver, $final, $inputs, ['php' => 'passed'], removals: ['app/Moved.php' => 'release']))
            ->toThrow(RuntimeException::class, 'Required lineage example/other is not installed at version B.');

        // 1. Release through an interim giver target without the requirement.
        removalUpdate($root, $inputs, $giver, $interim, 'move-1-release', removals: ['app/Moved.php' => 'release']);
        // 2. Install the receiver at the released bytes, claiming them.
        $claimed = removalUpdate($root, $inputs, $empty, $released, 'move-2-claim', claim: ['app/Moved.php']);
        // 3. Update the receiver to its final bytes.
        $changed = removalUpdate($root, $inputs, $released, $receiver, 'move-3-receiver');
        // The finished release stays safe to retry after the receiver rewrote the released file.
        expect(Maintenance::resume($root->path, 'one', $giver, $interim, $inputs, ['php' => 'passed'], 'move-1-release', [], true, removals: ['app/Moved.php' => 'release'])['no_op'])->toBeTrue();
        // 4. Update the giver to its final target, which requires the receiver.
        removalUpdate($root, $inputs, $interim, $final, 'move-4-giver');

        expect($claimed['written'])->toBe([])
            ->and($changed['written'])->toBe(['app/Moved.php'])
            ->and(file_get_contents($root->path('app/Moved.php')))->toBe('moved v2')
            ->and(array_map(fn (array $lineage) => [$lineage['version'], $lineage['files']], Checkout::installed($root->path, 'one')))->toBe([
                'example/other' => ['B', ['app/Moved.php' => hash('sha256', 'moved v2')]],
                'example/recipe' => ['B', ['app/Keep.php' => hash('sha256', 'keep v2')]],
            ]);

        // Resuming the final steps from cold is a no-op.
        $again = [
            Maintenance::resume($root->path, 'one', $released, $receiver, $inputs, ['php' => 'passed'], 'move-3-receiver', [], true),
            Maintenance::resume($root->path, 'one', $interim, $final, $inputs, ['php' => 'passed'], 'move-4-giver', [], true),
        ];
        expect(array_column($again, 'no_op'))->toBe([true, true])
            ->and(file_get_contents($root->path('app/Moved.php')))->toBe('moved v2');
    });
});

it('refuses the four-step move at the claim when the released file was edited', function () {
    OwnedAppRoot::using(function ($root) {
        $inputs = removalInputs($root);
        $giver = removalInstall($root, $inputs, files: ['app/Keep.php' => 'keep', 'app/Moved.php' => 'moved']);
        $interim = removalArtifact('example/recipe', 'B-interim', ['app/Keep.php' => 'keep']);
        removalUpdate($root, $inputs, $giver, $interim, 'move-1-release', removals: ['app/Moved.php' => 'release']);
        file_put_contents($root->path('app/Moved.php'), 'edited');

        $plan = Checkout::inspect($root->path, 'one', removalArtifact('example/other', 'A', []), removalArtifact('example/other', 'A', ['app/Moved.php' => 'moved']), $inputs, ['php' => 'passed'], ['app/Moved.php']);

        expect($plan->files['app/Moved.php']['state'])->toBe('conflict')
            ->and(fn () => Maintenance::apply($plan, $inputs, 'move-2-claim', ['check' => fn () => true], true))->toThrow(RuntimeException::class)
            ->and(file_get_contents($root->path('app/Moved.php')))->toBe('edited')
            ->and(array_keys(Checkout::installed($root->path, 'one')))->toBe(['example/recipe']);
    });
});

it('still protects the bytes of a release that never committed', function () {
    OwnedAppRoot::using(function ($root) {
        $inputs = removalInputs($root);
        $giver = removalInstall($root, $inputs, files: ['app/Keep.php' => 'keep', 'app/Moved.php' => 'moved']);
        $interim = removalArtifact('example/recipe', 'B-interim', ['app/Keep.php' => 'keep']);
        $plan = Checkout::inspect($root->path, 'one', $giver, $interim, $inputs, ['php' => 'passed'], removals: ['app/Moved.php' => 'release']);
        expect(Maintenance::apply($plan, $inputs, 'move-1-release', ['check' => fn () => false], true)['status'])->toBe('verification_failed');
        file_put_contents($root->path('app/Moved.php'), 'edited');

        expect(fn () => Maintenance::resume($root->path, 'one', $giver, $interim, $inputs, ['php' => 'passed'], 'move-1-release', ['check' => fn () => true], true, removals: ['app/Moved.php' => 'release']))
            ->toThrow(RuntimeException::class, 'Third file value blocks maintenance recovery.')
            ->and(file_get_contents($root->path('app/Moved.php')))->toBe('edited')
            ->and(Checkout::installed($root->path, 'one')['example/recipe']['files'])->toHaveKey('app/Moved.php');
    });
});
