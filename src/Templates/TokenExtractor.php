<?php

namespace Tey\Mod\Templates;

use PhpToken;
use RuntimeException;

/** @internal Token-only extraction; source classes are never executed. */
final class TokenExtractor
{
    /** @return array{name: string, namespace: string, class: string, contents: string, replaced: string, left: list<string>} */
    public function extract(string $source): array
    {
        $tokens = PhpToken::tokenize($source);
        $names = [];
        $namespace = '';
        $namespaceRange = null;
        $namespaceLine = null;
        foreach ($tokens as $i => $token) {
            if ($token->id === T_NAMESPACE) {
                $start = $i + 1;
                while (isset($tokens[$start]) && $tokens[$start]->isIgnorable()) {
                    $start++;
                }
                $end = $start;
                while (isset($tokens[$end]) && ! in_array($tokens[$end]->text, [';', '{'], true)) {
                    $end++;
                }
                $namespace = '';
                for ($j = $start; $j < $end; $j++) {
                    $namespace .= $tokens[$j]->text;
                }
                $namespace = trim($namespace);
                $namespaceRange = [$start, $end];
                $namespaceLine = $token->line;
            }
            if (in_array($token->id, [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)) {
                $next = $i + 1;
                while (isset($tokens[$next]) && $tokens[$next]->isIgnorable()) {
                    $next++;
                }
                if (isset($tokens[$next]) && $tokens[$next]->id === T_STRING) {
                    $names[] = $tokens[$next]->text;
                }
            }
        }
        if (count($names) !== 1) {
            throw new RuntimeException('mod:template --from needs a PHP file with exactly one named class, interface, trait or enum.');
        }
        $name = $names[0];
        $contents = '';
        $lines = [];
        $comments = [];
        $qualified = [];
        foreach ($tokens as $i => $token) {
            if ($namespaceRange !== null && $i >= $namespaceRange[0] && $i < $namespaceRange[1]) {
                if ($i === $namespaceRange[0]) {
                    $contents .= '{{ namespace }}';
                }

                continue;
            }
            if ($token->id === T_STRING && $token->text === $name) {
                $contents .= '{{ class }}';
                $lines[] = $token->line;
            } else {
                $contents .= $token->text;
                if (preg_match('/\b'.preg_quote($name, '/').'\b/', $token->text) === 1) {
                    if (in_array($token->id, [T_COMMENT, T_DOC_COMMENT], true)) {
                        $comments[] = $token->line;
                    } elseif (in_array($token->id, [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE], true)) {
                        $qualified[] = $token->line;
                    }
                }
            }
        }
        $left = [];
        foreach (['comment' => $comments, 'qualified name' => $qualified] as $label => $mentions) {
            if ($mentions !== []) {
                $count = count($mentions);
                $left[] = $count.' '.$label.($count === 1 ? ' mentions ' : 's mention ').$name.' ('.$this->lines($mentions).')';
            }
        }

        return ['name' => $name, 'namespace' => $namespace, 'class' => ($namespace === '' ? '' : $namespace.'\\').$name, 'contents' => $contents,
            'replaced' => ($namespaceLine === null ? 'namespace (not declared)' : 'namespace (line '.$namespaceLine.')').', '.$name.' ('.$this->lines($lines).')', 'left' => $left];
    }

    /** @param list<int> $lines */
    private function lines(array $lines): string
    {
        $lines = array_values(array_unique($lines));

        return (count($lines) === 1 ? 'line ' : 'lines ').implode(', ', $lines);
    }
}
