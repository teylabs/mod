<?php

namespace Tey\Mod\Scaffolds;

use Tey\Mod\Artifact\ArtifactKind;
use Tey\Mod\Artifact\FileIdentity;
use Tey\Mod\Artifact\IdentityShape;
use Tey\Mod\Artifact\NamePolicy;
use Tey\Mod\Artifact\ResolvedArtifact;
use Tey\Mod\Exceptions\GenerationRefused;
use Tey\Mod\Layout\CompiledLayout;
use Tey\Mod\Placement\PlacementContext;
use Tey\Mod\Support\Path;

/** @internal Folder identity and ownership, shared by generation and rename. */
final class Folder
{
    public static function pattern(CompiledLayout $layout, string $key): string
    {
        $pattern = $layout->frontend()[$key] ?? null;
        if (! is_string($pattern) || $pattern === '') {
            throw GenerationRefused::because("Scaffold folder frontend path {$key} is not configured. Nothing was written.");
        }
        if (! $layout->isPlainFilePath($pattern)) {
            throw GenerationRefused::because("Scaffold folder {$key} must belong to a plain-file root, not a PHP class root. Nothing was written.");
        }

        return $pattern;
    }

    public static function place(CompiledLayout $layout, string $key, string $name, PlacementContext $context): ResolvedArtifact
    {
        $pattern = self::pattern($layout, $key);
        $path = preg_replace_callback('/\{(\w+)[+?]*\}/', static fn (array $match): string => $context->get($match[1]) ?? throw GenerationRefused::because("Scaffold folder needs placement {$match[1]}. Nothing was written."), $pattern);
        $name = Path::normalize($name);
        if ($name === '' || Path::relative('', $name) === null || preg_match('~(?:^|/)\.{1,2}(?:/|$)|[{}\x00:]~', $name) === 1) {
            throw GenerationRefused::because('A scaffold folder name must be a non-empty relative subtree without parent segments or unresolved placeholders. Nothing was written.');
        }
        $path = Path::join((string) $path, $name);
        if (Path::relative('', $path) === null || in_array('..', explode('/', $path), true)) {
            throw GenerationRefused::because('A scaffold folder must stay inside the project. Nothing was written.');
        }

        return new ResolvedArtifact(new ArtifactKind('folder', IdentityShape::File, NamePolicy::asGiven()), $context, $name, new FileIdentity($path));
    }
}
