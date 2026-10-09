<?php

namespace Tey\Mod\Generation;

use PhpToken;

/** @internal inspect rendered PHP without treating comments or method locals as declarations */
final class ClassMembers
{
    /** @var list<PhpToken> */
    private array $tokens;

    public function __construct(string $source)
    {
        $this->tokens = array_values(array_filter(PhpToken::tokenize($source), static fn (PhpToken $token): bool => ! $token->isIgnorable()));
    }

    public function hasProperty(string $class, string $property): bool
    {
        return $this->hasMember($class, T_VARIABLE, '$'.$property);
    }

    public function hasMethod(string $class, string $method): bool
    {
        return $this->hasMember($class, T_FUNCTION, $method);
    }

    public function hasTrait(string $class, string $trait): bool
    {
        return $this->hasMember($class, T_USE, $trait);
    }

    private function hasMember(string $class, int $type, string $name): bool
    {
        $depth = 0;
        $body = null;
        $pending = false;
        $function = false;
        foreach ($this->tokens as $index => $token) {
            if ($token->is(T_CLASS) && ($this->tokens[$index + 1]->text ?? '') === $class) {
                $pending = true;
            }
            if ($token->text === '{' || $token->is([T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) {
                $depth++;
                if ($pending) {
                    $body = $depth;
                    $pending = false;
                }

                continue;
            }
            if ($token->text === '}') {
                if ($depth === $body) {
                    return false;
                }
                $depth--;
                if ($depth === $body) {
                    $function = false;
                }

                continue;
            }
            if ($body === null || $depth !== $body) {
                continue;
            }
            if ($token->is(T_FUNCTION)) {
                $function = true;
                $next = $index + 1;
                if (($this->tokens[$next]->text ?? '') === '&') {
                    $next++;
                }
                if ($type === T_FUNCTION && strcasecmp($this->tokens[$next]->text ?? '', $name) === 0) {
                    return true;
                }
            }
            if ($type === T_VARIABLE && ! $function && $token->is(T_VARIABLE) && $token->text === $name) {
                return true;
            }
            if ($type === T_USE && $token->is(T_USE)) {
                for ($next = $index + 1; isset($this->tokens[$next]) && ! $this->tokens[$next]->is([';', '{']); $next++) {
                    if (strcasecmp(ltrim($this->tokens[$next]->text, '\\'), ltrim($name, '\\')) === 0
                        || strcasecmp($this->imports()[strtolower($this->tokens[$next]->text)] ?? '', ltrim($name, '\\')) === 0) {
                        return true;
                    }
                }
            }
            if ($token->text === ';') {
                $function = false;
            }
        }

        return false;
    }

    public function hasImport(string $name, ?string $alias = null): bool
    {
        return strcasecmp($this->imports()[strtolower($alias ?? class_basename($name))] ?? '', ltrim($name, '\\')) === 0;
    }

    /** @return array<string, string> lower-case local name => imported class */
    private function imports(): array
    {
        $imports = [];
        $depth = 0;
        $namespaceDepth = 0;
        $namespace = false;
        foreach ($this->tokens as $index => $token) {
            if ($token->is(T_NAMESPACE)) {
                $namespace = true;
            }
            if ($token->text === '{') {
                $depth++;
                if ($namespace) {
                    $namespaceDepth = $depth;
                    $namespace = false;
                }
            } elseif ($token->text === '}') {
                $depth--;
            } elseif ($token->text === ';') {
                $namespace = false;
            } elseif ($depth === $namespaceDepth && $token->is(T_USE)) {
                $prefix = '';
                $name = '';
                $alias = '';
                $aliased = false;
                for ($next = $index + 1; isset($this->tokens[$next]); $next++) {
                    $part = $this->tokens[$next];
                    if ($part->is([T_FUNCTION, T_CONST])) {
                        break;
                    }
                    if ($part->text === '{') {
                        $prefix = $name;
                        $name = '';
                    } elseif ($part->is(T_AS)) {
                        $aliased = true;
                    } elseif ($part->is([',', '}', ';'])) {
                        if ($name !== '') {
                            $fqcn = ltrim($prefix.$name, '\\');
                            $imports[strtolower($alias !== '' ? $alias : class_basename($fqcn))] = $fqcn;
                        }
                        $name = $alias = '';
                        $aliased = false;
                        if ($part->text === '}') {
                            $prefix = '';
                        }
                        if ($part->text === ';') {
                            break;
                        }
                    } elseif ($aliased) {
                        $alias .= $part->text;
                    } else {
                        $name .= $part->text;
                    }
                }
            }
        }

        return $imports;
    }
}
