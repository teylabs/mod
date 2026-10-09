<?php

use Tey\Mod\Support\Path;

/*
 * The public API is a short, explicit list: everything else in src is marked
 * @internal, so it can change without a major release.
 */

const MOD_PUBLIC_API = [
    'Facades/Mod.php',
    'ModServiceProvider.php',
    'Layout/Layout.php',
    'Layout/Root.php',
    'Layout/FileType.php',
    'Layout/CompiledLayout.php',
    'Layout/CompiledRoot.php',
    'Generation/Stub.php',
    'Generation/GeneratedBase.php',
    'Generation/Starters.php',
    'Generation/StubRegistry.php',
    'Generation/GeneratorRegistry.php',
    'Generation/GeneratorAdapter.php',
    'Generation/CollisionPolicy.php',
    'Generation/GenerationPlan.php',
    'Placement/PlacementContext.php',
    'Artifact/ResolvedArtifact.php',
    'Artifact/ArtifactKind.php',
    'Relation/RelationMode.php',
    'Discovery/DiscoveryOptions.php',
    'Discovery/DiscoveryDefinition.php',
];

/**
 * @return list<string> src files, relative, whose class is neither public API nor marked @internal
 */
function unmarkedInternals(): array
{
    $root = dirname(__DIR__, 3).'/src';
    $unmarked = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
        $relative = (string) Path::relative($root, $file->getPathname());

        if ($file->getExtension() !== 'php' || str_starts_with($relative, 'Commands/') || str_starts_with($relative, 'Exceptions/') || in_array($relative, MOD_PUBLIC_API, true)) {
            continue;
        }

        $source = (string) file_get_contents($file->getPathname());
        $declaration = preg_match('/^((?:\/\*\*(?:(?!\*\/).)*\*\/\s*)?)(?:final |abstract |readonly )*(?:class|interface|trait|enum) \w+/ms', $source, $match) === 1 ? $match[1] : '';

        if (! str_contains($declaration, '@internal')) {
            $unmarked[] = $relative;
        }
    }

    sort($unmarked);

    return $unmarked;
}

it('marks every class outside the public API @internal', function () {
    expect(unmarkedInternals())->toBe([]);
});
