<?php

use PHPUnit\Framework\Assert;
use Tey\Mod\Maintenance\Artifact;
use Tey\Mod\Maintenance\Checkout;
use Tey\Mod\Maintenance\Journal;
use Tey\Mod\Maintenance\Maintenance;
use Tey\Mod\Tests\Support\OwnedAppRoot;

beforeEach(function () {
    if (PHP_OS_FAMILY === 'Windows') {
        Assert::markTestSkipped('Maintenance supports POSIX checkouts only.');
    }
});

/**
 * @return array<mixed>
 */
function journalFixture(OwnedAppRoot $root): array
{
    file_put_contents($root->path('app/Owned.php'), 'A');
    file_put_contents($root->path('composer.lock'), 'locked');
    $a = new Artifact('example/recipe', 'A', ['app/Owned.php' => 'A'], ['core'], ['auth' => 'session'], ['protocol']);
    $b = new Artifact('example/recipe', 'B', ['app/Owned.php' => 'B'], ['core'], ['auth' => 'session'], ['protocol']);

    return [Checkout::inspect($root->path, 'one', $a, $b, ['lock' => 'composer.lock'], ['framework' => 'passed']), ['lock' => 'composer.lock']];
}

it('durably prepares original and target bytes before any generated write or version record', function () {
    OwnedAppRoot::using(function ($root) {
        [$plan, $inputs] = journalFixture($root);
        $journal = Journal::prepare($plan, $inputs, 'upgrade-one', exclusiveCheckout: true);
        try {
            $receipt = $journal->receipt();
            expect($receipt['state'])->toBe('prepared')
                ->and(base64_decode($receipt['envelope']['originals']['app/Owned.php']))->toBe('A')
                ->and(base64_decode($receipt['envelope']['targets']['app/Owned.php']))->toBe('B')
                ->and(file_get_contents($root->path('app/Owned.php')))->toBe('A')
                ->and(file_exists($root->path('.mod-maintenance/manifest.json')))->toBeFalse();
        } finally {
            $journal->close();
        }
    });
});

it('refuses without exclusive checkout before metadata effects', function () {
    OwnedAppRoot::using(function ($root) {
        [$plan, $inputs] = journalFixture($root);
        expect(fn () => Journal::prepare($plan, $inputs, 'upgrade-one', exclusiveCheckout: false))->toThrow(RuntimeException::class)
            ->and(file_exists($root->path('.mod-maintenance')))->toBeFalse();
    });
});

it('refuses stale generated or host input observations before metadata effects', function (string $path) {
    OwnedAppRoot::using(function ($root) use ($path) {
        [$plan, $inputs] = journalFixture($root);
        file_put_contents($root->path($path), 'host edit');
        expect(fn () => Journal::prepare($plan, $inputs, 'upgrade-one', exclusiveCheckout: true))->toThrow(RuntimeException::class)
            ->and(file_exists($root->path('.mod-maintenance')))->toBeFalse()
            ->and(file_get_contents($root->path($path)))->toBe('host edit');
    });
})->with(['app/Owned.php', 'composer.lock']);

it('holds one advisory claim and resumes identical preparation without rewriting it', function () {
    OwnedAppRoot::using(function ($root) {
        [$plan, $inputs] = journalFixture($root);
        $journal = Journal::prepare($plan, $inputs, 'upgrade-one', exclusiveCheckout: true);
        $receipt = $journal->receipt();
        try {
            expect(fn () => Journal::prepare($plan, $inputs, 'upgrade-two', exclusiveCheckout: true))->toThrow(RuntimeException::class);
        } finally {
            $journal->close();
        }
        $path = $root->path('.mod-maintenance/journal-'.hash('sha256', 'upgrade-one').'.json');
        $before = hash_file('sha256', $path);
        $again = Journal::prepare($plan, $inputs, 'upgrade-one', exclusiveCheckout: true);
        try {
            expect($again->receipt())->toBe($receipt)->and(hash_file('sha256', $path))->toBe($before);
        } finally {
            $again->close();
        }
    });
});

it('refuses changed intent payload and edited journal envelope', function (string $kind) {
    OwnedAppRoot::using(function ($root) use ($kind) {
        [$plan, $inputs] = journalFixture($root);
        Journal::prepare($plan, $inputs, 'upgrade-one', exclusiveCheckout: true)->close();
        if ($kind === 'intent') {
            $target = new Artifact('example/recipe', 'C', ['app/Owned.php' => 'C'], ['core'], ['auth' => 'session'], ['protocol']);
            $plan = Checkout::inspect($root->path, 'one', $plan->baseline, $target, $inputs, ['framework' => 'passed']);
        } else {
            $path = $root->path('.mod-maintenance/journal-'.hash('sha256', 'upgrade-one').'.json');
            $record = json_decode(file_get_contents($path), true);
            $record['envelope']['targets']['app/Owned.php'] = base64_encode('forged');
            file_put_contents($path, json_encode($record));
        }
        expect(fn () => Journal::prepare($plan, $inputs, 'upgrade-one', exclusiveCheckout: true))->toThrow(RuntimeException::class)
            ->and(file_get_contents($root->path('app/Owned.php')))->toBe('A');
    });
})->with(['intent', 'journal']);

it('names a reused intent whose journal belongs to a different plan, before touching files', function () {
    OwnedAppRoot::using(function ($root) {
        file_put_contents($root->path('policy.json'), '{"checks":["protocol"]}');
        file_put_contents($root->path('verifier.php'), '<?php return true;');
        $inputs = ['verification-policy' => 'policy.json', 'verification-code' => 'verifier.php'];
        $empty = new Artifact('example/recipe', 'A', [], ['core'], ['auth' => 'session'], ['protocol']);
        $first = new Artifact('example/recipe', 'B', ['app/Owned.php' => 'B'], ['core'], ['auth' => 'session'], ['protocol']);
        $second = new Artifact('example/recipe', 'C', ['app/Other.php' => 'C'], ['core'], ['auth' => 'session'], ['protocol']);
        $plan = Checkout::inspect($root->path, 'one', $empty, $first, $inputs, ['framework' => 'passed']);
        expect(Maintenance::apply($plan, $inputs, 'install', ['protocol' => fn () => false], true)['status'])->toBe('verification_failed');
        $journal = file_get_contents($root->path('.mod-maintenance/journal-'.hash('sha256', 'install').'.json'));

        $plan = Checkout::inspect($root->path, 'one', $empty, $second, $inputs, ['framework' => 'passed']);

        expect(fn () => Maintenance::apply($plan, $inputs, 'install', ['protocol' => fn () => true], true))
            ->toThrow(RuntimeException::class, 'Intent [install] already has a journal for a different plan. Finish or resume it, or use a new intent.')
            ->and(file_exists($root->path('app/Other.php')))->toBeFalse()
            ->and(file_get_contents($root->path('.mod-maintenance/journal-'.hash('sha256', 'install').'.json')))->toBe($journal);
    });
});

it('refuses unknown metadata and foreign app ownership without adoption', function (string $kind) {
    OwnedAppRoot::using(function ($root) use ($kind) {
        [$plan, $inputs] = journalFixture($root);
        mkdir($root->path('.mod-maintenance'));
        file_put_contents($root->path('.mod-maintenance/owner.json'), $kind === 'unknown' ? '{}' : json_encode(['format' => 1, 'owner' => 'tey/mod:maintenance', 'app_id' => 'other', 'root' => $root->path]));
        $before = file_get_contents($root->path('.mod-maintenance/owner.json'));
        expect(fn () => Journal::prepare($plan, $inputs, 'upgrade-one', exclusiveCheckout: true))->toThrow(RuntimeException::class)
            ->and(file_get_contents($root->path('.mod-maintenance/owner.json')))->toBe($before)
            ->and(file_get_contents($root->path('app/Owned.php')))->toBe('A');
    });
})->with(['unknown', 'foreign']);

it('refuses unmarked metadata and linked or hardlinked journal files without touching the original', function (string $kind) {
    OwnedAppRoot::using(function ($root) use ($kind) {
        [$plan, $inputs] = journalFixture($root);
        if ($kind === 'unmarked') {
            mkdir($root->path('.mod-maintenance'));
        } else {
            Journal::prepare($plan, $inputs, 'upgrade-one', exclusiveCheckout: true)->close();
            $path = $root->path('.mod-maintenance/journal-'.hash('sha256', 'upgrade-one').'.json');
            $outside = $root->path('host-journal.json');
            rename($path, $outside);
            if ($kind === 'symlink') {
                symlink($outside, $path);
            } else {
                link($outside, $path);
            }
        }
        expect(fn () => Journal::prepare($plan, $inputs, 'upgrade-one', exclusiveCheckout: true))->toThrow(RuntimeException::class)
            ->and(file_get_contents($root->path('app/Owned.php')))->toBe('A');
    });
})->with(['unmarked', 'symlink', 'hardlink']);
