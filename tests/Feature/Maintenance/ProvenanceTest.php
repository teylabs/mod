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

function provenanceArtifact(string $version, array $metadata = []): Artifact
{
    return new Artifact('example/recipe', $version, ['app/One.php' => $version], ['core'], ['auth' => 'session'], ['check'], metadata: $metadata);
}

function provenanceInputs($root): array
{
    file_put_contents($root->path('policy.json'), '{"checks":["check"]}');
    file_put_contents($root->path('verifier.php'), '<?php return true;');

    return ['verification-policy' => 'policy.json', 'verification-code' => 'verifier.php'];
}

it('preserves the exact legacy identity and installed record shape when metadata is empty', function () {
    $a = provenanceArtifact('A');
    expect($a->identity())->toBe('sha256:f20d5021fd5a3163bf4f88fcb1910b94ae82f2cfaf2dbe19355cdef9201f6620');
    OwnedAppRoot::using(function ($root) use ($a) {
        $inputs = provenanceInputs($root);
        $empty = new Artifact($a->name, $a->version, [], $a->groups, $a->bindings, $a->checks);
        $plan = Checkout::inspect($root->path, 'one', $empty, $a, $inputs, ['php' => 'passed']);
        Maintenance::apply($plan, $inputs, 'install', ['check' => fn () => true], true);
        $saved = json_decode(file_get_contents($root->path('.mod-maintenance/installed.json')), true);
        expect(array_keys($saved['artifact']))->toBe(['identity', 'name', 'version', 'files', 'groups', 'bindings', 'checks']);
    });
});

it('binds inert provenance to the version-last record and rejects changed intent source on replay', function () {
    OwnedAppRoot::using(function ($root) {
        $metadata = ['source_ref' => 'sha256:source-a', 'requirements' => ['php' => '^8.4'], 'migration_ids' => [], 'exceptions' => [], 'host_owned' => ['app/Domain']];
        $a = provenanceArtifact('A', $metadata);
        $empty = new Artifact($a->name, $a->version, [], $a->groups, $a->bindings, $a->checks);
        $inputs = provenanceInputs($root);
        $plan = Checkout::inspect($root->path, 'one', $empty, $a, $inputs, ['php' => 'passed']);
        expect(Maintenance::apply($plan, $inputs, 'install', ['check' => fn () => true], true)['status'])->toBe('completed');
        $path = $root->path('.mod-maintenance/installed.json');
        $before = file_get_contents($path);
        expect(json_decode($before, true)['artifact']['metadata'])->toBe($metadata);
        $other = provenanceArtifact('A', array_replace($metadata, ['source_ref' => 'sha256:other']));
        expect($other->identity())->not->toBe($a->identity())
            ->and(fn () => Maintenance::resume($root->path, 'one', $empty, $other, $inputs, ['php' => 'passed'], 'install', [], true))->toThrow(RuntimeException::class)
            ->and(file_get_contents($path))->toBe($before);
        expect(Maintenance::apply($plan, $inputs, 'install', [], true)['no_op'])->toBeTrue();
    });
});

it('does not let provenance widen owned files or waive required checks', function () {
    OwnedAppRoot::using(function ($root) {
        file_put_contents($root->path('app/Host.php'), 'host');
        $a = provenanceArtifact('A', ['files' => ['app/Host.php' => 'overwrite'], 'checks' => [], 'exceptions' => [['requirement' => 'check', 'waived' => true]]]);
        $empty = new Artifact($a->name, $a->version, [], $a->groups, $a->bindings, $a->checks);
        $inputs = provenanceInputs($root);
        $plan = Checkout::inspect($root->path, 'one', $empty, $a, $inputs, ['php' => 'passed']);
        expect(fn () => Maintenance::apply($plan, $inputs, 'install', [], true))->toThrow(InvalidArgumentException::class)
            ->and(file_get_contents($root->path('app/Host.php')))->toBe('host');
        expect(Maintenance::apply($plan, $inputs, 'install', ['check' => fn () => false], true)['status'])->toBe('verification_failed')
            ->and(file_exists($root->path('.mod-maintenance/installed.json')))->toBeFalse();
        expect(Maintenance::apply($plan, $inputs, 'install', ['check' => fn () => true], true)['status'])->toBe('completed')
            ->and(file_get_contents($root->path('app/Host.php')))->toBe('host');
    });
});

it('rejects altered baseline provenance before any upgrade replacement', function () {
    OwnedAppRoot::using(function ($root) {
        $a = provenanceArtifact('A', ['source_ref' => 'source-a']);
        $b = provenanceArtifact('B', ['source_ref' => 'source-b']);
        $empty = new Artifact($a->name, $a->version, [], $a->groups, $a->bindings, $a->checks);
        $inputs = provenanceInputs($root);
        Maintenance::apply(Checkout::inspect($root->path, 'one', $empty, $a, $inputs, ['php' => 'passed']), $inputs, 'install', ['check' => fn () => true], true);
        $path = $root->path('.mod-maintenance/installed.json');
        $saved = json_decode(file_get_contents($path), true);
        $saved['artifact']['metadata']['source_ref'] = 'changed';
        file_put_contents($path, json_encode($saved));
        $before = file_get_contents($path);
        $plan = Checkout::inspect($root->path, 'one', $a, $b, $inputs, ['php' => 'passed']);
        expect(fn () => Maintenance::apply($plan, $inputs, 'upgrade', ['check' => fn () => true], true))->toThrow(RuntimeException::class)
            ->and(file_get_contents($root->path('app/One.php')))->toBe('A')->and(file_get_contents($path))->toBe($before);
    });
});

it('rejects executable invalid or over-bound metadata as data without invoking objects', function (string $kind) {
    $metadata = match ($kind) {
        'object' => ['value' => new stdClass],
        'callback' => ['value' => fn () => true],
        'nonfinite' => ['value' => INF],
        'utf8' => ['value' => "\xB1\x31"],
        'numeric map' => ['value' => [2 => 'non-list']],
        'nodes' => ['value' => array_fill(0, 4097, 'x')],
        'bytes' => ['value' => str_repeat('x', 1024 * 1024)],
        default => [],
    };
    if ($kind === 'depth') {
        for ($i = 0; $i < 20; $i++) {
            $metadata = ['nested' => $metadata];
        }
    }
    expect(fn () => provenanceArtifact('A', $metadata))->toThrow(InvalidArgumentException::class);
})->with(['object', 'callback', 'nonfinite', 'utf8', 'numeric map', 'nodes', 'bytes', 'depth']);

it('rejects resource metadata without closing or serialising the caller resource', function () {
    $resource = fopen('php://temp', 'r+');
    try {
        expect(fn () => provenanceArtifact('A', ['value' => $resource]))->toThrow(InvalidArgumentException::class)
            ->and(is_resource($resource))->toBeTrue();
    } finally {
        fclose($resource);
    }
});

it('rejects JSON serialisers before invoking their executable methods and bounds cyclic arrays', function () {
    $object = new class implements JsonSerializable
    {
        public bool $called = false;

        public function jsonSerialize(): mixed
        {
            $this->called = true;

            return ['should' => 'never execute'];
        }
    };
    expect(fn () => provenanceArtifact('A', ['object' => $object]))->toThrow(InvalidArgumentException::class)
        ->and($object->called)->toBeFalse();
    $cyclic = [];
    $cyclic['self'] = &$cyclic;
    expect(fn () => provenanceArtifact('A', $cyclic))->toThrow(InvalidArgumentException::class);
});

it('refuses referenced inspection input paths before creating a public delivery claim', function () {
    OwnedAppRoot::using(function ($root) {
        $a = provenanceArtifact('A');
        $empty = new Artifact($a->name, $a->version, [], $a->groups, $a->bindings, $a->checks);
        $inputs = provenanceInputs($root);
        $plan = Checkout::inspect($root->path, 'one', $empty, $a, $inputs, ['php' => 'passed']);
        $alias = &$inputs['verification-code'];
        expect(fn () => Maintenance::apply($plan, $inputs, 'install', ['check' => fn () => true], true))->toThrow(InvalidArgumentException::class)
            ->and(file_exists($root->path('app/One.php')))->toBeFalse()
            ->and(file_exists($root->path('.mod-maintenance')))->toBeFalse();
    });
});
