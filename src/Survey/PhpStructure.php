<?php

namespace Tey\Mod\Survey;

use ParseError;
use PhpToken;
use Tey\Mod\Rename\Php\Symbols;

/**
 * @internal Parse source without executing it. Bind imports with the existing PHP tokenizer.
 *
 * @phpstan-type Edge array{from: string, kind: string, to: string}
 * @phpstan-type Structure array{classes: list<string>, edges: list<Edge>, parsed: bool}
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
            return ['classes' => [], 'edges' => [], 'parsed' => false];
        }
        $classes = [];
        $edges = [];
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

        return ['classes' => $classes, 'edges' => $edges, 'parsed' => true];
    }
}
