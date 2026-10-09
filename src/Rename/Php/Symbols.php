<?php

namespace Tey\Mod\Rename\Php;

use PhpToken;
use Tey\Mod\Rename\Checklist;
use Tey\Mod\Rename\Contribution;
use Tey\Mod\Rename\Edit;

/** @internal Token bindings are local to each namespace, including bracketed scopes. */
final class Symbols
{
    /** @var list<PhpToken> */
    public array $tokens;

    /** @var array<int, string> */
    private array $namespaces = [];

    /** @var array<int, int> */
    private array $scopes = [];

    /** @var array<int, array<string, array{class: string, after: string, alias: string}>> */
    private array $imports = [];

    /** @var array<int, array<string, string>> */
    private array $functions = [];

    /** @var array<string, true> */
    private array $declared = [];

    /** @var array<string, int> */
    private array $types = [];

    /** @var array<int, true> */
    private array $skip = [];

    /** @var array<int, true> */
    private array $contexts = [];

    /** @var list<Edit> */
    private array $edits = [];

    /** @var list<string> */
    private array $blockers = [];

    /** @param array<int, PhpToken> $tokens
     * @param  array<string, true>  $functionShadows
     * @param  array<string, string>  $namespaceChanges
     * @param  array<string, string>  $classes  Lowercase original FQCN => target FQCN.
     */
    public function __construct(array $tokens, private array $classes, private array $namespaceChanges = [], private array $functionShadows = [])
    {
        $this->tokens = array_values(array_filter($tokens, static fn (PhpToken $token): bool => ! $token->isIgnorable()));
    }

    public function analyse(): Contribution
    {
        $scope = 0;
        $namespace = '';
        $depth = 0;
        $base = 0;
        $declarations = [];
        foreach ($this->tokens as $i => $token) {
            if ($token->id === T_NAMESPACE) {
                $scope++;
                $namespace = $this->name($i + 1) ? $this->tokens[$i + 1]->text : '';
                $this->skip[$i + 1] = true;
                $base = ($this->tokens[$i + ($namespace === '' ? 1 : 2)]->text ?? '') === '{' ? 1 : 0;
            }
            $this->scopes[$i] = $scope;
            $this->namespaces[$i] = $namespace;
            $functionName = ($this->tokens[$i + 1]->text ?? '') === '&' ? $i + 2 : $i + 1;
            if ($token->id === T_FUNCTION && $depth === $base && $this->name($functionName)) {
                $function = strtolower(trim($namespace.'\\'.$this->tokens[$functionName]->text, '\\'));
                $this->declared[$function] = true;
            }
            if ($token->id === T_USE && $depth === $base && ($this->tokens[$i - 1]->text ?? '') !== ')') {
                $this->readImport($i, $scope);
            }
            if (in_array($token->id, [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true) && $this->name($i + 1)) {
                $type = trim($namespace.'\\'.$this->tokens[$i + 1]->text, '\\');
                $this->types[strtolower($type)] = $this->tokens[$i + 1]->line;
                $declarations[$scope][] = $this->tokens[$i + 1];
                $this->skip[$i + 1] = true;
            }
            if ($token->text === '{') {
                $depth++;
            } elseif ($token->text === '}') {
                $depth--;
                if ($base === 1 && $depth === 0) {
                    $namespace = '';
                    $scope++;
                    $base = 0;
                }
            }
        }
        foreach ($this->imports as $id => $imports) {
            $bindings = [];
            foreach ($imports as $import) {
                $binding = strtolower($import['alias']);
                if (isset($bindings[$binding])) {
                    $this->blockers[] = "PHP import collision for {$import['alias']}. Choose non-conflicting imports before retrying.";
                }
                $bindings[$binding] = strtolower($import['after']);
            }
            foreach ($declarations[$id] ?? [] as $declaration) {
                $original = trim(($this->namespaces[array_search($declaration, $this->tokens, true)] ?? '').'\\'.$declaration->text, '\\');
                $after = $this->classes[strtolower($original)] ?? $original;
                $binding = strtolower($this->basename($after));
                if (isset($bindings[$binding])) {
                    $this->blockers[] = "PHP declaration/import collision for {$this->basename($after)}. Choose non-conflicting imports before retrying.";
                }
            }
        }
        $this->markTypes();
        $checklist = [];
        foreach ($this->tokens as $i => $token) {
            if (! $this->name($i) || isset($this->skip[$i])) {
                continue;
            }
            $before = $this->resolve($i);
            $target = $this->classes[strtolower($before)] ?? null;
            $namespaceMoved = isset($this->namespaceChanges[$this->namespaces[$i]]) && $this->namespaceChanges[$this->namespaces[$i]] !== $this->namespaces[$i];
            $prefix = explode('\\', $token->text, 2)[0];
            $import = $this->imports[$this->scopes[$i]][strtolower($prefix)] ?? null;
            $bindingMoved = $import !== null && $import['class'] !== $import['after'];
            if ($target === null && ! $namespaceMoved && ! $bindingMoved) {
                continue;
            }
            if (in_array(strtolower($token->text), ['self', 'parent', 'static', 'int', 'float', 'bool', 'string', 'array', 'object', 'callable', 'iterable', 'mixed', 'never', 'void', 'null', 'false', 'true'], true)) {
                continue;
            }
            $previous = $this->tokens[$i - 1] ?? null;
            $next = $this->tokens[$i + 1] ?? null;
            // Member/function names have a different symbol space.
            if (in_array($previous?->id, [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_CONST], true)) {
                continue;
            }
            $known = isset($this->contexts[$i]) || $next?->id === T_DOUBLE_COLON || in_array($previous?->id, [T_NEW, T_INSTANCEOF], true);
            if ($target === null) {
                if ($known) {
                    $this->edit($token, $this->spelling($i, $before), 'php-symbol');
                }

                continue;
            }
            if (! $known) {
                if ($next?->text !== '(' && $next?->text !== ':') {
                    $checklist[] = new Checklist('', $token->line, 'uncertain-php-name', "{$token->text} has an unsupported PHP name context; review it.", $target);
                }

                continue;
            }
            $after = $this->spelling($i, $target);
            $this->edit($token, $after, 'php-symbol');
        }

        return new Contribution($this->edits, $checklist, blockers: $this->blockers);
    }

    /** @return array<string, true> */
    public function declaredFunctions(): array
    {
        return $this->declared;
    }

    /** @return array<string, int> */
    public function declaredTypes(): array
    {
        return $this->types;
    }

    public function resolve(int $i): string
    {
        $text = $this->tokens[$i]->text;
        if (str_starts_with($text, '\\')) {
            return ltrim($text, '\\');
        }
        $namespace = $this->namespaces[$i] ?? '';
        if (str_starts_with(strtolower($text), 'namespace\\')) {
            return trim($namespace.'\\'.substr($text, 10), '\\');
        }
        $parts = explode('\\', $text, 2);
        $import = $this->imports[$this->scopes[$i] ?? 0][strtolower($parts[0])] ?? null;

        return $import === null ? trim($namespace.'\\'.$text, '\\') : $import['class'].(isset($parts[1]) ? '\\'.$parts[1] : '');
    }

    public function frameworkFunction(int $i): ?string
    {
        $name = strtolower($this->tokens[$i]->text);
        $import = $this->functions[$this->scopes[$i] ?? 0][$name] ?? null;
        $fqcn = str_starts_with($name, '\\') ? ltrim($name, '\\') : ($import ?? trim(($this->namespaces[$i] ?? '').'\\'.$name, '\\'));
        $fqcn = strtolower($fqcn);
        if (isset($this->functionShadows[$fqcn]) || ($import === null && isset($this->functionShadows[ltrim($name, '\\')]))) {
            return null;
        }
        $resolved = $import ?? ltrim($name, '\\');

        return in_array($resolved, ['view', 'inertia'], true) ? $resolved : null;
    }

    private function spelling(int $i, string $target): string
    {
        $text = $this->tokens[$i]->text;
        if (str_starts_with($text, '\\')) {
            return '\\'.$target;
        }
        $parts = explode('\\', $text, 2);
        $import = $this->imports[$this->scopes[$i]][strtolower($parts[0])] ?? null;
        if ($import !== null && strtolower($import['after']) === strtolower($target)) {
            return strtolower($import['alias']) === strtolower($parts[0]) ? $text : $import['alias'];
        }
        if ($import !== null && str_starts_with(strtolower($target), strtolower($import['after']).'\\')) {
            return $parts[0].substr($target, strlen($import['after']));
        }
        $namespace = $this->namespaces[$i];
        $namespace = $this->namespaceChanges[$namespace] ?? $namespace;
        if ($this->namespace($target) === $namespace) {
            return (str_starts_with(strtolower($text), 'namespace\\') ? 'namespace\\' : '').$this->basename($target);
        }

        return '\\'.$target;
    }

    private function readImport(int $start, int $scope): void
    {
        $end = $start + 1;
        while (isset($this->tokens[$end]) && $this->tokens[$end]->text !== ';') {
            $end++;
        }
        for ($i = $start; $i <= $end; $i++) {
            $this->skip[$i] = true;
        }
        $begin = $start + 1;
        $importKind = $this->tokens[$begin]->id;
        if (in_array($importKind, [T_FUNCTION, T_CONST], true)) {
            $begin++;
        }
        $prefix = '';
        $group = null;
        for ($i = $begin; $i < $end; $i++) {
            if ($this->tokens[$i]->text === '{') {
                $group = $i;
                $prefix = rtrim($this->tokens[$begin]->text, '\\').'\\';
                $begin = $i + 1;
                break;
            }
        }
        $entries = [];
        for ($i = $begin; $i < $end; $i++) {
            if (! $this->name($i) || ($this->tokens[$i - 1]->id ?? null) === T_AS) {
                continue;
            }
            $entryKind = in_array($importKind, [T_FUNCTION, T_CONST], true) ? $importKind : ($this->tokens[$i - 1]->id ?? null);
            $nonClass = in_array($entryKind, [T_FUNCTION, T_CONST], true);
            $token = $this->tokens[$i];
            $class = ltrim($prefix.$token->text, '\\');
            $after = $nonClass ? $class : ($this->classes[strtolower($class)] ?? $class);
            $aliasToken = ($this->tokens[$i + 1]->id ?? null) === T_AS ? $this->tokens[$i + 2] : null;
            $alias = $aliasToken->text ?? $this->basename($after);
            if (! $nonClass) {
                $key = strtolower($aliasToken->text ?? $this->basename($class));
                if (isset($this->imports[$scope][$key])) {
                    $this->blockers[] = "PHP import collision for {$key}. Choose non-conflicting imports before retrying.";
                }
                $this->imports[$scope][$key] = ['class' => $class, 'after' => $after, 'alias' => $alias];
            } elseif ($entryKind === T_FUNCTION) {
                $this->functions[$scope][strtolower($alias)] = strtolower($class);
            }
            $entries[] = [$token, $class, $after];
        }
        $expand = false;
        foreach ($entries as [$token, $class, $after]) {
            $expand = $expand || ($group !== null && $class !== $after && ! str_starts_with($after, $prefix));
        }
        if ($expand && $group !== null) {
            // Expand the group using punctuation-only edits; comments/whitespace survive.
            $this->edit($this->tokens[$start + 1], '', 'php-import');
            for ($i = $start + 2; $i <= $group; $i++) {
                $this->edit($this->tokens[$i], '', 'php-import');
            }
            for ($i = $group + 1; $i < $end; $i++) {
                if ($this->tokens[$i]->text === ',') {
                    // A trailing comma becomes empty, not an empty use declaration.
                    $this->edit($this->tokens[$i], ($this->tokens[$i + 1]->text ?? '') === '}' ? '' : '; use ', 'php-import');
                } elseif ($this->tokens[$i]->text === '}') {
                    $this->edit($this->tokens[$i], '', 'php-import');
                }
            }
        }
        foreach ($entries as [$token, $class, $after]) {
            if ($expand || $class !== $after) {
                $this->edit($token, $expand ? $after : ($group === null ? (str_starts_with($token->text, '\\') ? '\\' : '').$after : substr($after, strlen($prefix))), 'php-import');
            }
        }
    }

    /** Mark the class-name positions in PHP's type, attribute and trait grammar. */
    private function markTypes(): void
    {
        $count = count($this->tokens);
        for ($i = 0; $i < $count; $i++) {
            $token = $this->tokens[$i];
            if (in_array($token->id, [T_EXTENDS, T_IMPLEMENTS, T_INSTEADOF, T_USE, T_CATCH], true) && ! isset($this->skip[$i])) {
                for ($j = $i + 1; $j < $count; $j++) {
                    if (in_array($this->tokens[$j]->text, [';', '{', ')'], true) || $this->tokens[$j]->id === T_VARIABLE) {
                        break;
                    }
                    if ($this->name($j)) {
                        $this->contexts[$j] = true;
                    }
                }
            }
            if ($token->id === T_ATTRIBUTE) {
                $depth = 1;
                $arguments = 0;
                for ($j = $i + 1; $j < $count && $depth > 0; $j++) {
                    $text = $this->tokens[$j]->text;
                    if ($text === '(') {
                        $arguments++;
                    }
                    if ($text === ')') {
                        $arguments--;
                    }
                    if ($text === '[') {
                        $depth++;
                    }
                    if ($text === ']') {
                        $depth--;
                    }
                    if ($arguments === 0 && $depth === 1 && $this->name($j) && in_array($this->tokens[$j - 1]->text, ['#[', ','], true)) {
                        $this->contexts[$j] = true;
                    }
                }
            }
            if ($token->id === T_VARIABLE) {
                // Parentheses are allowed only as matched intersection-type pairs.
                $typeDepth = 0;
                for ($j = $i - 1; $j >= 0; $j--) {
                    $type = $this->tokens[$j];
                    if ($type->text === ')') {
                        $typeDepth++;
                    }
                    if ($type->text === '(') {
                        if ($typeDepth === 0) {
                            break;
                        }
                        $typeDepth--;
                    }
                    if ($this->name($j)) {
                        $this->contexts[$j] = true;
                    } elseif (! in_array($type->text, ['?', '|', '&', '(', ')', '...'], true) && ! in_array($type->id, [T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG, T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG], true)) {
                        break;
                    }
                }
            }
            if (in_array($token->id, [T_FUNCTION, T_FN], true)) {
                $open = $i + 1;
                while ($open < $count && $this->tokens[$open]->text !== '(') {
                    $open++;
                }
                $depth = 0;
                $j = $open;
                for (; $j < $count; $j++) {
                    if ($this->tokens[$j]->text === '(') {
                        $depth++;
                    }
                    if ($this->tokens[$j]->text === ')' && --$depth === 0) {
                        break;
                    }
                }
                // Closure capture can appear before its return type.
                if (($this->tokens[$j + 1]->id ?? null) === T_USE) {
                    $j += 2;
                    while ($j < $count && $this->tokens[$j]->text !== ')') {
                        $j++;
                    }
                }
                if (($this->tokens[$j + 1]->text ?? '') === ':') {
                    for ($j += 2; $j < $count && ! in_array($this->tokens[$j]->text, ['{', ';', '=>'], true); $j++) {
                        if ($this->name($j)) {
                            $this->contexts[$j] = true;
                        }
                    }
                }
            }
        }
    }

    private function name(int $i): bool
    {
        return isset($this->tokens[$i]) && in_array($this->tokens[$i]->id, [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE], true);
    }

    private function edit(PhpToken $token, string $after, string $category): void
    {
        if ($token->text !== $after) {
            $this->edits[] = new Edit('', $token->pos, $token->text, $after, $category, $token->line);
        }
    }

    private function basename(string $name): string
    {
        return substr($name, (int) strrpos('\\'.$name, '\\'));
    }

    private function namespace(string $name): string
    {
        $position = strrpos($name, '\\');

        return $position === false ? '' : substr($name, 0, $position);
    }
}
