<?php

namespace Tey\Mod\Templates;

use Composer\Autoload\ClassLoader;
use FilesystemIterator;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Tey\Mod\Layout\CompiledLayout;
use Tey\Mod\Support\Path;

/** @internal Filesystem and tokenizer lookup. Never calls class_exists or includes a source. */
final class ClassLookup
{
    /** @var list<array{name: string, class: string, file: string}>|null */
    private ?array $index = null;

    public function __construct(private readonly string $basePath, private readonly CompiledLayout $layout) {}

    /** @return list<array{name: string, class: string, file: string}> */
    public function all(): array
    {
        if ($this->index !== null) {
            return $this->index;
        }
        $records = [];
        foreach ($this->layout->roots() as $root) {
            $folder = Path::resolve($this->basePath, $root->path);
            if ($root->namespace === null || ! is_dir($folder) || $this->excluded($folder)) {
                continue;
            }
            $filter = new RecursiveCallbackFilterIterator(new RecursiveDirectoryIterator($folder, FilesystemIterator::SKIP_DOTS), fn (SplFileInfo $file): bool => ! $file->isLink() && ! $this->excluded($file->getPathname()));
            foreach (new RecursiveIteratorIterator($filter) as $file) {
                if ($file instanceof SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
                    try {
                        $record = $this->record($file->getPathname());
                        $records[$record['file']] = $record;
                    } catch (RuntimeException) {
                        // Unreadable files and files without a single declaration are not choices.
                    }
                }
            }
        }
        $records = array_values($records);
        usort($records, static fn (array $a, array $b): int => strcmp($a['class'], $b['class']));

        return $this->index = $records;
    }

    /** @return list<array{name: string, class: string, file: string}> */
    public function matches(string $value): array
    {
        $file = Path::resolve($this->basePath, $value);
        if (is_file($file)) {
            return [$this->record($file)];
        }
        $fqcn = ltrim(str_replace('/', '\\', $value), '\\');
        if (str_contains($fqcn, '\\')) {
            foreach (ClassLoader::getRegisteredLoaders() as $loader) {
                $found = $loader->findFile($fqcn);
                if ($found !== false) {
                    return [$this->record($found)];
                }
            }
            foreach (spl_autoload_functions() as $autoload) {
                if (is_array($autoload) && $autoload[0] instanceof ClassLoader) {
                    $found = $autoload[0]->findFile($fqcn);
                    if ($found !== false) {
                        return [$this->record($found)];
                    }
                }
            }
        }
        $parts = explode('\\', $fqcn);
        $name = (string) array_pop($parts);

        return array_values(array_filter($this->all(), static function (array $record) use ($name, $parts): bool {
            if ($record['name'] !== $name) {
                return false;
            }
            $position = 0;
            foreach ($parts as $part) {
                $found = strpos($record['class'], $part.'\\', $position);
                if ($found === false) {
                    return false;
                }
                $position = $found + strlen($part) + 1;
            }

            return true;
        }));
    }

    /** @return list<array{name: string, class: string, file: string}> */
    public function near(string $name): array
    {
        $records = array_values(array_filter($this->all(), static fn (array $r): bool => levenshtein(strtolower($name), strtolower($r['name'])) <= 2));
        usort($records, static fn (array $a, array $b): int => [levenshtein(strtolower($name), strtolower($a['name'])), $a['class']] <=> [levenshtein(strtolower($name), strtolower($b['name'])), $b['class']]);

        return $records;
    }

    /** @return array{name: string, class: string, file: string} */
    public function record(string $file): array
    {
        $contents = file_get_contents($file);
        if ($contents === false) {
            throw new RuntimeException("mod:template cannot read [{$file}]. Check its permissions.");
        }
        $extracted = (new TokenExtractor)->extract($contents);

        return ['name' => $extracted['name'], 'class' => $extracted['class'], 'file' => Path::normalize($file)];
    }

    private function excluded(string $path): bool
    {
        $path = Path::normalize($path);
        if (in_array('vendor', explode('/', $path), true)) {
            return true;
        }
        foreach ($this->layout->excludedRoots() as $root) {
            if (Path::relative(Path::resolve($this->basePath, $root->path), $path) !== null) {
                return true;
            }
        }

        return false;
    }
}
