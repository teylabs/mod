<?php

namespace Tey\Mod\Templates;

use Illuminate\Support\Str;
use Symfony\Component\Filesystem\Filesystem;
use Tey\Mod\Artifact\Identifier;
use Tey\Mod\Layout\BuiltIn\TemplateAnchors;
use Tey\Mod\Layout\FileType;
use Tey\Mod\Layout\GroupPath;
use Tey\Mod\Layout\Layout;
use Tey\Mod\Support\Path;

/** @internal Parses folder segments literally; brackets never become filesystem patterns. */
final class PathParser
{
    private const RESERVED = ['help', 'quiet', 'verbose', 'version', 'ansi', 'no-ansi', 'no-interaction', 'env', 'in', 'force'];

    public function parse(string $path, Layout $layout): ParsedTemplate
    {
        $chain = $layout->toArray();
        [$types, $roots] = GroupPath::resolve($layout->name, $chain['path'], $chain['kinds'], $chain['roots'], $chain['nesting']);

        return $this->resolve($path, $layout->name, $chain['path'], $types, $roots);
    }

    /**
     * @param  array<string, FileType>  $types  already resolved through GroupPath
     * @param  array<string, array{namespace: ?string, path: string}>  $roots
     */
    public function resolve(string $path, string $layout, ?string $groupPath, array $types, array $roots): ParsedTemplate
    {
        $path = Path::normalize($path);
        if ((new Filesystem)->isAbsolutePath($path)) {
            throw new InvalidTemplate('Template paths must be relative to their template folder.');
        }
        $parts = explode('/', $path);
        if (in_array('..', $parts, true) || in_array('.', $parts, true) || in_array('', $parts, true)) {
            throw new InvalidTemplate('The template path points outside its folder. Use whole folder names.');
        }
        if ($parts[0] === 'app') {
            throw new InvalidTemplate('Template paths are relative to app/, like ->generates(in:). Drop app/: '.substr($path, 4).'.');
        }
        $filename = array_pop($parts);
        if (! str_ends_with($filename, '.stub')) {
            throw new InvalidTemplate('Generator templates need a .stub extension.');
        }
        $id = Str::kebab(Str::studly(substr($filename, 0, -5)));
        if (preg_match('/^[a-z][a-z0-9]*(?:-[a-z0-9]+)*$/', $id) !== 1) {
            throw new InvalidTemplate('Name the template with letters, numbers and dashes.');
        }
        $patterns = $this->groupPatterns($types, $roots);
        $tokens = [];
        if ($groupPath !== null) {
            preg_match_all('/\{(\w+)[+?]*\}/', $groupPath, $matches);
            $tokens = $matches[1];
        }
        if ($tokens === []) {
            foreach ($patterns as $pattern) {
                preg_match_all('/\{(\w+)[+?]*\}/', $pattern, $matches);
                foreach ($matches[1] as $token) {
                    if (! in_array($token, $tokens, true)) {
                        $tokens[] = $token;
                    }
                }
            }
        }
        $slots = [];
        $anchorIndex = null;
        $anchor = null;
        foreach ($parts as $index => $part) {
            if (str_contains($part, '{') || str_contains($part, '}') || in_array($part, array_map(static fn (string $token): string => '['.$token.']', $tokens), true)) {
                $token = $tokens[0] ?? 'group';
                throw new InvalidTemplate("template folders use @{$token}; {$part} is the Layout API's form.");
            }
            if (str_starts_with($part, '@')) {
                if ($anchorIndex !== null) {
                    throw new InvalidTemplate('Use only one anchor in a template path.');
                }
                $anchorIndex = $index;
                $anchor = substr($part, 1);
                if (! in_array($anchor, [...TemplateAnchors::NAMES, ...$tokens], true)) {
                    throw new InvalidTemplate("Unknown anchor {$part}. Use @".($tokens[0] ?? 'group').'.');
                }
            } elseif (str_contains($part, '[') || str_contains($part, ']')) {
                if (preg_match('/^\[([a-z][a-z0-9_-]*)\]$/', $part, $match) !== 1) {
                    throw new InvalidTemplate('A slot must name a single folder, such as [source].');
                }
                $slot = $match[1];
                if (in_array($slot, self::RESERVED, true)) {
                    throw new InvalidTemplate("Slot [{$slot}] conflicts with --{$slot}. Choose another slot name.");
                }
                if (in_array($slot, $slots, true)) {
                    throw new InvalidTemplate("Use slot [{$slot}] only once in a template path.");
                }
                $slots[] = $slot;
                $parts[$index] = '{'.$slot.'}';
            }
        }
        $notice = null;
        if ($anchorIndex !== null) {
            if ($tokens === []) {
                throw new InvalidTemplate("Layout [{$layout}] has no group. Remove @{$anchor} from the path.");
            }
            $resolved = $anchor === 'group' ? end($tokens) : (in_array($anchor, $tokens, true) ? $anchor : $tokens[0]);
            if ($anchor !== 'group' && $anchor !== $resolved) {
                $notice = "Template anchor @{$anchor} resolves to @{$resolved} in layout [{$layout}].";
            }
            $prefix = [];
            $suffix = [];
            foreach ($parts as $index => $part) {
                if ($index < $anchorIndex) {
                    $prefix[] = $part;
                } elseif ($index > $anchorIndex) {
                    $suffix[] = $part;
                }
            }
            $literalPrefix = array_values(array_filter($prefix, static fn (string $part): bool => ! str_starts_with($part, '{')));
            $target = null;
            if ($literalPrefix === [] && $groupPath !== null) {
                $candidate = $this->throughToken(GroupPath::projectPath($groupPath), $resolved);
                if ($candidate !== null) {
                    $target = str_contains($candidate, '*') ? str_replace('*', implode('/', $suffix), $candidate) : Path::join($candidate, ...$suffix);
                    $suffix = [];
                }
            }
            if ($target === null) {
                foreach ($patterns as $pattern) {
                    $candidate = $this->throughToken($pattern, $resolved);
                    if ($candidate === null) {
                        continue;
                    }
                    $before = substr($candidate, 0, strcspn($candidate, '{'));
                    $relativePrefix = Path::relative('app', rtrim($before, '/')) ?? rtrim($before, '/');
                    if ($literalPrefix === [] || implode('/', $literalPrefix) === $relativePrefix) {
                        $target = $candidate;
                        break;
                    }
                }
            }
            if ($target === null) {
                throw new InvalidTemplate("Layout [{$layout}] does not keep @{$resolved} after [".implode('/', $literalPrefix).']. Use @'.$resolved.' at the start.');
            }
            // Slot folders before an anchor precede the group's root-relative path.
            $leadingSlots = array_values(array_filter($prefix, static fn (string $part): bool => str_starts_with($part, '{')));
            [$root, $below] = $this->rootFor(Path::join($target, ...$suffix), $roots);
            $below = Path::join(...[...$leadingSlots, $below]);
            // Template groups are required, even where a native generator allows no placement.
            $below = (string) preg_replace('/\{(\w+)(\+?)\??\}/', '{$1$2}', $below);
            // A type-first wildcard keeps the native optional group contract (E13).
            if ($groupPath !== null && str_contains($groupPath, '*')) {
                foreach ($tokens as $token) {
                    $optional = null;
                    foreach ($types as $type) {
                        if (preg_match('/\{'.preg_quote($token, '/').'\+?(\??)\}/', $type->toArray()['in'] ?? '', $match) === 1) {
                            $optional = ($optional ?? true) && $match[1] === '?';
                        }
                    }
                    if ($optional === true) {
                        $below = str_replace('{'.$token.'}', '{'.$token.'?}', $below);
                    }
                }
            }
            foreach ($types as $type) {
                $in = $type->toArray()['in'] ?? '';
                foreach ($tokens as $token) {
                    if (str_contains($in, '{'.$token.'+')) {
                        $below = str_replace('{'.$token.'}', '{'.$token.'+}', $below);
                    }
                }
            }
        } else {
            $target = implode('/', $parts);
            $known = false;
            foreach ($roots as $rootDefinition) {
                if (Path::relative(GroupPath::projectPath($rootDefinition['path']), $target) !== null) {
                    $known = true;
                    break;
                }
            }
            [$root, $below] = $this->rootFor($known ? $target : Path::join('app', $target), $roots);
        }
        if ($roots[$root]['namespace'] === null) {
            throw new InvalidTemplate("plain-file templates aren't supported yet. Choose a namespaced root.");
        }
        foreach (explode('/', $below) as $part) {
            if ($part !== '' && ! str_starts_with($part, '{') && ! Identifier::isClassSegment($part)) {
                throw new InvalidTemplate("Folder [{$part}] cannot form a PHP namespace. Use letters, numbers and underscores.");
            }
        }
        $groups = array_values(array_filter($tokens, static fn (string $token): bool => preg_match('/\{'.preg_quote($token, '/').'[+?]*\}/', $below) === 1));

        return new ParsedTemplate($id, $root, $below, $slots, $groups, $notice);
    }

    /**
     * @param  array<string, FileType>  $types
     * @param  array<string, array{namespace: ?string, path: string}>  $roots
     * @return list<string>
     */
    private function groupPatterns(array $types, array $roots): array
    {
        $patterns = [];
        foreach ($types as $type) {
            $data = $type->toArray();
            $root = $data['root'] ?? array_key_first($roots);
            $in = $data['in'] ?? '';
            if (preg_match('/^([\w-]+):(.*)$/', $in, $match) === 1) {
                $root = $match[1];
                $in = $match[2];
            }
            if ($root !== null && isset($roots[$root]) && str_contains($in, '{')) {
                $patterns[] = Path::join(GroupPath::projectPath($roots[$root]['path']), $in);
            }
        }

        return array_values(array_unique($patterns));
    }

    private function throughToken(string $pattern, string $token): ?string
    {
        if (preg_match('/^(.*\{'.preg_quote($token, '/').'[+?]*\})/', $pattern, $match) !== 1) {
            return null;
        }

        return $match[1];
    }

    /**
     * @param  array<string, array{namespace: ?string, path: string}>  $roots
     * @return array{string, string}
     */
    private function rootFor(string $target, array $roots): array
    {
        $best = null;
        $below = '';
        foreach ($roots as $name => $root) {
            $relative = Path::relative(GroupPath::projectPath($root['path']), $target);
            if ($relative !== null && ($best === null || strlen($root['path']) > strlen($roots[$best]['path']))) {
                $best = $name;
                $below = $relative;
            }
        }
        if ($best === null) {
            throw new InvalidTemplate('The template writes outside every layout root and PSR-4 entry. Run php artisan mod:autoload, or declare a namespaced root with ->mounts().');
        }

        return [$best, $below];
    }
}
