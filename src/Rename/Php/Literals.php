<?php

namespace Tey\Mod\Rename\Php;

use PhpToken;
use Tey\Mod\Rename\Checklist;
use Tey\Mod\Rename\Contribution;
use Tey\Mod\Rename\Edit;
use Tey\Mod\Rename\InputFile;
use Tey\Mod\Rename\Inputs;

/** @internal Framework identity calls and advisory runtime strings remain separate. */
final class Literals
{
    /** @param array<string, array<string, string>> $identities
     */
    public function analyse(InputFile $file, Inputs $inputs, Symbols $symbols, array $identities): Contribution
    {
        $tokens = $symbols->tokens;
        $allIdentities = array_merge(...array_values($identities));
        $edits = [];
        $checklist = [];
        $names = [];
        foreach ($inputs->members as $member) {
            $names[] = $member->old->name;
            if ($member->old->fqcn() !== null) {
                $names[] = $member->old->fqcn();
            }
            foreach ($member->oldIdentity as $identity) {
                $names[] = $identity;
            }
        }
        $delimiters = [];
        foreach ($tokens as $i => $token) {
            if ($token->text === '(') {
                $delimiters[] = ($tokens[$i - 1]->id ?? null) === T_INLINE_HTML && ($tokens[$i - 1]->text ?? '') === 'blade-identity' ? 'frontend' : 'php';
            } elseif (in_array($token->text, ['[', '#['], true)) {
                $delimiters[] = 'array';
            } elseif (in_array($token->text, [')', ']'], true)) {
                array_pop($delimiters);
            }
            if ($token->id === T_CONSTANT_ENCAPSED_STRING && end($delimiters) === 'frontend' && in_array($tokens[$i - 1]->text ?? '', ['(', ','], true)) {
                continue;
            }
            if (($token->id === T_NEW && in_array($tokens[$i + 1]->text ?? '', ['(', '$'], true)) || ($token->id === T_NEW && ($tokens[$i + 1]->id ?? null) === T_VARIABLE) || ($token->id === T_VARIABLE && ($tokens[$i + 1]->id ?? null) === T_DOUBLE_COLON)) {
                $checklist[] = new Checklist($file->path, $token->line, 'dynamic-class', 'Computed class name requires review.');
            }
            if ($token->id === T_STRING && in_array(strtolower($token->text), ['class_exists', 'interface_exists', 'trait_exists', 'enum_exists', 'is_a', 'is_subclass_of', 'unserialize'], true) && ($tokens[$i + 1]->text ?? '') === '(' && ($tokens[$i + 2]->id ?? null) !== T_CONSTANT_ENCAPSED_STRING && ! in_array($tokens[$i - 1]->id ?? null, [T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION], true)) {
                $checklist[] = new Checklist($file->path, $token->line, 'dynamic-class', 'Computed class or serialized value requires review.');
            }
            $callKind = $this->callKind($tokens, $i, $symbols);
            if ($callKind !== null && ($tokens[$i + 1]->text ?? '') === '(' && (($tokens[$i + 2]->id ?? null) !== T_CONSTANT_ENCAPSED_STRING || ! in_array($tokens[$i + 3]->text ?? '', [',', ')'], true)) && ($identities[$callKind] ?? []) !== []) {
                $checklist[] = new Checklist($file->path, $token->line, 'dynamic-identity', 'Computed framework identity requires review.');
            }
            if ($token->id !== T_CONSTANT_ENCAPSED_STRING) {
                continue;
            }
            $value = $this->value($token->text);
            $call = $tokens[$i - 2] ?? null;
            $previous = $tokens[$i - 3] ?? null;
            $first = ($tokens[$i - 1]->text ?? '') === '(';
            $kind = $first ? $this->callKind($tokens, $i - 2, $symbols) : null;
            $framework = $kind !== null;
            $target = $kind === null ? null : ($identities[$kind][$value] ?? null);
            $literalArgument = in_array($tokens[$i + 1]->text ?? '', [',', ')'], true);
            if ($framework && $target !== null && $target !== $value && $literalArgument && ! $file->historicalMigration) {
                $quote = $token->text[0];
                $new = $quote.str_replace(['\\', $quote], ['\\\\', '\\'.$quote], $target).$quote;
                if ($new !== $token->text) {
                    $edits[] = new Edit($file->path, $token->pos, $token->text, $new, 'php-identity', $token->line);
                }

                continue;
            }
            $matches = false;
            foreach ($names as $name) {
                if ($name !== '' && preg_match('~(?<![\pL\pN_])'.preg_quote($name, '~').'(?:s)?(?![\pL\pN_])~iu', $value) === 1) {
                    $matches = true;
                    break;
                }
            }
            $parts = [];
            for ($j = max(0, $i - 5); $j < $i; $j++) {
                $parts[] = $tokens[$j]->text;
            }
            $window = implode(' ', $parts);
            $database = preg_match('/\$(?:table|foreignKey|primaryKey)\s*=\s*$/', $window) === 1 || ($first && $call !== null && in_array(strtolower($call->text), ['table', 'create', 'rename', 'constrained', 'foreign', 'references', 'ontable'], true));
            if (! $matches && ! $database) {
                continue;
            }
            $category = 'uncertain-string';
            if ($file->historicalMigration) {
                $category = 'historical-migration';
            } elseif ($database) {
                $category = 'database-name';
            } elseif (str_starts_with($file->path, 'config/')) {
                $category = 'config-string';
            } elseif (str_starts_with($file->path, 'lang/') || str_contains($file->path, '/lang/')) {
                $category = 'translation';
            } elseif ($first && $call !== null && in_array(strtolower($call->text), ['__', 'trans', 'trans_choice'], true) && ! in_array($previous?->id, [T_OBJECT_OPERATOR, T_DOUBLE_COLON], true)) {
                $category = 'translation';
            } elseif ($first && $call !== null && strtolower($call->text) === 'config' && ! in_array($previous?->id, [T_OBJECT_OPERATOR, T_DOUBLE_COLON], true)) {
                $category = 'config-string';
            } elseif ($first && $call !== null && strtolower($call->text) === 'route' && ! in_array($previous?->id, [T_OBJECT_OPERATOR, T_DOUBLE_COLON], true)) {
                $category = 'route-name';
            } elseif ($first && $call !== null && strtolower($call->text) === 'name' && $previous?->id === T_OBJECT_OPERATOR) {
                $category = 'route-name';
            } elseif ($first && $call !== null && in_array(strtolower($call->text), ['get', 'post', 'put', 'patch', 'delete', 'options', 'any', 'match', 'prefix'], true) && $previous?->id === T_DOUBLE_COLON && isset($tokens[$i - 4]) && strtolower($symbols->resolve($i - 4)) === 'illuminate\\support\\facades\\route') {
                $category = 'route-uri';
            } elseif ($first && $call !== null && in_array(strtolower($call->text), ['class_exists', 'interface_exists', 'trait_exists', 'enum_exists', 'is_a', 'is_subclass_of', 'unserialize'], true)) {
                $category = 'dynamic-class';
            } elseif ($framework || isset($allIdentities[$value])) {
                $category = 'unsupported-identity';
            }
            $checklist[] = new Checklist($file->path, $token->line, $category, "{$token->text} is unchanged.", $allIdentities[$value] ?? null);
        }

        return new Contribution($edits, $checklist);
    }

    /** @param list<PhpToken> $tokens */
    private function callKind(array $tokens, int $i, Symbols $symbols): ?string
    {
        $call = $tokens[$i] ?? null;
        $previous = $tokens[$i - 1] ?? null;
        if ($call === null) {
            return null;
        }
        $name = strtolower(ltrim($call->text, '\\'));
        if (in_array($call->id, [T_STRING, T_NAME_FULLY_QUALIFIED], true) && ! in_array($previous?->id, [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_NEW, T_FUNCTION], true)) {
            return $symbols->frameworkFunction($i);
        }
        if ($name === 'render' && $previous?->id === T_DOUBLE_COLON && isset($tokens[$i - 2]) && strtolower($symbols->resolve($i - 2)) === 'inertia\\inertia') {
            return 'inertia';
        }

        return null;
    }

    private function value(string $literal): string
    {
        $body = substr($literal, 1, -1);

        if ($literal[0] === "'") {
            return preg_replace_callback("~\\\\([\\\\'])~", static fn (array $match): string => $match[1], $body) ?? $body;
        }

        // PHP preserves unknown escapes; stripcslashes alone would remove them.
        return preg_replace_callback('~\\\\(?:[\\\\"$nrtvef]|[0-7]{1,3}|x[0-9a-fA-F]{1,2})~', static fn (array $match): string => stripcslashes($match[0]), $body) ?? $body;
    }
}
