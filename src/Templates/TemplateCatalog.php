<?php

namespace Tey\Mod\Templates;

use Illuminate\Support\Str;
use Tey\Mod\Generation\Stub;
use Tey\Mod\Layout\FileType;
use Tey\Mod\Support\Path;

/** The accepted templates and skip reasons for one active layout. */
final class TemplateCatalog
{
    /** @var array<string, array{file: string, path: string, source: string, slots: list<string>, groups: list<string>, digest: string}> */
    private array $templates = [];

    /** @var array<string, string> */
    private array $skipped = [];

    /** @var list<string> */
    private array $notices = [];

    public function __construct(private readonly string $basePath) {}

    /**
     * @param  array<string, FileType>  $types
     * @param  array<string, array{namespace: ?string, path: string}>  $roots
     * @return array<string, FileType>
     */
    public function merge(string $layout, ?string $groupPath, array $types, array $roots): array
    {
        $this->templates = [];
        $this->skipped = [];
        $this->notices = [];
        $candidates = [];
        foreach ((new TemplateScanner)->files(Path::join($this->basePath, 'stubs/mod')) as $path => $file) {
            $display = 'stubs/mod/'.$path;
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
                $candidates[$id][] = [$parsed, $file, $display];
            } catch (InvalidTemplate $exception) {
                $this->skipped[$display] = $exception->getMessage();
            }
        }
        foreach ($candidates as $id => $entries) {
            if (count($entries) > 1) {
                $paths = implode(', ', array_column($entries, 2));
                foreach ($entries as $entry) {
                    $this->skipped[$entry[2]] = "Templates [{$paths}] give the same command mod:{$id}. Rename one template.";
                }

                continue;
            }
            [$parsed, $file, $display] = $entries[0];
            $type = isset($types[$id]) ? clone $types[$id] : new FileType($id);
            $stub = $this->stub($file, $parsed, $types);
            $type->withinRoot($parsed->root)->in($parsed->in)->stub($stub);
            if ($type->toArray()['priority'] === null) {
                $type->priority(100 + count($this->templates));
            }
            $alias = 'mod:'.str_replace('-', '', $id);
            if ($alias !== 'mod:'.$id) {
                $type->aliases($alias);
            }
            $types[$id] = $type;
            $this->templates[$id] = ['file' => $file, 'path' => $display, 'source' => 'app', 'slots' => $parsed->slots, 'groups' => $parsed->groups, 'digest' => hash_file('sha256', $file) ?: ''];
            if ($parsed->notice !== null) {
                $this->notices[] = $parsed->notice;
            }
        }
        ksort($this->templates);
        ksort($this->skipped);
        $this->notices = array_values(array_unique($this->notices));

        return $types;
    }

    /** @param array<string, FileType> $types */
    private function stub(string $file, ParsedTemplate $parsed, array $types): Stub
    {
        $contents = (string) file_get_contents($file);
        if (str_contains($contents, '{{ baseImport }}') || str_contains($contents, '{{baseImport}}')) {
            foreach ($types as $type) {
                $definition = $type->toArray();
                if ($definition['root'] === $parsed->root && $definition['in'] === $parsed->in && $definition['stub'] !== null) {
                    return $definition['stub']->forTemplate($file);
                }
            }
        }

        return Stub::file($file);
    }

    /** @return array<string, array{file: string, path: string, source: string, slots: list<string>, groups: list<string>, digest: string}> */
    public function templates(): array
    {
        return $this->templates;
    }

    /** @return array<string, string> template path => actionable skip reason */
    public function skipped(): array
    {
        return $this->skipped;
    }

    /** @return list<string> */
    public function notices(): array
    {
        return $this->notices;
    }
}
