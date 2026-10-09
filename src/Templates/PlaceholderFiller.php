<?php

namespace Tey\Mod\Templates;

use Illuminate\Support\Str;

/** @internal The value and name placeholders generator templates add to Laravel's stubs. */
final class PlaceholderFiller
{
    /** @param array<string, string> $values */
    public function fill(string $stub, string $class, array $values, string $rootNamespace): string
    {
        return (string) preg_replace_callback('/\{\{\s*([A-Za-z_][A-Za-z0-9_]*)(?:\.([a-z.]+))?\s*\}\}/', static function (array $match) use ($class, $values, $rootNamespace): string {
            $key = $match[1];
            if ($key === 'rootNamespace') {
                return $rootNamespace;
            }
            $value = $key === 'class' ? $class : ($values[$key] ?? null);
            if ($value === null) {
                return $match[0];
            }
            foreach (explode('.', $match[2] ?? '') as $form) {
                $value = match ($form) {
                    '' => $value,
                    'camel' => Str::camel($value),
                    'kebab' => Str::kebab($value),
                    'snake' => Str::snake($value),
                    'studly' => Str::studly($value),
                    'plural' => Str::plural($value),
                    'singular' => Str::singular($value),
                    'lower' => Str::lower($value),
                    'upper' => Str::upper($value),
                    'title' => Str::title($value),
                    'headline' => Str::headline($value),
                    default => null,
                };
                if ($value === null) {
                    return $match[0];
                }
            }

            return $value;
        }, $stub);
    }
}
