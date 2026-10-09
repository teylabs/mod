<?php

namespace Tey\Mod\Templates;

use Illuminate\Support\Str;
use Symfony\Component\Filesystem\Path as FilesystemPath;
use Tey\Mod\Generation\Stub;
use Tey\Mod\Generation\StubRegistry;
use Tey\Mod\Layout\FileType;
use Tey\Mod\Support\ComposerJson;
use Tey\Mod\Support\Path;

/** @internal The accepted templates and skip reasons for one active layout. */
final class TemplateCatalog
{
    /** @var array<string, array{file: string, path: string, source: string, slots: list<string>, groups: list<string>, digest: string, relative: string, uses_base: bool, body_aliases?: array<string, string>}> */
    private array $templates = [];

    /** @var array<string, string> */
    private array $skipped = [];

    /** @var array<string, string> command => conflict warning */
    private array $conflicts = [];

    /** @var list<string> */
    private array $notices = [];

    /** @param array<string, array{file: string, path: string, source: string, slots: list<string>, groups: list<string>, digest: string, relative: string, uses_base: bool, body_aliases?: array<string, string>}>|null $cached */
    public function __construct(
        private readonly string $basePath,
        private readonly StubRegistry $stubs = new StubRegistry,
        private readonly ?array $cached = null,
    ) {}

    /**
     * @param  array<string, FileType>  $types
     * @param  array<string, array{namespace: ?string, path: string}>  $roots
     * @return array{array<string, FileType>, array<string, array{namespace: ?string, path: string}>}
     */
    public function merge(string $layout, ?string $groupPath, array $types, array $roots): array
    {
        $this->templates = [];
        $this->skipped = [];
        $this->notices = [];
        $this->conflicts = [];
        $records = $this->files();
        if ($records === []) {
            return [$types, $roots];
        }
        $roots = $this->composerRoots($roots, $records);
        $roots['app-resources'] = ['namespace' => null, 'path' => 'resources'];
        $candidates = [];
        foreach ($records as $record) {
            ['relative' => $path, 'file' => $file, 'path' => $display, 'source' => $source] = $record;
            try {
                $parsed = (new PathParser)->resolve($path, $layout, $groupPath, $types, $roots);
                $id = $parsed->id;
                $existing = $types[$id] ?? null;
                if ($existing !== null && $existing->toArray()['in'] !== null) {
                    throw new InvalidTemplate("mod:{$id} already exists. To change what ".Str::plural($id)." start as, use stubs/mod.{$id}.stub.");
                }
                if (in_array($id, ['autoload', 'bases', 'cache', 'clear', 'list', 'template'], true)) {
                    throw new InvalidTemplate("mod:{$id} is one of mod's own commands. Choose another template name.");
                }
                foreach ($this->commands($id, $types[$id] ?? null) as $command) {
                    foreach ($types as $otherId => $other) {
                        $definition = $other->toArray();
                        if ($definition['in'] !== null && in_array($command, $this->commands($otherId, $other), true)) {
                            throw new InvalidTemplate("{$command} already exists. To change what ".Str::plural($otherId)." start as, use stubs/mod.{$otherId}.stub.");
                        }
                    }
                    if (in_array($command, ['mod:autoload', 'mod:bases', 'mod:cache', 'mod:clear', 'mod:list', 'mod:template'], true)) {
                        throw new InvalidTemplate("{$command} is one of mod's own commands. Choose another template name.");
                    }
                }
                $candidates[$id][] = [$parsed, $record];
            } catch (InvalidTemplate $exception) {
                $this->skipped[$display] = $exception->getMessage();
            }
        }
        foreach ($candidates as $id => $entries) {
            $app = array_values(array_filter($entries, static fn (array $entry): bool => ($entry[1]['source'] === 'app' || str_starts_with($entry[1]['source'], 'app (overrides '))));
            if ($app !== []) {
                $packages = array_values(array_unique(array_map(static fn (array $entry): string => $entry[1]['source'], array_filter($entries, static fn (array $entry): bool => $entry[1]['source'] !== 'app' && ! str_starts_with($entry[1]['source'], 'app (overrides ')))));
                sort($packages);
                if ($packages !== []) {
                    foreach ($app as &$entry) {
                        $entry[1]['source'] = 'app (overrides '.implode(', ', $packages).')';
                    }
                    unset($entry);
                }
            }
            $candidates[$id] = $app === [] ? $entries : $app;
        }
        $owners = [];
        foreach ($candidates as $id => $entries) {
            foreach ($this->commands($id, $types[$id] ?? null) as $command) {
                foreach ($entries as $entry) {
                    $owners[$command][] = [$id, $entry[1]];
                }
            }
        }
        foreach ($owners as $command => $entries) {
            if (count($entries) < 2) {
                continue;
            }
            $paths = implode(', ', array_map(static fn (array $entry): string => $entry[1]['path'].' ('.$entry[1]['source'].')', $entries));
            $warning = "Templates [{$paths}] give the same command {$command}. Define the template in the app's stubs/mod folder to override the packages, or rename one template.";
            $this->conflicts[$command] = $warning;
            foreach ($entries as [$id, $record]) {
                $this->skipped[$record['path']] = $warning;
                foreach ($this->commands($id, $types[$id] ?? null) as $disabled) {
                    $this->conflicts[$disabled] = $warning;
                }
                unset($candidates[$id]);
            }
        }
        foreach ($candidates as $id => $entries) {
            [$parsed, $record] = $entries[0];
            ['file' => $file, 'path' => $display, 'source' => $source] = $record;
            $type = isset($types[$id]) ? clone $types[$id] : new FileType($id);
            $stub = $this->stub($file, $parsed, $types, $record['uses_base']);
            $type->withinRoot($parsed->root)->in($parsed->in)->stub($stub);
            if ($parsed->extension !== null && $parsed->extension !== '.php') {
                $type->extension($parsed->extension)->nested();
            }
            if ($type->toArray()['priority'] === null) {
                $type->priority(100 + count($this->templates));
            }
            $alias = 'mod:'.str_replace('-', '', $id);
            if ($alias !== 'mod:'.$id) {
                $type->aliases($alias);
            }
            $types[$id] = $type;
            $aliases = $parsed->group === null ? [] : ['group' => $parsed->group];
            if ($parsed->anchor !== null && $parsed->group !== null) {
                $aliases[$parsed->anchor] = $parsed->group;
            }
            $this->templates[$id] = [...$record, 'slots' => $parsed->slots, 'groups' => $parsed->groups, 'body_aliases' => $aliases];
            if ($parsed->notice !== null) {
                $this->notices[] = $parsed->notice;
            }
        }
        // A refinement of a skipped template has no independent path to compile.
        foreach ($records as $record) {
            $id = Str::kebab(Str::studly(pathinfo(basename($record['relative']), PATHINFO_FILENAME)));
            if (isset($types[$id]) && $types[$id]->toArray()['in'] === null && ! isset($this->templates[$id])) {
                unset($types[$id]);
            }
        }
        ksort($this->templates);
        ksort($this->skipped);
        $this->notices = array_values(array_unique($this->notices));

        return [$types, $roots];
    }

    /** @return list<string> */
    private function commands(string $id, ?FileType $type): array
    {
        $definition = $type?->toArray();
        $command = $definition['command'] ?? 'mod:'.$id;
        if ($command === false) {
            return [];
        }

        return array_values(array_unique(array_map(strtolower(...), [$command, 'mod:'.str_replace('-', '', $id), ...($definition['aliases'] ?? [])])));
    }

    /** @return list<array{file: string, path: string, source: string, slots: list<string>, groups: list<string>, digest: string, relative: string, uses_base: bool, body_aliases?: array<string, string>}> */
    private function files(): array
    {
        if ($this->cached !== null) {
            return array_values($this->cached);
        }
        $folders = $this->stubs->folders();
        $folders[Path::join($this->basePath, 'stubs/mod')] = null;
        $files = [];
        foreach ($folders as $folder => $provider) {
            $folder = FilesystemPath::canonicalize(Path::resolve($this->basePath, $folder));
            $app = Path::same($folder, Path::join($this->basePath, 'stubs/mod'));
            $source = $app ? 'app' : ($provider ?? (Path::relative(Path::join($this->basePath, 'vendor'), $folder) ?? $folder));
            if (! $app && preg_match('#^([^/]+/[^/]+)/#', $source, $match) === 1) {
                $source = $match[1];
            }
            foreach ((new TemplateScanner)->files($folder) as $relative => $file) {
                $contents = file_get_contents($file);
                if ($contents === false) {
                    $this->skipped[$file] = 'The template cannot be read. Check its permissions.';

                    continue;
                }
                $files[] = ['file' => $file, 'path' => Path::relative($this->basePath, $file) ?? $file, 'source' => $source, 'slots' => [], 'groups' => [], 'digest' => hash('sha256', $contents), 'relative' => $relative, 'uses_base' => preg_match('/\\{\\{\\s*baseImport\\s*\\}\\}/', $contents) === 1];
            }
        }

        return $files;
    }

    /**
     * @param  array<string, array{namespace: ?string, path: string}>  $roots
     * @param  list<array{relative: string}>  $records
     * @return array<string, array{namespace: ?string, path: string}>
     */
    private function composerRoots(array $roots, array $records): array
    {
        $file = Path::join($this->basePath, 'composer.json');
        if (! is_file($file)) {
            return $roots;
        }
        foreach ((new ComposerJson($file))->mappings() as $namespace => $paths) {
            foreach ((array) $paths as $path) {
                $path = Path::relative($this->basePath, $path) ?? Path::normalize($path);
                $needed = false;
                foreach ($records as $record) {
                    if (Path::relative($path, $record['relative']) !== null) {
                        $needed = true;
                    }
                }
                if (! $needed) {
                    continue;
                }
                $covered = false;
                foreach ($roots as $root) {
                    if (Path::same($root['path'], $path)) {
                        $covered = true;
                    }
                }
                if (! $covered) {
                    $roots['psr4_'.substr(hash('sha256', $namespace.'|'.$path), 0, 12)] = ['namespace' => $namespace, 'path' => $path];
                }
            }
        }

        return $roots;
    }

    /** @param array<string, FileType> $types */
    private function stub(string $file, ParsedTemplate $parsed, array $types, bool $usesBase): Stub
    {
        if ($usesBase) {
            foreach ($types as $type) {
                $definition = $type->toArray();
                if ($definition['root'] === $parsed->root && $definition['in'] === $parsed->in) {
                    $source = $this->stubs->resolve($type->id, $definition['stub']);
                    if ($source !== null) {
                        return $source->forTemplate($file);
                    }
                }
            }
        }

        return Stub::file($file);
    }

    /** @return array<string, array{file: string, path: string, source: string, slots: list<string>, groups: list<string>, digest: string, relative: string, uses_base: bool, body_aliases?: array<string, string>}> */
    public function templates(): array
    {
        return $this->templates;
    }

    /** @return array<string, string> template path => actionable skip reason */
    public function skipped(): array
    {
        return $this->skipped;
    }

    /** @return array<string, string> command => actionable conflict warning */
    public function conflicts(): array
    {
        return $this->conflicts;
    }

    /** @return list<string> */
    public function notices(): array
    {
        return $this->notices;
    }
}
