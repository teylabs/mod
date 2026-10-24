<?php

namespace Tey\Mod\Discovery;

use Composer\Autoload\ClassLoader;

/**
 * Reads a class file's declaration without loading it: the parent, the
 * interfaces and the traits it names, resolved through its namespace and
 * imports. PHP fatals when it loads a class whose trait is missing, which no
 * catch can stop, so discovery checks these names before it autoloads a
 * candidate. A production install without dev packages is the usual cause.
 *
 * @internal used by DiscoveryScanner.
 */
final readonly class Declarations
{
    /**
     * The first class, interface or trait the file's declaration needs that
     * the autoloader cannot load, looking through the declarations of the
     * named classes it can find first. Null when everything is available.
     *
     * @param  array<string, true>  $visited
     */
    public function missing(string $file, array $visited = []): ?string
    {
        $visited[strtolower($file)] = true;

        foreach ($this->dependencies($file) as $name) {
            if (self::loaded($name)) {
                continue;
            }

            $dependency = self::find($name);

            if ($dependency !== null && ! isset($visited[strtolower($dependency)])) {
                $missing = $this->missing($dependency, $visited);

                if ($missing !== null) {
                    return $missing;
                }
            }

            if (! class_exists($name) && ! interface_exists($name) && ! trait_exists($name)) {
                return $name;
            }
        }

        return null;
    }

    /**
     * The same, for a class the autoloader would load from a file; null when
     * it is already loaded or no file declares it.
     */
    public function missingFor(string $class): ?string
    {
        $class = ltrim($class, '\\');

        if (self::loaded($class)) {
            return null;
        }

        $file = self::find($class);

        return $file === null ? null : $this->missing($file);
    }

    /**
     * Fully qualified extends, implements and trait names of the file's first class-like declaration.
     *
     * @return list<string>
     */
    public function dependencies(string $file): array
    {
        $source = @file_get_contents($file);

        if ($source === false) {
            return [];
        }

        $tokens = array_values(array_filter(
            token_get_all($source),
            static fn (array|string $token): bool => ! is_array($token) || ! in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true),
        ));
        $count = count($tokens);
        $namespace = '';
        /** @var array<string, string> $imports lowercased alias => name */
        $imports = [];

        for ($i = 0; $i < $count; $i++) {
            $id = is_array($tokens[$i]) ? $tokens[$i][0] : null;

            if ($id === T_NAMESPACE && self::isName($tokens[$i + 1] ?? null)) {
                [$namespace, $i] = self::name($tokens, $i + 1);
                $imports = [];
            } elseif ($id === T_USE) {
                $i = self::imports($tokens, $i + 1, $imports);
            } elseif (in_array($id, [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true) && ! self::anonymousOrConstant($tokens, $i)) {
                return self::declaration($tokens, $i + 2, $namespace, $imports);
            }
        }

        return [];
    }

    /**
     * @param  list<array{0: int, 1: string, 2: int}|string>  $tokens
     * @param  array<string, string>  $imports
     * @return list<string>
     */
    private static function declaration(array $tokens, int $i, string $namespace, array $imports): array
    {
        $names = [];
        $count = count($tokens);

        // extends and implements, up to the body.
        for (; $i < $count && $tokens[$i] !== '{'; $i++) {
            if (self::isName($tokens[$i])) {
                [$name, $i] = self::name($tokens, $i);
                $names[] = self::resolve($name, $tokens[$i], $namespace, $imports);
            }
        }

        // Trait uses at the top level of the body.
        $depth = 0;

        for (; $i < $count; $i++) {
            $token = $tokens[$i];

            if ($token === '{' || (is_array($token) && in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                $depth++;
            } elseif ($token === '}') {
                if (--$depth === 0) {
                    break;
                }
            } elseif ($depth === 1 && is_array($token) && $token[0] === T_USE) {
                for ($i++; $i < $count && $tokens[$i] !== ';' && $tokens[$i] !== '{'; $i++) {
                    if (self::isName($tokens[$i])) {
                        [$name, $i] = self::name($tokens, $i);
                        $names[] = self::resolve($name, $tokens[$i], $namespace, $imports);
                    }
                }

                if (($tokens[$i] ?? null) === '{') {
                    $depth++;
                }
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * Reads a top-level use statement into the import map and returns the index of its last token.
     *
     * @param  list<array{0: int, 1: string, 2: int}|string>  $tokens
     * @param  array<string, string>  $imports
     */
    private static function imports(array $tokens, int $i, array &$imports): int
    {
        $count = count($tokens);

        if (is_array($tokens[$i] ?? null) && in_array($tokens[$i][0], [T_FUNCTION, T_CONST], true)) {
            for (; $i < $count && $tokens[$i] !== ';'; $i++);

            return $i;
        }

        $prefix = '';
        $current = null;

        for (; $i < $count && $tokens[$i] !== ';'; $i++) {
            $token = $tokens[$i];

            if (self::isName($token)) {
                [$name, $i] = self::name($tokens, $i);
                $name = ltrim($name, '\\');

                if (($tokens[$i + 1] ?? null) === '{' || (is_array($tokens[$i + 1] ?? null) && $tokens[$i + 1][0] === T_NS_SEPARATOR)) {
                    $prefix = rtrim($name, '\\').'\\';
                    $i += ($tokens[$i + 1] === '{') ? 1 : 2;

                    continue;
                }

                $current = $prefix.$name;
                $imports[strtolower(self::last($current))] = $current;
            } elseif (is_array($token) && $token[0] === T_AS && $current !== null && is_array($tokens[$i + 1] ?? null)) {
                unset($imports[strtolower(self::last($current))]);
                $imports[strtolower($tokens[++$i][1])] = $current;
            } elseif ($token === '}') {
                $prefix = '';
            }
        }

        return $i;
    }

    /**
     * @param  array<string, string>  $imports
     * @param  array{0: int, 1: string, 2: int}|string  $token
     */
    private static function resolve(string $name, array|string $token, string $namespace, array $imports): string
    {
        $prefixed = static fn (string $name): string => $namespace === '' ? $name : $namespace.'\\'.$name;

        if (str_starts_with($name, '\\')) {
            return ltrim($name, '\\');
        }

        if (is_array($token) && $token[0] === T_NAME_RELATIVE) {
            return $prefixed(substr($name, strlen('namespace\\')));
        }

        $parts = explode('\\', $name, 2);
        $first = $parts[0];
        $rest = $parts[1] ?? null;
        $import = $imports[strtolower($first)] ?? null;

        if ($import !== null) {
            return $rest === null ? $import : $import.'\\'.$rest;
        }

        return $prefixed($name);
    }

    /**
     * The name starting at $i and the index of its last token.
     *
     * @param  list<array{0: int, 1: string, 2: int}|string>  $tokens
     * @return array{0: string, 1: int}
     */
    private static function name(array $tokens, int $i): array
    {
        $token = $tokens[$i];

        return [is_array($token) ? $token[1] : $token, $i];
    }

    /**
     * @param  array{0: int, 1: string, 2: int}|string|null  $token
     */
    private static function isName(array|string|null $token): bool
    {
        return is_array($token) && in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE], true);
    }

    /**
     * `new class`, `Foo::class` and `enum` used as a plain identifier are not declarations.
     *
     * @param  list<array{0: int, 1: string, 2: int}|string>  $tokens
     */
    private static function anonymousOrConstant(array $tokens, int $i): bool
    {
        $previous = $tokens[$i - 1] ?? null;

        return (is_array($previous) && in_array($previous[0], [T_NEW, T_DOUBLE_COLON, T_NULLSAFE_OBJECT_OPERATOR, T_OBJECT_OPERATOR], true))
            || ! is_array($tokens[$i + 1] ?? null)
            || $tokens[$i + 1][0] !== T_STRING;
    }

    private static function last(string $name): string
    {
        $position = strrpos($name, '\\');

        return $position === false ? $name : substr($name, $position + 1);
    }

    private static function loaded(string $name): bool
    {
        return class_exists($name, false) || interface_exists($name, false) || trait_exists($name, false) || enum_exists($name, false);
    }

    private static function find(string $class): ?string
    {
        // Composer lists only loaders that know their vendor directory, so the autoload stack is read too.
        $loaders = ClassLoader::getRegisteredLoaders();

        foreach (spl_autoload_functions() as $function) {
            if (is_array($function) && $function[0] instanceof ClassLoader) {
                $loaders[] = $function[0];
            }
        }

        foreach ($loaders as $loader) {
            $file = $loader->findFile($class);

            if (is_string($file)) {
                return realpath($file) ?: $file;
            }
        }

        return null;
    }
}
