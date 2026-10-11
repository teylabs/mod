<?php

namespace Tey\Mod\Survey;

use PhpToken;

/**
 * What a PHP file declares and references, read with PHP's tokenizer: the
 * classes it declares, the classes it imports with `use`, and the literal
 * page names it passes to `Inertia::render()` or `inertia()`. Nothing else
 * in the source, string values included, is kept.
 *
 * @internal
 */
final readonly class PhpSource
{
    /** A page name: optional `namespace::`, then slash-separated words. */
    private const PAGE = '~^(?:[A-Za-z0-9_-]+::)?[A-Za-z0-9_-]+(?:/[A-Za-z0-9_-]+)*$~D';

    /**
     * @param  list<string>  $classes
     * @param  list<string>  $imports
     * @param  list<string>  $pages
     */
    private function __construct(public array $classes, public array $imports, public array $pages) {}

    public static function parse(string $source): self
    {
        $tokens = array_values(array_filter(
            PhpToken::tokenize($source),
            static fn (PhpToken $token): bool => ! $token->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT]),
        ));
        $namespace = '';
        $importDepth = 0;
        $depth = 0;
        $classes = [];
        $imports = [];
        $aliases = [];
        $pages = [];
        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            $previous = $tokens[$i - 1] ?? null;
            if ($token->text === '{' || $token->is([T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) {
                $depth++;
            } elseif ($token->text === '}') {
                $depth = max(0, $depth - 1);
            } elseif ($token->is(T_NAMESPACE) && ! ($tokens[$i + 1] ?? null)?->is(T_NS_SEPARATOR)) {
                $namespace = '';
                for ($j = $i + 1; $j < $count && ! in_array($tokens[$j]->text, [';', '{'], true); $j++) {
                    $namespace .= $tokens[$j]->text;
                }
                $namespace = trim($namespace, '\\');
                $aliases = [];
                // A bracketed namespace keeps its imports one level down.
                $importDepth = ($tokens[$j] ?? null)?->text === '{' ? $depth + 1 : $depth;
            } elseif ($token->is(T_USE) && $depth === $importDepth && ($tokens[$i + 1] ?? null)?->text !== '(') {
                $i = self::imports($tokens, $i + 1, $imports, $aliases);
            } elseif ($token->is([T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM]) && ! $previous?->is([T_DOUBLE_COLON, T_NEW]) && ($tokens[$i + 1] ?? null)?->is(T_STRING)) {
                $classes[] = ltrim($namespace.'\\'.$tokens[$i + 1]->text, '\\');
            } elseif (($page = self::page($tokens, $i, $aliases)) !== null) {
                $pages[] = $page;
            }
        }

        return new self(self::sorted($classes), self::sorted($imports), self::sorted($pages));
    }

    /**
     * Read one `use` statement from its first token; returns the index of its `;`.
     *
     * @param  list<PhpToken>  $tokens
     * @param  list<string>  $imports
     * @param  array<string, string>  $aliases
     */
    private static function imports(array $tokens, int $i, array &$imports, array &$aliases): int
    {
        if (($tokens[$i] ?? null)?->is([T_FUNCTION, T_CONST])) {
            while (isset($tokens[$i]) && $tokens[$i]->text !== ';') {
                $i++;
            }

            return $i;
        }
        $prefix = '';
        $names = [''];
        $as = [null];
        for (; isset($tokens[$i]) && $tokens[$i]->text !== ';'; $i++) {
            $token = $tokens[$i];
            $last = count($names) - 1;
            if ($token->text === '{') {
                $prefix = trim($names[$last], '\\');
                $names[$last] = '';
            } elseif ($token->text === ',' || $token->text === '}') {
                $names[] = '';
                $as[] = null;
            } elseif ($token->is(T_AS)) {
                $as[$last] = ($tokens[$i + 1] ?? null)?->text;
                $i++;
            } elseif ($token->is([T_FUNCTION, T_CONST])) {
                // A mixed group import of a function or constant is not a class.
                while (isset($tokens[$i + 1]) && ! in_array($tokens[$i + 1]->text, [',', '}', ';'], true)) {
                    $i++;
                }
            } elseif ($token->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NS_SEPARATOR])) {
                $names[$last] .= $token->text;
            }
        }
        foreach ($names as $index => $name) {
            $class = trim($prefix.'\\'.trim($name, '\\'), '\\');
            if ($name !== '' && $class !== '') {
                $imports[] = $class;
                $aliases[strtolower($as[$index] ?? substr((string) strrchr('\\'.$class, '\\'), 1))] = $class;
            }
        }

        return $i;
    }

    /**
     * A literal page name passed to Inertia::render() or inertia() at this token.
     *
     * @param  list<PhpToken>  $tokens
     * @param  array<string, string>  $aliases
     */
    private static function page(array $tokens, int $i, array $aliases): ?string
    {
        $token = $tokens[$i];
        if (! $token->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])) {
            return null;
        }
        $previous = $tokens[$i - 1] ?? null;
        if ($previous !== null && $previous->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW, T_CONST])) {
            return null;
        }
        $name = ltrim($token->text, '\\');
        if (strcasecmp($name, 'inertia') === 0 && ! $token->is(T_NAME_QUALIFIED) && ($tokens[$i + 1] ?? null)?->text === '(') {
            $at = $i + 2;
        } elseif (($tokens[$i + 1] ?? null)?->is(T_DOUBLE_COLON) && strcasecmp((string) ($tokens[$i + 2] ?? null)?->text, 'render') === 0 && ($tokens[$i + 3] ?? null)?->text === '(') {
            $class = $token->is(T_NAME_FULLY_QUALIFIED) ? $name : ($aliases[strtolower($name)] ?? $name);
            if (! in_array(strtolower($class), ['inertia', 'inertia\\inertia'], true)) {
                return null;
            }
            $at = $i + 4;
        } else {
            return null;
        }
        $argument = $tokens[$at] ?? null;
        // Only a whole literal argument counts, not an expression starting with one.
        if ($argument === null || ! $argument->is(T_CONSTANT_ENCAPSED_STRING) || ! in_array(($tokens[$at + 1] ?? null)?->text, [',', ')'], true)) {
            return null;
        }
        $page = substr($argument->text, 1, -1);

        return preg_match(self::PAGE, $page) === 1 ? $page : null;
    }

    /**
     * @param  list<string>  $values
     * @return list<string>
     */
    private static function sorted(array $values): array
    {
        $values = array_values(array_unique($values));
        sort($values, SORT_STRING);

        return $values;
    }
}
