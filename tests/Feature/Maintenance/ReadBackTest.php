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
function readBackArtifact(string $name, string $version, array $files): Artifact
{
    return new Artifact($name, $version, $files, ['core'], ['auth' => 'session'], ['check'], metadata: ['source' => 'tests']);
}

function readBackEmpty(Artifact $target): Artifact
{
    return new Artifact($target->name, $target->version, [], $target->groups, $target->bindings, $target->checks, metadata: $target->metadata);
}

/**
 * @return array<string, string>
 */
function readBackInputs(OwnedAppRoot $root): array
{
    file_put_contents($root->path('policy.json'), '{"checks":["check"]}');
    file_put_contents($root->path('verifier.php'), '<?php return true;');

    return ['verification-policy' => 'policy.json', 'verification-code' => 'verifier.php'];
}

/**
 * @param  list<string>  $claim
 * @return array<string, mixed>
 */
function readBackApply(OwnedAppRoot $root, Artifact $baseline, Artifact $target, string $intent, bool $passes = true, array $claim = []): array
{
    $inputs = readBackInputs($root);
    $plan = Checkout::inspect($root->path, 'one', $baseline, $target, $inputs, ['php' => 'passed'], $claim);

    return Maintenance::apply($plan, $inputs, $intent, ['check' => fn () => $passes], true);
}

/**
 * Turn a lineage-named journal into one written by 0.8, which names no lineage.
 */
function readBackLegacyJournal(OwnedAppRoot $root, string $intent): void
{
    $path = $root->path('.mod-maintenance/journal-'.hash('sha256', $intent).'.json');
    $journal = json_decode(file_get_contents($path), true);
    unset($journal['envelope']['lineage']);
    file_put_contents($path, json_encode($journal, JSON_UNESCAPED_SLASHES)."\n");
}

/**
 * Write a 0.8 single-lineage checkout by hand: owner, installed.json and the owned file.
 */
function readBackLegacyCheckout(OwnedAppRoot $root, Artifact $installed): void
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

it('reads back an installed lineage with the identity it was installed with', function () {
    OwnedAppRoot::using(function ($root) {
        $target = readBackArtifact('example/recipe', 'A', ['app/One.php' => 'one']);
        expect(readBackApply($root, readBackEmpty($target), $target, 'install')['status'])->toBe('completed');
        $artifact = Checkout::artifact($root->path, 'one', 'example/recipe');
        expect($artifact)->toBeInstanceOf(Artifact::class)
            ->and($artifact->identity())->toBe($target->identity())
            ->and($artifact->identity())->toBe(Checkout::installed($root->path, 'one')['example/recipe']['identity'])
            ->and($artifact->files)->toBe($target->files)
            ->and($artifact->metadata)->toBe($target->metadata);
    });
});

it('reads back a lineage installed by claim with its target identity', function () {
    OwnedAppRoot::using(function ($root) {
        file_put_contents($root->path('app/One.php'), 'one');
        $target = readBackArtifact('example/recipe', 'A', ['app/One.php' => 'one', 'app/Two.php' => 'two']);
        expect(readBackApply($root, readBackEmpty($target), $target, 'install', claim: ['app/One.php'])['status'])->toBe('completed')
            ->and(Checkout::artifact($root->path, 'one', 'example/recipe')?->identity())->toBe($target->identity());
    });
});

it('reads back null for a lineage that is not installed, without writing', function () {
    OwnedAppRoot::using(function ($root) {
        expect(Checkout::artifact($root->path, 'one', 'example/recipe'))->toBeNull()
            ->and(file_exists($root->path('.mod-maintenance')))->toBeFalse();
        $target = readBackArtifact('example/recipe', 'A', ['app/One.php' => 'one']);
        readBackApply($root, readBackEmpty($target), $target, 'install');
        expect(Checkout::artifact($root->path, 'one', 'example/other'))->toBeNull();
    });
});

it('refuses an empty app identity when reading back', function () {
    OwnedAppRoot::using(function ($root) {
        expect(fn () => Checkout::artifact($root->path, '', 'example/recipe'))->toThrow(InvalidArgumentException::class)
            ->and(fn () => Checkout::pending($root->path, ''))->toThrow(InvalidArgumentException::class);
    });
});

it('reads back a 0.8 record as its own lineage', function () {
    OwnedAppRoot::using(function ($root) {
        $installed = new Artifact('example/recipe', 'A', ['app/One.php' => 'A'], ['core'], ['auth' => 'session'], ['check']);
        readBackLegacyCheckout($root, $installed);
        expect(Checkout::artifact($root->path, 'one', 'example/recipe')?->identity())->toBe($installed->identity());
    });
});

it('upgrades from the read-back artifact as the original', function () {
    OwnedAppRoot::using(function ($root) {
        $a = readBackArtifact('example/recipe', 'A', ['app/One.php' => 'A']);
        readBackApply($root, readBackEmpty($a), $a, 'install');
        $b = readBackArtifact('example/recipe', 'B', ['app/One.php' => 'B']);
        expect(readBackApply($root, Checkout::artifact($root->path, 'one', 'example/recipe'), $b, 'upgrade')['status'])->toBe('completed')
            ->and(Checkout::artifact($root->path, 'one', 'example/recipe')?->identity())->toBe($b->identity());
    });
});

it('lists no unfinished updates in a fresh or settled checkout', function () {
    OwnedAppRoot::using(function ($root) {
        expect(Checkout::pending($root->path, 'one'))->toBe([]);
        $target = readBackArtifact('example/recipe', 'A', ['app/One.php' => 'one']);
        readBackApply($root, readBackEmpty($target), $target, 'install');
        expect(Checkout::pending($root->path, 'one'))->toBe([]);
    });
});

it('lists an update that failed verification, then clears it after resume', function () {
    OwnedAppRoot::using(function ($root) {
        $target = readBackArtifact('example/recipe', 'A', ['app/One.php' => 'one']);
        expect(readBackApply($root, readBackEmpty($target), $target, 'install-recipe', passes: false)['status'])->toBe('verification_failed');
        $pending = Checkout::pending($root->path, 'one');
        expect($pending)->toBe([['name' => 'example/recipe', 'intent' => 'install-recipe', 'target' => $target->identity()]]);
        $baseline = Checkout::artifact($root->path, 'one', $pending[0]['name']) ?? readBackEmpty($target);
        expect(Maintenance::resume($root->path, 'one', $baseline, $target, readBackInputs($root), ['php' => 'passed'], $pending[0]['intent'], ['check' => fn () => true], true)['status'])->toBe('completed')
            ->and(Checkout::pending($root->path, 'one'))->toBe([]);
    });
});

it('lists unfinished updates of several lineages sorted by name, own and other alike', function () {
    OwnedAppRoot::using(function ($root) {
        $b = readBackArtifact('example/b', '1', ['app/B.php' => 'b']);
        $a = readBackArtifact('example/a', '1', ['app/A.php' => 'a']);
        $c = readBackArtifact('example/c', '1', ['app/C.php' => 'c']);
        readBackApply($root, readBackEmpty($c), $c, 'c');
        // The pending refusal applies to new plans; craft both journals by letting each fail before the other starts.
        readBackApply($root, readBackEmpty($b), $b, 'b', passes: false);
        expect(fn () => readBackApply($root, readBackEmpty($a), $a, 'a', passes: false))->toThrow(RuntimeException::class, 'pending:example/b');
        $journal = $root->path('.mod-maintenance/journal-'.hash('sha256', 'b').'.json');
        $saved = file_get_contents($journal);
        unlink($journal);
        readBackApply($root, readBackEmpty($a), $a, 'a', passes: false);
        file_put_contents($journal, $saved);
        expect(Checkout::pending($root->path, 'one'))->toBe([
            ['name' => 'example/a', 'intent' => 'a', 'target' => $a->identity()],
            ['name' => 'example/b', 'intent' => 'b', 'target' => $b->identity()],
        ]);
    });
});

it('names an unfinished 0.8 journal by the 0.8 record, else unknown', function () {
    OwnedAppRoot::using(function ($root) {
        $a = new Artifact('example/recipe', 'A', ['app/One.php' => 'A'], ['core'], ['auth' => 'session'], ['check']);
        $b = new Artifact('example/recipe', 'B', ['app/One.php' => 'B'], ['core'], ['auth' => 'session'], ['check']);
        readBackLegacyCheckout($root, $a);
        expect(readBackApply($root, $a, $b, 'upgrade', passes: false)['status'])->toBe('verification_failed');
        readBackLegacyJournal($root, 'upgrade');
        expect(Checkout::pending($root->path, 'one'))->toBe([['name' => 'example/recipe', 'intent' => 'upgrade', 'target' => $b->identity()]]);
        unlink($root->path('.mod-maintenance/installed.json'));
        expect(Checkout::pending($root->path, 'one'))->toBe([['name' => 'unknown', 'intent' => 'upgrade', 'target' => $b->identity()]]);
    });
});

it('refuses a malformed journal when listing unfinished updates', function () {
    OwnedAppRoot::using(function ($root) {
        $target = readBackArtifact('example/recipe', 'A', ['app/One.php' => 'one']);
        readBackApply($root, readBackEmpty($target), $target, 'install');
        file_put_contents($root->path('.mod-maintenance/journal-'.hash('sha256', 'broken').'.json'), '{"envelope":{}}');
        expect(fn () => Checkout::pending($root->path, 'one'))->toThrow(RuntimeException::class, 'Invalid prepared journal schema.');
    });
});
