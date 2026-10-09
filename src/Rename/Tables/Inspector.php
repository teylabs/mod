<?php

namespace Tey\Mod\Rename\Tables;

use Illuminate\Support\Str;
use ParseError;
use PhpToken;
use Tey\Mod\Rename\ClusterMember;
use Tey\Mod\Rename\Inputs;

/** @internal Never loads, constructs or invokes an application model. */
final class Inspector
{
    /** @return list<ModelTable> */
    public function inspect(Inputs $inputs): array
    {
        $result = [];
        foreach ($inputs->members as $member) {
            $file = $inputs->files[$member->old->path()] ?? null;
            if ($member->old->fileType->id !== 'model' || $file === null || $file->historicalMigration) {
                continue;
            }
            $table = $this->model($member, $file->bytes);
            if ($table !== null) {
                $result[] = $table;
            }
        }

        return $result;
    }

    private function model(ClusterMember $member, string $bytes): ?ModelTable
    {
        try {
            $tokens = array_values(array_filter(PhpToken::tokenize($bytes, TOKEN_PARSE), static fn (PhpToken $token): bool => ! $token->isIgnorable()));
        } catch (ParseError) {
            return new ModelTable($member, 1, null, null);
        }
        $imports = [];
        $parent = null;
        $line = 1;
        $classIndex = null;
        foreach ($tokens as $i => $token) {
            if ($token->id === T_NAMESPACE) {
                $imports = [];
            }
            if ($token->id === T_CLASS) {
                $classIndex = $i;
                $line = $token->line;
                break;
            }
            if ($token->id === T_USE) {
                $name = $tokens[$i + 1]->text ?? '';
                $alias = ($tokens[$i + 2]->id ?? null) === T_AS ? ($tokens[$i + 3]->text ?? '') : class_basename($name);
                // Group imports are conservatively unresolved.
                if (($tokens[$i + 2]->text ?? '') === ';' || ($tokens[$i + 2]->id ?? null) === T_AS) {
                    $imports[$alias] = ltrim($name, '\\');
                }
            }
        }
        if ($classIndex === null) {
            return new ModelTable($member, $line, null, null);
        }
        for ($i = $classIndex + 1; isset($tokens[$i]) && $tokens[$i]->text !== '{'; $i++) {
            if ($tokens[$i]->id === T_EXTENDS) {
                $name = $tokens[$i + 1]->text ?? '';
                $parent = str_starts_with($name, '\\') ? ltrim($name, '\\') : ($imports[$name] ?? $name);
            }
        }
        // A recipe can call an ordinary PHP class "model"; do not invent Eloquent behaviour.
        if ($parent === null) {
            return null;
        }
        $ambiguous = $parent !== 'Illuminate\\Database\\Eloquent\\Model';
        $explicit = false;
        $table = null;
        $depth = 0;
        foreach ($tokens as $index => $token) {
            if ($index <= $classIndex) {
                continue;
            }
            if ($token->text === '{') {
                $depth++;
            } elseif ($token->text === '}') {
                $depth--;
            }
            $methodIndex = ($tokens[$index + 1]->text ?? '') === '&' ? $index + 2 : $index + 1;
            if ($token->id === T_USE) {
                $traitIndex = $index + 1;
                do {
                    $name = $tokens[$traitIndex]->text ?? '';
                    $trait = str_starts_with($name, '\\') ? ltrim($name, '\\') : ($imports[$name] ?? $name);
                    if (! in_array($trait, ['Illuminate\\Database\\Eloquent\\Factories\\HasFactory', 'Illuminate\\Database\\Eloquent\\SoftDeletes'], true)) {
                        $ambiguous = true;
                    }
                    $traitIndex++;
                    if (($tokens[$traitIndex]->text ?? '') !== ',') {
                        break;
                    }
                    $traitIndex++;
                } while (isset($tokens[$traitIndex]));
                // Adaptations can expose custom naming hooks; never guess their effect.
                if (($tokens[$traitIndex]->text ?? '') !== ';') {
                    $ambiguous = true;
                }
            }
            if ($token->id === T_FUNCTION && in_array(strtolower($tokens[$methodIndex]->text ?? ''), ['gettable', 'settable', 'getconnectionname', 'setconnection', '__construct'], true)) {
                $ambiguous = true;
            }
            if ($depth === 1 && $token->id === T_VARIABLE && $token->text === '$connection') {
                $ambiguous = true;
            }
            if ($depth === 1 && $token->id === T_VARIABLE && $token->text === '$table') {
                $explicit = true;
                $line = $token->line;
                $value = $tokens[$index + 2] ?? null;
                if (($tokens[$index + 1]->text ?? '') === '=' && $value?->id === T_CONSTANT_ENCAPSED_STRING && ($tokens[$index + 3]->text ?? '') === ';') {
                    $table = substr($value->text, 1, -1);
                    // No evaluation of escapes, concatenation, constants or custom SQL identifiers.
                    if (preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/D', $table) !== 1) {
                        $ambiguous = true;
                    }
                } else {
                    $ambiguous = true;
                }
            }
            $next = $tokens[$index + 1] ?? null;
            if (in_array($token->id, [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true) && ($next?->id === T_VARIABLE || in_array(strtolower($next->text ?? ''), ['table', 'settable', 'gettable', 'connection', 'setconnection', '{'], true))) {
                $ambiguous = true;
            }
        }
        if ($ambiguous) {
            return new ModelTable($member, $line, null, null, $explicit);
        }
        $old = $table ?? Str::snake(Str::pluralStudly(class_basename($member->old->fqcn() ?? '')));
        $new = $table ?? Str::snake(Str::pluralStudly(class_basename($member->new->fqcn() ?? '')));

        return new ModelTable($member, $line, $old, $new, $explicit);
    }
}
