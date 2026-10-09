<?php

namespace Tey\Mod\Scaffolds;

use Illuminate\Support\Str;
use Tey\Mod\Artifact\ResolvedArtifact;

/** @internal Literal substitution shared by creation, growing and inserts. */
final readonly class Placeholders
{
    /** @param array<string, mixed> $values */
    public function __construct(private array $values) {}

    public function render(string $stub): string
    {
        return (string) preg_replace_callback('/\{\{\s*([\w.-]+)\s*\}\}/', function (array $match): string {
            $key = $match[1];
            $forms = [];
            while (! array_key_exists($key, $this->values) && str_contains($key, '.')) {
                $index = (int) strrpos($key, '.');
                array_unshift($forms, substr($key, $index + 1));
                $key = substr($key, 0, $index);
            }
            if (! array_key_exists($key, $this->values)) {
                return $match[0];
            }
            $value = $this->values[$key];
            if (is_array($value)) {
                return $forms === ['array'] ? var_export(array_values($value), true) : $match[0];
            }
            $fqcn = $value instanceof ResolvedArtifact ? ($value->fqcn() ?? $value->name) : (is_string($value) ? $value : '');
            $value = $value instanceof ResolvedArtifact ? class_basename($fqcn) : (is_bool($value) ? ($value ? 'true' : 'false') : (is_scalar($value) ? (string) $value : ''));
            if (str_contains($value, '\\')) {
                $value = class_basename($value);
            }
            foreach ($forms as $form) {
                $value = match ($form) {
                    'fqcn' => $fqcn,
                    'camel' => Str::camel($value),
                    'snake' => Str::snake($value),
                    'kebab' => Str::kebab($value),
                    'studly' => Str::studly($value),
                    'plural' => Str::plural($value),
                    'singular' => Str::singular($value),
                    'lower' => Str::lower($value),
                    'upper' => Str::upper($value),
                    'title' => Str::title($value),
                    'headline' => Str::headline($value),
                    default => $match[0],
                };
            }

            return $value;
        }, $stub);
    }

    public function name(string $pattern): string
    {
        return (string) preg_replace_callback('/(?<!\{)\{([\w.-]+)\}(?!\})/', fn (array $m): string => $this->render('{{ '.$m[1].' }}'), $pattern);
    }
}
