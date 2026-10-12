<?php

namespace Tey\Mod\Survey;

use ParseError;
use PhpToken;
use Tey\Mod\Rename\Php\Symbols;

/**
 * @internal Parse source without executing it. Bind imports with the existing PHP tokenizer.
 *
 * @phpstan-type Edge array{from: string, kind: string, to: string}
 * @phpstan-type Callback array{line: int, target: string, references: list<string>}
 * @phpstan-type Table array{line: int, name: string, operation: string}
 * @phpstan-type Structure array{classes: list<string>, edges: list<Edge>, parsed: bool, callbacks: list<Callback>, tables: list<Table>}
 */
final class PhpStructure
{
    /** @return Structure */
    public function read(string $source, string $path): array
    {
        try {
            $symbols = new Symbols(PhpToken::tokenize($source, TOKEN_PARSE), []);
            $symbols->analyse();
        } catch (ParseError) {
            return ['classes' => [], 'edges' => [], 'parsed' => false, 'callbacks' => [], 'tables' => []];
        }
        $classes = [];
        $edges = [];
        $callbacks = [];
        $tables = [];
        $tokens = $symbols->tokens;
        $depth = 0;
        $classDepth = null;
        $owner = 'file:'.$path;
        foreach ($tokens as $i => $token) {
            if ($token->text === '{') {
                $depth++;
            } elseif ($token->text === '}') {
                $depth--;
                if ($classDepth !== null && $depth < $classDepth) {
                    $owner = 'file:'.$path;
                    $classDepth = null;
                }
            }
            if (in_array($token->id, [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true) && ($tokens[$i + 1]->id ?? null) === T_STRING) {
                $owner = $symbols->resolve($i + 1);
                $classes[] = $owner;
                $classDepth = $depth + 1;
            }
            // Namespace imports apply to each declaration, and to route/other procedural files.
            if ($token->id === T_USE || in_array($token->id, [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)) {
                foreach ($symbols->importedClasses($i) as $import) {
                    $edges[] = ['from' => $owner, 'kind' => 'import', 'to' => $import];
                }
            }
            $reference = $symbols->referencedClass($i);
            if ($reference !== null) {
                $edges[] = ['from' => $owner, 'kind' => 'class', 'to' => $reference];
            }
            if ($reference !== null && ($tokens[$i + 1]->id ?? null) === T_DOUBLE_COLON
                && ($tokens[$i + 2]->id ?? null) === T_STRING && ($tokens[$i + 3]->text ?? '') === '(') {
                $method = $tokens[$i + 2]->text;
                $argument = $tokens[$i + 4] ?? null;
                if (strtolower($reference) === 'illuminate\\support\\facades\\schema'
                    && in_array(strtolower($method), ['create', 'table', 'drop', 'dropifexists', 'rename'], true)
                    && $argument?->id === T_CONSTANT_ENCAPSED_STRING
                    && preg_match('/^[a-zA-Z0-9_]+$/D', substr($argument->text, 1, -1)) === 1
                    && in_array($tokens[$i + 5]->text ?? '', [',', ')'], true)) {
                    $tables[] = ['line' => $token->line, 'name' => substr($argument->text, 1, -1), 'operation' => strtolower($method)];
                }
                $nesting = 1;
                $callback = false;
                $references = [];
                for ($j = $i + 4; isset($tokens[$j]) && $nesting > 0; $j++) {
                    if ($tokens[$j]->text === '(') {
                        $nesting++;
                    } elseif ($tokens[$j]->text === ')') {
                        $nesting--;
                    }
                    $callback = $callback || $tokens[$j]->id === T_FN
                        || ($tokens[$j]->id === T_FUNCTION && in_array($tokens[$j + 1]->text ?? '', ['(', '&'], true));
                    $used = $symbols->referencedClass($j);
                    if ($used !== null) {
                        $references[] = $used;
                    }
                }
                if ($callback && strtolower($reference) !== 'illuminate\\support\\facades\\schema') {
                    $callbacks[] = ['line' => $token->line, 'target' => $reference.'::'.$method, 'references' => array_values(array_unique($references))];
                }
            }
            if (($tokens[$i + 1]->id ?? null) === T_DOUBLE_COLON && strtolower($tokens[$i + 2]->text ?? '') === 'render'
                && ($tokens[$i + 3]->text ?? '') === '(' && strtolower($symbols->resolve($i)) === 'inertia\\inertia') {
                $argument = $i + 4;
                if (($tokens[$argument]->text ?? '') === 'component' && ($tokens[$argument + 1]->text ?? '') === ':') {
                    $argument += 2;
                }
                $literal = $tokens[$argument] ?? null;
                if ($literal?->id === T_CONSTANT_ENCAPSED_STRING && in_array($tokens[$argument + 1]->text ?? '', [',', ')'], true)) {
                    $value = substr($literal->text, 1, -1);
                    $value = $literal->text[0] === "'" ? str_replace(["\\'", '\\\\'], ["'", '\\'], $value) : stripcslashes($value);
                    // Page identities are structural names, not arbitrary response strings.
                    if (preg_match('~^[\pL\pN_./:\\-]+$~uD', $value) === 1 && ! in_array('..', explode('/', $value), true)) {
                        $edges[] = ['from' => $owner, 'kind' => 'page', 'to' => 'page:'.$value];
                    }
                }
            }
        }

        return ['classes' => $classes, 'edges' => $edges, 'parsed' => true, 'callbacks' => $callbacks, 'tables' => $tables];
    }
}
