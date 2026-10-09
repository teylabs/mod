<?php

namespace Tey\Mod\Scaffolds;

use Illuminate\Support\Str;
use Tey\Mod\Artifact\ResolvedArtifact;
use Tey\Mod\Generation\PlainFile\Identity;
use Tey\Mod\Layout\CompiledLayout;

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
                return match ($forms) {
                    ['array'] => var_export(array_values($value), true),
                    ['json'] => json_encode(array_values($value), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                    default => $match[0],
                };
            }
            if ($value instanceof ResolvedArtifact && $value->fqcn() === null) {
                $identity = Identity::forms($value, app(CompiledLayout::class));
                if ($forms !== [] && isset($identity[$forms[0]])) {
                    $value = $identity[array_shift($forms)];
                } else {
                    $value = $identity['component'] ?? $value->name;
                }
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

    /** @param list<array{file: string, line: int, message: string}> $warnings */
    public function renderPlain(string $stub, string $extension, string $file, array &$warnings): string
    {
        $original = $stub;
        $escaped = [];
        $stub = (string) preg_replace_callback('/@\{\{.*?\}\}/s', static function (array $match) use (&$escaped): string {
            $token = "\x00mod-escape-".count($escaped)."\x00";
            $escaped[$token] = substr($match[0], 1);

            return $token;
        }, $stub);
        if (in_array($extension, ['.vue', '.blade.php'], true)) {
            preg_match_all('/(?<!@)\{\{\s*([A-Za-z_][A-Za-z0-9_]*)\s*\}\}/', $original, $matches, PREG_OFFSET_CAPTURE);
            foreach ($matches[0] as $index => [$placeholder, $offset]) {
                $key = $matches[1][$index][0];
                if (! array_key_exists($key, $this->values)) {
                    continue;
                }
                $line = substr_count(substr($original, 0, $offset), "\n") + 1;
                $value = $this->render($placeholder);
                $framework = $extension === '.vue' ? 'Vue' : 'Blade';
                $message = $file.' line '.$line.': {{ '.$key.' }} is a mod placeholder, so it was replaced with '.$value.'. In a '.$extension.' file, write {{ '.$key.'.studly }} to mean mod\'s value, or @{{ '.$key.' }} for '.$framework.'\'s own.';
                $warnings[] = compact('file', 'line', 'message');
            }
        }

        return strtr($this->render($stub), $escaped);
    }

    public function name(string $pattern): string
    {
        return (string) preg_replace_callback('/(?<!\{)\{([\w.-]+)\}(?!\})/', fn (array $m): string => $this->render('{{ '.$m[1].' }}'), $pattern);
    }
}
