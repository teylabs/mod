<?php

namespace Tey\Mod\Support;

use JsonException;
use RuntimeException;
use stdClass;

/**
 * PSR-4 mappings in the host application's composer.json.
 *
 * @internal
 */
final class ComposerJson
{
    /** @var array<string, mixed> */
    private array $data;

    private stdClass $shape;

    private string $indent;

    private string $newline;

    public function __construct(private readonly string $file)
    {
        $contents = is_file($file) ? file_get_contents($file) : false;

        if ($contents === false) {
            throw new RuntimeException("mod:autoload could not read [{$file}]. Create composer.json before running mod:autoload.");
        }

        try {
            $shape = json_decode($contents, false, 512, JSON_THROW_ON_ERROR);
            $data = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('mod:autoload could not read composer.json: '.$exception->getMessage().'. Fix the JSON and run mod:autoload again.', previous: $exception);
        }

        if (! $shape instanceof stdClass || ! is_array($data)) {
            throw new RuntimeException('mod:autoload needs composer.json to contain a JSON object. Fix it and run mod:autoload again.');
        }

        $this->shape = $shape;

        foreach (['autoload', 'psr-4'] as $key) {
            if (property_exists($shape, $key) && ! $shape->{$key} instanceof stdClass) {
                throw new RuntimeException("mod:autoload needs [{$key}] to be a JSON object. Fix composer.json and run mod:autoload again.");
            }

            $shape = $shape->{$key} ?? new stdClass;
        }

        /** @var array<string, mixed> $data */
        $this->data = $data;
        $this->indent = preg_match('/\R( +)"/', $contents, $matches) === 1 && strlen($matches[1]) === 2 ? '  ' : '    ';
        $this->newline = str_ends_with($contents, "\r\n") ? "\r\n" : (str_ends_with($contents, "\n") ? "\n" : '');
        $this->mappings();
    }

    /** @return array<string, string|list<string>> */
    public function mappings(): array
    {
        $autoload = $this->data['autoload'] ?? [];
        $mappings = is_array($autoload) ? ($autoload['psr-4'] ?? []) : [];

        if (! is_array($mappings)) {
            throw new RuntimeException('mod:autoload needs autoload.psr-4 to be an object. Fix composer.json and run mod:autoload again.');
        }

        foreach ($mappings as $namespace => $paths) {
            if (! is_string($namespace) || (! is_string($paths) && (! is_array($paths) || ! array_is_list($paths))) || (is_array($paths) && array_filter($paths, static fn (mixed $path): bool => ! is_string($path)) !== [])) {
                throw new RuntimeException('mod:autoload needs PSR-4 mappings to contain a path or a list of paths. Fix composer.json and run mod:autoload again.');
            }
        }

        /** @var array<string, string|list<string>> $mappings */
        return $mappings;
    }

    public function covers(string $namespace, string $path): bool
    {
        $namespace = rtrim($namespace, '\\').'\\';
        $exact = array_key_exists($namespace, $this->mappings());

        foreach ($this->coverageMappings() as $prefix => $paths) {
            if (($exact && $prefix !== $namespace) || ! str_starts_with($namespace, $prefix)) {
                continue;
            }

            $suffix = str_replace('\\', '/', substr($namespace, strlen($prefix)));

            foreach ((array) $paths as $folder) {
                if (Path::same($this->absolute(Path::join($folder, $suffix)), $this->absolute($path))) {
                    return true;
                }
            }
        }

        return false;
    }

    public function register(string $namespace, string $path): void
    {
        $autoload = $this->data['autoload'] ?? [];
        $autoload = is_array($autoload) ? $autoload : [];
        $mappings = $this->mappings();
        $mappings[rtrim($namespace, '\\').'\\'] = $this->normalizePathForComposer($path);
        $autoload['psr-4'] = $mappings;
        $this->data['autoload'] = $autoload;
    }

    public function normalizePathForComposer(string $path): string
    {
        $path = Path::normalize($path);
        $relative = Path::relative(Path::normalize(dirname($this->file)), $path);

        return rtrim($relative ?? $path, '/').'/';
    }

    public function save(): void
    {
        $json = json_encode($this->preserveEmptyObjects($this->data, $this->shape), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        if ($this->indent === '  ') {
            $json = (string) preg_replace_callback('/^ +/m', static fn (array $match): string => str_repeat(' ', intdiv(strlen($match[0]), 2)), $json);
        }

        if ($this->newline === "\r\n") {
            $json = str_replace("\n", "\r\n", $json);
        }

        if (file_put_contents($this->file, $json.$this->newline) === false) {
            throw new RuntimeException('mod:autoload could not write composer.json. Check its permissions and run mod:autoload again.');
        }
    }

    private function absolute(string $path): string
    {
        $path = Path::normalize($path);

        return str_starts_with($path, '/') || preg_match('#^[A-Za-z]:/#', $path) === 1 ? $path : Path::join(dirname($this->file), $path);
    }

    /**
     * Development mappings already load test roots; never promote them into autoload.
     *
     * @return array<string, list<string>>
     */
    private function coverageMappings(): array
    {
        $mappings = [];
        $dev = $this->data['autoload-dev'] ?? [];
        $dev = is_array($dev) ? ($dev['psr-4'] ?? []) : [];

        foreach ([$this->mappings(), is_array($dev) ? $dev : []] as $section) {
            foreach ($section as $prefix => $paths) {
                foreach ((array) $paths as $path) {
                    if (is_string($prefix) && is_string($path)) {
                        $mappings[$prefix][] = $path;
                    }
                }
            }
        }

        return $mappings;
    }

    /** Restore an empty array to {} wherever the file held a JSON object. */
    private function preserveEmptyObjects(mixed $value, mixed $shape): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if ($shape instanceof stdClass) {
            if ($value === []) {
                return new stdClass;
            }

            $shape = get_object_vars($shape);
        }

        if (! is_array($shape)) {
            return $value;
        }

        foreach ($value as $key => $item) {
            if (array_key_exists($key, $shape)) {
                $value[$key] = $this->preserveEmptyObjects($item, $shape[$key]);
            }
        }

        return $value;
    }
}
