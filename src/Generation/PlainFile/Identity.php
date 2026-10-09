<?php

namespace Tey\Mod\Generation\PlainFile;

use Illuminate\Support\Str;
use Tey\Mod\Artifact\ArtifactKind;
use Tey\Mod\Artifact\FileIdentity;
use Tey\Mod\Artifact\IdentityShape;
use Tey\Mod\Artifact\NamePolicy;
use Tey\Mod\Artifact\ResolvedArtifact;
use Tey\Mod\Layout\CompiledLayout;
use Tey\Mod\Placement\PlacementContext;
use Tey\Mod\Support\Path;

/** @internal Framework references derived from the same resolved file path. */
final class Identity
{
    public static function fromPath(string $path, CompiledLayout $layout): ?ResolvedArtifact
    {
        $extension = str_ends_with($path, '.blade.php') ? '.blade.php' : '.'.pathinfo($path, PATHINFO_EXTENSION);
        foreach ($layout->frontend() as $key => $pattern) {
            if (! is_string($pattern) || $key === 'page_name') {
                continue;
            }
            $tokens = [];
            $regex = '';
            foreach (explode('/', $pattern) as $part) {
                if (preg_match('/^\{(\w+)[+?]*\}$/', $part, $match)) {
                    $tokens[] = $match[1];
                    $regex .= '/([^/]+)';
                } else {
                    $regex .= '/'.preg_quote($part, '~');
                }
            }
            if (preg_match('~^'.ltrim($regex, '/').'/(.+)~', $path, $match) !== 1) {
                continue;
            }
            $context = PlacementContext::none();
            foreach ($tokens as $index => $token) {
                $context = $context->with($token, $match[$index + 1]);
            }
            $kind = new ArtifactKind($key === 'pages' ? 'page' : 'plain', IdentityShape::File, NamePolicy::asGiven(), extension: $extension);
            $name = substr(basename($path), 0, -strlen($extension));

            return new ResolvedArtifact($kind, $context, $name, new FileIdentity($path));
        }

        return null;
    }

    /** @return array<string, string> */
    public static function forms(ResolvedArtifact $artifact, CompiledLayout $layout): array
    {
        $path = $artifact->path();
        $extension = $artifact->kind->extension;
        if ($extension === null && ! $artifact->kind->isClass() && str_ends_with($path, '.blade.php')) {
            $extension = '.blade.php';
        }
        $component = basename($path);
        if ($extension !== null && str_ends_with($component, $extension)) {
            $component = substr($component, 0, -strlen($extension));
        }
        $forms = ['path' => $path];
        if ($extension === null) {
            return $forms;
        }
        $forms['component'] = Str::studly($component);
        $group = implode('/', $artifact->context->only($layout->dimensionNames())->toArray());
        $frontend = $layout->frontend();
        $tokens = $artifact->context->toArray();
        $resolve = static fn (?string $pattern): string => (string) preg_replace_callback('/\{(\w+)[+?]*\}/', static fn (array $m): string => $tokens[$m[1]] ?? ($m[1] === 'path' ? '{path}' : ''), $pattern ?? '');
        $pages = $resolve($frontend['pages']);
        $views = $resolve($frontend['views']);
        if ($artifact->kind->id === 'page' && $pages !== '') {
            $below = Path::relative($pages, $path) ?? basename($path);
            $below = substr($below, 0, -strlen($extension));
            $forms['name'] = str_replace('{path}', $below, $resolve($frontend['page_name']));
        } elseif ($extension === '.blade.php' && $views !== '' && ($below = Path::relative($views, $path)) !== null) {
            $name = str_replace('/', '.', substr($below, 0, -strlen($extension)));
            $namespace = $group === '' ? '' : Casing::name(str_replace('/', '-', $group), 'kebab').'::';
            $forms['name'] = $namespace.$name;
            if (str_starts_with($name, 'components.')) {
                $forms['tag'] = 'x-'.$namespace.substr($name, strlen('components.'));
            }
        }
        // The stable alias points at the ancestor above the first group folder.
        $prefix = '';
        foreach ($layout->roots() as $root) {
            if (str_contains($root->path, '{')) {
                $candidate = rtrim(substr($root->path, 0, strcspn($root->path, '{')), '/');
                if (Path::relative($candidate, $path) !== null && strlen($candidate) > strlen($prefix)) {
                    $prefix = $candidate;
                }
            }
        }
        $alias = '@modules/';
        $forms['import'] = str_starts_with($path, 'resources/js/') ? '@/'.(Path::relative('resources/js', $path) ?? $path) : $alias.(Path::relative($prefix, $path) ?? $path);

        return $forms;
    }
}
