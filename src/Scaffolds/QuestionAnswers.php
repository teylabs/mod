<?php

namespace Tey\Mod\Scaffolds;

use FilesystemIterator;
use Illuminate\Support\Str;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Tey\Mod\Exceptions\GenerationRefused;
use Tey\Mod\Layout\CompiledLayout;
use Tey\Mod\Reverse\ReverseMapper;
use Tey\Mod\Support\Path;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\search;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

/** @internal Questions never execute candidate classes. */
final readonly class QuestionAnswers
{
    public function __construct(private CompiledLayout $layout, private string $basePath) {}

    public function answer(Question $question, mixed $option, bool $interactive, string $command, string $name): mixed
    {
        $default = $question->default;
        if (is_string($default)) {
            $default = str_replace('{name}', $name, $default);
        }
        $value = $option;
        if ($value === null || $value === []) {
            if (! $interactive) {
                if ($default === null) {
                    $form = match ($question->type) {
                        'model', 'class' => $question->type, default => 'value'
                    };
                    throw GenerationRefused::because("{$command} needs a {$question->name}. Pass --{$question->name}=<{$form}>.");
                }
                $value = $default;
            } else {
                $label = $question->label ?? Str::headline($question->name);
                $value = match ($question->type) {
                    'choice' => select($label, $question->options, default: is_string($default) ? $default : null),
                    'confirm' => confirm($label, default: (bool) $default),
                    'model', 'class' => search($label, fn (string $query): array => array_filter($this->classes($question->type === 'model'), static fn (string $class): bool => str_contains(strtolower($class), strtolower($query))), default: is_string($default) ? $default : null),
                    default => text($label, default: is_array($default) ? implode(', ', $default) : (is_string($default) ? $default : ''), hint: $question->type === 'list' ? 'Separate with commas' : '', required: $default === null),
                };
            }
        }
        if ($question->type === 'list') {
            $items = [];
            foreach (is_array($value) ? $value : [$value] as $entry) {
                if (! is_string($entry)) {
                    throw GenerationRefused::because("{$command}: --{$question->name} needs comma-separated values.");
                }
                array_push($items, ...array_filter(array_map('trim', explode(',', $entry)), static fn (string $v): bool => $v !== ''));
            }

            return $items;
        }
        if ($question->type === 'confirm') {
            return (bool) $value;
        }
        if (! is_string($value) || $value === '') {
            throw GenerationRefused::because("{$command}: --{$question->name} needs a value.");
        }
        if ($question->type === 'choice' && ! in_array($value, $question->options, true) && ! array_key_exists($value, $question->options)) {
            throw GenerationRefused::because("{$command}: --{$question->name} must be one of ".implode(', ', $question->options).'.');
        }
        if (in_array($question->type, ['model', 'class'], true)) {
            $candidates = $this->classes($question->type === 'model');
            $matches = array_values(array_filter($candidates, static fn (string $class): bool => $class === ltrim($value, '\\') || class_basename($class) === $value));
            if (count($matches) !== 1) {
                throw GenerationRefused::because("{$command}: --{$question->name}={$value} ".($matches === [] ? 'was not found' : 'is ambiguous').'. Pass its fully qualified '.$question->type.' name.');
            }

            return $matches[0];
        }

        return $value;
    }

    /** @return array<string, string> */
    public function classes(bool $models): array
    {
        $classes = [];
        $mapper = new ReverseMapper($this->layout);
        // Layout roots include mounted model folders outside app/.
        $roots = array_unique(['app', ...array_map(static fn ($root): string => $root->path, $this->layout->roots())]);
        foreach ($roots as $root) {
            $directory = Path::resolve($this->basePath, $root);
            if (! is_dir($directory)) {
                continue;
            }
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));
            /** @var SplFileInfo $file */
            foreach ($files as $file) {
                if (! $file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }
                $relative = Path::relative($this->basePath, $file->getPathname());
                $owned = $relative === null ? null : $mapper->fromPath($relative)->artifact;
                if ($models && $owned?->kind->id !== 'model') {
                    continue;
                }
                $source = (string) file_get_contents($file->getPathname());
                if (preg_match('/namespace\s+([^;{]+)\s*[;{]/', $source, $ns) && preg_match('/\b(?:class|interface|trait|enum)\s+(\w+)/', $source, $class)) {
                    $fqcn = trim($ns[1]).'\\'.$class[1];
                    $classes[$fqcn] = $fqcn;
                }
            }
        }
        ksort($classes);

        return $classes;
    }
}
