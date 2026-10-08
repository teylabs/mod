<?php

use Tey\Mod\Artifact\ArtifactRequest;
use Tey\Mod\Artifact\ResolvedArtifact;
use Tey\Mod\Facades\Mod;
use Tey\Mod\Generation\PackageDetector;
use Tey\Mod\Layout\CompiledLayout;
use Tey\Mod\Placement\PlacementContext;
use Tey\Mod\Placement\PlacementResolver;
use Tey\Mod\Tests\Support\OwnedAppRoot;
use Tey\Mod\Tests\TestCase;

/*
 * Feature tests boot Testbench. Safety net: every owned root must be destroyed
 * by the test that created it. Anything still alive after a test is cleaned up
 * and the test is failed.
 */
require_once __DIR__.'/Expectations.php';

uses(TestCase::class)
    ->afterEach(function () {
        $leaked = OwnedAppRoot::destroyAll();

        expect($leaked)->toBe([], 'Owned app roots were not destroyed by their test.');
    })
    ->in('Feature');

/*
 * Unit tests are pure: no application, no container, no filesystem.
 */
uses(PHPUnit\Framework\TestCase::class)->in('Unit');

/**
 * Resolve one artifact against a preset, placing it with the --in option value.
 *
 * @param  array<string, string|int|float|bool|null>  $attributes
 */
function place(CompiledLayout $preset, string $kind, string $name, string $in = '', array $attributes = []): ResolvedArtifact
{
    return (new PlacementResolver($preset))->resolve(
        ArtifactRequest::for($kind, $name, PlacementContext::fromOption($in, $preset), $attributes),
    );
}

const MIGRATION_TIMESTAMP = '2026_01_01_000000';

/**
 * Move the ddd layout's domain root to a namespace no other test uses. Classes
 * stay loaded for the whole PHP process, so a generated base such as
 * Domain\Shared\Data\DataTransferObject may already exist when this test runs;
 * under a fresh namespace it never does. Returns the namespace, without the
 * trailing backslash; the folder stays src/Domain. Pass a namespace to apply
 * the same one again (an acceptance app runs its layout calls more than once).
 */
function isolatedDomainNamespace(?string $namespace = null): string
{
    $namespace ??= 'Domain'.bin2hex(random_bytes(4));

    Mod::layout('ddd')->root('domain', $namespace.'\\', 'src/Domain');

    return $namespace;
}

/**
 * Pretend these Composer packages are installed, and no class is.
 */
function starterPackages(string ...$packages): void
{
    app()->instance(PackageDetector::class, new class($packages) implements PackageDetector
    {
        /**
         * @param  list<string>  $packages
         */
        public function __construct(private array $packages) {}

        public function isInstalled(string $package): bool
        {
            return in_array($package, $this->packages, true);
        }

        public function classExists(string $class): bool
        {
            return false;
        }
    });
}

/**
 * A bases folder of this test's own below app/Support: generated bases stay
 * loaded for the whole process, so no other test may share their names.
 *
 * @return array{string, string} the folder and its namespace
 */
function isolatedBasesPath(): array
{
    $folder = 'B'.bin2hex(random_bytes(4));
    config()->set('mod.bases_path', "app/Support/{$folder}");

    return ["app/Support/{$folder}", "App\\Support\\{$folder}"];
}
