<?php

namespace Tey\Mod\Templates;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Str;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Tey\Mod\Generation\GeneratorRegistry;
use Tey\Mod\Generation\PackageDetector;
use Tey\Mod\Generation\Starters;
use Tey\Mod\Generation\StubRegistry;
use Tey\Mod\Layout\CompiledLayout;

/** @internal Selects the current stub without invoking a generator or generating a base. */
final readonly class TypeStubs
{
    public function __construct(private Application $app, private CompiledLayout $layout, private StubRegistry $stubs) {}

    /** @return list<string> */
    public function types(): array
    {
        $types = array_unique(['class', 'interface', 'trait', 'enum', ...array_merge([], ...array_values(Starters::KINDS)), ...array_keys($this->layout->kinds()), ...array_keys($this->layout->templates())]);
        $other = array_values(array_diff($types, ['class', 'interface', 'trait', 'enum']));
        sort($other);

        return ['class', 'interface', 'trait', 'enum', ...$other];
    }

    /** @return array{contents: string, label: string, needs: ?string, placeholder: ?string} */
    public function read(string $type, ?ParsedTemplate $destination = null): array
    {
        $template = $this->layout->templates()[$type] ?? null;
        $published = $this->app->basePath($this->stubs->publishedPath($type));
        $definition = $this->stubs->resolve($type, $this->layout->hasKind($type) ? $this->layout->stub($type) : null);
        $native = null;
        $custom = is_file($published);
        if ($custom) {
            $file = $published;
        } elseif ($template !== null) {
            $file = $template['file'];
            $custom = true;
        } elseif ($definition !== null) {
            $file = $definition->choose($this->app->make(PackageDetector::class), fn (string $key): mixed => $this->app->make('config')->get($key), null)->file;
            $custom = true;
        } else {
            foreach (GeneratorRegistry::NATIVE as $class => $adapter) {
                if ((GeneratorRegistry::DEFAULTS[$type] ?? null) === $adapter) {
                    $native = $class;
                    break;
                }
            }
            if ($native === null) {
                throw new RuntimeException("There is no readable stub for [{$type}]. Start from a class: mod:template class <name>.");
            }
            $command = $this->app->make($native);
            $command->setLaravel($this->app);
            (new ReflectionProperty($command, 'input'))->setValue($command, new ArrayInput(['name' => 'Template'], $command->getDefinition()));
            $file = (new ReflectionMethod($command, 'getStub'))->invoke($command);
            if (! is_string($file)) {
                throw new RuntimeException("The {$type} generator did not provide a stub. Start from a class instead.");
            }
            if (is_file($this->app->basePath('stubs/'.basename($file)))) {
                $file = $this->app->basePath('stubs/'.basename($file));
            }
            $custom = ! str_contains(str_replace('\\', '/', $file), '/vendor/laravel/framework/');
        }
        $contents = file_get_contents($file);
        if ($contents === false) {
            throw new RuntimeException("The {$type} stub cannot be read. Check [{$file}].");
        }
        if ($type === 'class' && ! str_contains($contents, '{{ baseImport }}')) {
            $contents = str_replace('namespace {{ namespace }};', "namespace {{ namespace }};\n{{ baseImport }}", $contents);
            $contents = str_replace("{{ baseImport }}\n\n", "{{ baseImport }}\n", $contents);
            $contents = str_replace('class {{ class }}', 'class {{ class }}{{ extends }}', $contents);
        }
        $label = Starters::templateLabel($type) ?? ($template !== null ? 'the '.$type.' template' : 'the '.$type.' stub');
        $needs = null;
        $placeholder = null;
        // These native generators resolve context outside the ordinary class-template contract.
        if (! $custom && in_array($type, ['listener', 'controller', 'model', 'factory', 'policy', 'test', 'observer'], true)) {
            [$needs, $placeholder] = match ($type) {
                'listener' => ['an event', 'event'], 'controller' => ['a controller base', 'rootNamespace'],
                'test' => ['a test case', 'rootNamespace'], default => ['a model', 'model'],
            };
        }
        $available = $destination === null
            ? [...($template['groups'] ?? []), ...($template['slots'] ?? [])]
            : [...$destination->groups, ...$destination->slots];
        preg_match_all('/\{\{\s*([^{}]+?)\s*\}\}|\b(Dummy[A-Za-z]+)\b/', $contents, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $name = trim($match[2] ?? $match[1] ?? '');
            if (! in_array($name, ['namespace', 'class', 'base', 'baseClass', 'baseImport', 'extends', 'rootNamespace', ...$available], true)
                && ! preg_match('/^class(?:\.[a-z]+)*$/', $name)) {
                $needs ??= 'a '.Str::headline($name);
                $placeholder ??= $name;
            }
        }

        return compact('contents', 'label', 'needs', 'placeholder');
    }
}
