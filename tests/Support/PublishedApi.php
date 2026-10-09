<?php

namespace Tey\Mod\Tests\Support;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionType;

/** The deliberately published signatures, independent of bodies and line endings. */
final class PublishedApi
{
    /** @return array<string, array<string,mixed>> */
    public static function capture(): array
    {
        $api = [];
        $root = dirname(__DIR__, 2).'/src';
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $source = file_get_contents($file->getPathname());
            if (! str_contains($source, '@api') || preg_match('/namespace ([^;]+);/', $source, $ns) !== 1
                || preg_match('/^(?:final |abstract |readonly )*(?:class|interface|trait|enum) (\w+)/m', $source, $name) !== 1) {
                continue;
            }
            $class = new ReflectionClass($ns[1].'\\'.$name[1]);
            if (self::published($class->getDocComment())) {
                $api[$class->name] = [
                    'type' => $class->isEnum() ? 'enum' : ($class->isInterface() ? 'interface' : ($class->isTrait() ? 'trait' : 'class')),
                    'final' => $class->isFinal(), 'abstract' => $class->isAbstract(), 'readonly' => $class->isReadOnly(), 'shapes' => self::shape($class->getDocComment(), $class),
                    'interfaces' => array_values(array_filter($class->getInterfaceNames(), static fn (string $interface): bool => ! str_starts_with($interface, 'Tey\\Mod\\') || self::published((new ReflectionClass($interface))->getDocComment()))),
                    'parent' => ($parent = $class->getParentClass()) ? $parent->name : null,
                ];
                if ($class->isEnum()) {
                    $enum = new \ReflectionEnum($class->name);
                    $api[$class->name]['backing'] = $enum->getBackingType()?->getName();
                    $api[$class->name]['cases'] = array_map(static fn ($case): array => ['name' => $case->getName(), 'value' => $case instanceof \ReflectionEnumBackedCase ? $case->getBackingValue() : null], $enum->getCases());
                }
            }
            foreach ($class->getMethods() as $method) {
                if (self::published($method->getDocComment())) {
                    $api[$class->name.'::'.$method->name] = self::method($method);
                }
            }
            foreach ($class->getProperties() as $property) {
                if (self::published($property->getDocComment())) {
                    $api[$class->name.'::$'.$property->name] = [
                        'type' => self::type($property->getType(), $property->getDeclaringClass()),
                        'visibility' => $property->isPublic() ? 'public' : 'protected',
                        'readonly' => $property->isReadOnly(), 'static' => $property->isStatic(),
                        'shape' => self::shape($property->getDocComment(), $property->getDeclaringClass()),
                    ];
                }
            }
            foreach ($class->getReflectionConstants() as $constant) {
                if (self::published($constant->getDocComment())) {
                    $api[$class->name.'::'.$constant->name] = ['value' => $constant->getValue(), 'visibility' => $constant->isPublic() ? 'public' : 'protected'];
                }
            }
            if ($class->name === 'Tey\\Mod\\Facades\\Mod') {
                preg_match_all('/@method\s+([^\r\n]+)/', $class->getDocComment(), $facade);
                $signatures = array_map(static fn (string $signature): string => preg_replace('/\).*/', ')', $signature), $facade[1]);
                preg_match_all('/^use ([A-Za-z_\\\\]+)(?: as (\w+))?;/m', $source, $imports, PREG_SET_ORDER);
                foreach ($imports as $import) {
                    $parts = explode('\\', $import[1]);
                    $alias = $import[2] ?? end($parts);
                    $signatures = array_map(static fn (string $signature): string => preg_replace('/(?<![A-Za-z_\\\\$])'.preg_quote($alias, '/').'(?![A-Za-z_\\\\])/', addcslashes($import[1], '\\$'), $signature), $signatures);
                }
                $api[$class->name]['facade'] = $signatures;
            }
        }
        ksort($api);

        return $api;
    }

    /** @return array<string,mixed> */
    public static function method(ReflectionMethod $method): array
    {
        if (! self::published($method->getDocComment())) {
            throw new \InvalidArgumentException('The signature is not published.');
        }
        $class = $method->getDeclaringClass();

        return [
            'visibility' => $method->isPublic() ? 'public' : 'protected',
            'static' => $method->isStatic(), 'final' => $method->isFinal(), 'abstract' => $method->isAbstract(),
            'reference' => $method->returnsReference(),
            'parameters' => array_map(static fn (ReflectionParameter $parameter): array => [
                'name' => $parameter->name, 'type' => self::type($parameter->getType(), $class),
                'optional' => $parameter->isOptional(), 'variadic' => $parameter->isVariadic(), 'reference' => $parameter->isPassedByReference(),
                'default' => $parameter->isDefaultValueAvailable() ? self::defaultValue($parameter->getDefaultValue()) : null,
            ], $method->getParameters()),
            'return' => self::type($method->getReturnType(), $class),
            'shape' => self::shape($method->getDocComment(), $class, $method->getFileName()),
        ];
    }

    /**
     * @param  array<string,array<string,mixed>>  $api
     * @return list<string>
     */
    public static function violations(array $api): array
    {
        $errors = [];
        foreach ($api as $symbol => $entry) {
            if (preg_match('/kind|preset/i', $symbol)) {
                $errors[] = $symbol.' uses internal vocabulary';
            }
            foreach ($entry['parameters'] ?? [] as $parameter) {
                if (preg_match('/kind|preset/i', $parameter['name'])) {
                    $errors[] = $symbol.' parameter '.$parameter['name'].' uses internal vocabulary';
                }
            }
            // Native and documented nested types must themselves be published.
            $types = json_encode([$entry['return'] ?? null, $entry['type'] ?? null, $entry['parameters'] ?? [], $entry['shape'] ?? null, $entry['shapes'] ?? null, $entry['facade'] ?? null], JSON_UNESCAPED_SLASHES);
            $types = str_replace('\\\\', '\\', $types);
            preg_match_all('/Tey\\\\Mod\\\\[A-Za-z_\\\\]+/', $types, $names);
            foreach (array_unique($names[0]) as $type) {
                $type = rtrim($type, '\\');
                if (! isset($api[$type])) {
                    $errors[] = $symbol.' exposes unpublished '.$type;
                }
            }
            foreach ($entry['facade'] ?? [] as $signature) {
                preg_match('/^(?:static )?[^ ]+ (\w+)\(/', $signature, $method);
                preg_match_all('/\$(\w+)/', $signature, $parameters);
                if (preg_match('/kind|preset/i', ($method[1] ?? '').implode('', $parameters[1]))) {
                    $errors[] = $symbol.' facade signature uses internal vocabulary';
                }
            }
        }

        return array_values(array_unique($errors));
    }

    private static function published(string|false $doc): bool
    {
        return $doc !== false && str_contains($doc, '@api') && ! str_contains($doc, '@internal');
    }

    /** @param ReflectionClass<object> $class */
    private static function type(?ReflectionType $type, ReflectionClass $class): ?string
    {
        if ($type === null) {
            return null;
        }
        if ($type instanceof ReflectionNamedType) {
            $name = $type->getName();
            if ($name === 'self' || $name === 'static') {
                $name = $class->name;
            }

            return ($type->allowsNull() && ! in_array($name, ['mixed', 'null'], true) ? '?' : '').$name;
        }
        if (! $type instanceof \ReflectionUnionType && ! $type instanceof \ReflectionIntersectionType) {
            throw new \LogicException('Unknown reflected type.');
        }

        return implode($type instanceof \ReflectionUnionType ? '|' : '&', array_map(static fn (ReflectionType $part): ?string => self::type($part, $class), $type->getTypes()));
    }

    private static function defaultValue(mixed $value): mixed
    {
        return $value instanceof \BackedEnum ? $value::class.'::'.$value->name : $value;
    }

    /** Read the balanced PHPDoc type, stopping before its prose description. */
    private static function docType(string $text): string
    {
        $depth = 0;
        $length = strlen($text);
        for ($index = 0; $index < $length; $index++) {
            $character = $text[$index];
            if (str_contains('<({[', $character)) {
                $depth++;
            }
            if (str_contains('>)}]', $character)) {
                $depth--;
            }
            if ($depth === 0 && ctype_space($character)) {
                $next = ltrim(substr($text, $index));
                $previous = rtrim(substr($text, 0, $index));
                if (! str_starts_with($next, '|') && ! str_starts_with($next, '&') && ! str_ends_with($previous, '|') && ! str_ends_with($previous, '&') && ! str_ends_with($previous, ':')) {
                    return trim(substr($text, 0, $index));
                }
            }
        }

        return trim($text);
    }

    /** @param ReflectionClass<object> $class */
    private static function shape(string|false $doc, ReflectionClass $class, string|false|null $file = null): string
    {
        $source = file_get_contents($file ?: $class->getFileName());
        $aliases = [];
        preg_match_all('/^use ([A-Za-z_\\\\]+)(?: as (\w+))?;/m', $source, $imports, PREG_SET_ORDER);
        foreach ($imports as $import) {
            $parts = explode('\\', $import[1]);
            $aliases[$import[2] ?? end($parts)] = $import[1];
        }
        preg_match_all('/@(param|return|var|phpstan-type|phpstan-import-type)\s+([^@]*?)(?=\*\/|@|$)/', $doc ?: '', $matches, PREG_SET_ORDER);
        $shapes = [];
        foreach ($matches as $match) {
            $body = trim(preg_replace('/\s*\*\s*/', ' ', $match[2]));
            if ($match[1] === 'param' && preg_match('/^(.*?)\s+(\$\w+)/', $body, $parameter)) {
                $shapes[] = '@param '.trim($parameter[1]).' '.$parameter[2];
            } elseif ($match[1] === 'phpstan-type') {
                [$name, $definition] = explode(' ', $body, 2);
                $shapes[] = '@phpstan-type '.$name.' '.self::docType($definition);
            } elseif ($match[1] === 'phpstan-import-type') {
                $shapes[] = '@phpstan-import-type '.$body;
            } else {
                $shapes[] = '@'.$match[1].' '.self::docType($body);
            }
        }
        $shape = implode(' ', $shapes);
        foreach ($aliases as $alias => $fqcn) {
            $shape = preg_replace('/(?<![A-Za-z_\\\\$])'.preg_quote($alias, '/').'(?![A-Za-z_\\\\])/', addcslashes($fqcn, '\\$'), $shape);
        }

        return trim(preg_replace('/\s+/', ' ', $shape));
    }
}
