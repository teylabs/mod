<?php

use PHPUnit\Framework\Assert;
use Tey\Mod\Maintenance\Artifact;
use Tey\Mod\Maintenance\Checkout;
use Tey\Mod\Maintenance\InstalledRecord;
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
function portableArtifact(string $version, array $files): Artifact
{
    return new Artifact('example/recipe', $version, $files, ['core'], ['auth' => 'session'], ['check'], metadata: ['source' => 'tests']);
}

/**
 * @return array<string, mixed>
 */
function portableApply(OwnedAppRoot $root, ?Artifact $baseline, Artifact $target, string $intent, string $appId = 'one'): array
{
    file_put_contents($root->path('policy.json'), '{"checks":["check"]}');
    file_put_contents($root->path('verifier.php'), '<?php return true;');
    $inputs = ['verification-policy' => 'policy.json', 'verification-code' => 'verifier.php'];
    $baseline ??= new Artifact($target->name, $target->version, [], $target->groups, $target->bindings, $target->checks, metadata: $target->metadata);
    $plan = Checkout::inspect($root->path, $appId, $baseline, $target, $inputs, ['php' => 'passed']);

    return Maintenance::apply($plan, $inputs, $intent, ['check' => fn () => true], true);
}

/**
 * What a repository commits, copied to another checkout: the owned files and the installed records, never owner.json, the lock or a journal.
 *
 * @param  list<string>  $files
 */
function portableClone(OwnedAppRoot $from, OwnedAppRoot $to, array $files): void
{
    foreach ($files as $file) {
        @mkdir(dirname($to->path($file)), 0700, true);
        copy($from->path($file), $to->path($file));
    }
    mkdir($to->path(InstalledRecord::DIRECTORY), 0700, true);
    foreach (glob($from->path(InstalledRecord::DIRECTORY).'/*.json') ?: [] as $record) {
        copy($record, $to->path(InstalledRecord::DIRECTORY.'/'.basename($record)));
    }
}

function portableRecord(OwnedAppRoot $root): string
{
    return $root->path(InstalledRecord::relative('example/recipe'));
}

it('writes installed records with a relative root and no local path', function () {
    OwnedAppRoot::using(function ($root) {
        portableApply($root, null, portableArtifact('A', ['app/One.php' => 'one']), 'install');
        $bytes = file_get_contents(portableRecord($root));
        expect(json_decode($bytes, true)['root'])->toBe('.')
            ->and(str_contains($bytes, $root->path))->toBeFalse();
    });
});

it('plans and applies in another checkout that has only the committed records', function () {
    OwnedAppRoot::using(fn ($a) => OwnedAppRoot::using(function ($b) use ($a) {
        portableApply($a, null, portableArtifact('A', ['app/One.php' => 'one']), 'install');
        portableClone($a, $b, ['app/One.php']);
        expect(file_exists($b->path('.mod-maintenance/owner.json')))->toBeFalse()
            ->and(file_exists($b->path('.mod-maintenance/lock')))->toBeFalse();

        $installed = Checkout::artifact($b->path, 'one', 'example/recipe');
        expect($installed?->files)->toBe(['app/One.php' => 'one']);
        $result = portableApply($b, $installed, portableArtifact('B', ['app/One.php' => 'two']), 'upgrade');

        $owner = json_decode(file_get_contents($b->path('.mod-maintenance/owner.json')), true);
        expect($result['status'])->toBe('completed')
            ->and(file_get_contents($b->path('app/One.php')))->toBe('two')
            ->and($owner['root'])->toBe($b->path)
            ->and(is_file($b->path('.mod-maintenance/lock')))->toBeTrue()
            ->and(Checkout::installed($b->path, 'one')['example/recipe']['version'])->toBe('B');
    }));
});

it('accepts a legacy record with another checkout\'s absolute root and rewrites it on apply', function () {
    OwnedAppRoot::using(fn ($a) => OwnedAppRoot::using(function ($b) use ($a) {
        portableApply($a, null, portableArtifact('A', ['app/One.php' => 'one']), 'install');
        portableClone($a, $b, ['app/One.php']);
        $record = json_decode(file_get_contents(portableRecord($b)), true);
        $record['root'] = '/home/someone/worktrees/app';
        file_put_contents(portableRecord($b), json_encode($record, JSON_UNESCAPED_SLASHES)."\n");

        $installed = Checkout::artifact($b->path, 'one', 'example/recipe');
        expect($installed?->version)->toBe('A');
        expect(portableApply($b, $installed, portableArtifact('B', ['app/One.php' => 'two']), 'upgrade')['status'])->toBe('completed');
        $bytes = file_get_contents(portableRecord($b));
        expect(json_decode($bytes, true)['root'])->toBe('.')
            ->and(str_contains($bytes, '/home/someone'))->toBeFalse();
    }));
});

it('still refuses a foreign installed record', function (Closure $tamper) {
    OwnedAppRoot::using(fn ($a) => OwnedAppRoot::using(function ($b) use ($a, $tamper) {
        portableApply($a, null, portableArtifact('A', ['app/One.php' => 'one']), 'install');
        portableClone($a, $b, ['app/One.php']);
        $tamper($b);

        expect(fn () => Checkout::installed($b->path, 'one'))->toThrow(RuntimeException::class);
    }));
})->with([
    'another app' => [function (OwnedAppRoot $root) {
        $record = json_decode(file_get_contents(portableRecord($root)), true);
        $record['app_id'] = 'two';
        file_put_contents(portableRecord($root), json_encode($record, JSON_UNESCAPED_SLASHES));
    }],
    'another lineage\'s file' => [function (OwnedAppRoot $root) {
        rename(portableRecord($root), $root->path(InstalledRecord::relative('example/other')));
    }],
    'changed artifact bytes' => [function (OwnedAppRoot $root) {
        $record = json_decode(file_get_contents(portableRecord($root)), true);
        $record['artifact']['version'] = 'Z';
        file_put_contents(portableRecord($root), json_encode($record, JSON_UNESCAPED_SLASHES));
    }],
    'a relative root other than .' => [function (OwnedAppRoot $root) {
        $record = json_decode(file_get_contents(portableRecord($root)), true);
        $record['root'] = '../elsewhere';
        file_put_contents(portableRecord($root), json_encode($record, JSON_UNESCAPED_SLASHES));
    }],
]);

it('refuses to claim maintenance ownership over a linked owner file or another checkout\'s journal', function (Closure $prepare, string $message) {
    OwnedAppRoot::using(fn ($a) => OwnedAppRoot::using(function ($b) use ($a, $prepare, $message) {
        portableApply($a, null, portableArtifact('A', ['app/One.php' => 'one']), 'install');
        portableClone($a, $b, ['app/One.php']);
        $prepare($a, $b);
        expect(fn () => portableApply($b, Checkout::artifact($b->path, 'one', 'example/recipe'), portableArtifact('B', ['app/One.php' => 'two']), 'upgrade'))->toThrow(RuntimeException::class, $message);
        expect(file_get_contents($b->path('app/One.php')))->toBe('one')
            ->and(is_link($b->path('.mod-maintenance/owner.json')) || ! file_exists($b->path('.mod-maintenance/owner.json')))->toBeTrue();
    }));
})->with([
    'a symlinked owner.json' => [fn (OwnedAppRoot $a, OwnedAppRoot $b) => symlink($a->path('.mod-maintenance/owner.json'), $b->path('.mod-maintenance/owner.json')), 'Linked maintenance metadata is unsupported.'],
    'another checkout\'s journal' => [fn (OwnedAppRoot $a, OwnedAppRoot $b) => file_put_contents($b->path('.mod-maintenance/journal-'.str_repeat('a', 64).'.json'), '{}'), 'Invalid maintenance data schema.'],
]);

it('reports unknown maintenance content instead of claiming ownership over it', function () {
    OwnedAppRoot::using(fn ($a) => OwnedAppRoot::using(function ($b) use ($a) {
        portableApply($a, null, portableArtifact('A', ['app/One.php' => 'one']), 'install');
        portableClone($a, $b, ['app/One.php']);
        file_put_contents($b->path('.mod-maintenance/notes.txt'), 'x');
        $result = portableApply($b, Checkout::artifact($b->path, 'one', 'example/recipe'), portableArtifact('B', ['app/One.php' => 'two']), 'upgrade');

        expect($result['status'])->toBe('orphaned')
            ->and($result['orphans'])->toBe([['path' => '.mod-maintenance/notes.txt', 'kind' => 'file']])
            ->and(file_exists($b->path('.mod-maintenance/owner.json')))->toBeFalse()
            ->and(file_get_contents($b->path('app/One.php')))->toBe('one');
    }));
});
