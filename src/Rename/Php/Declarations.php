<?php

namespace Tey\Mod\Rename\Php;

use ParseError;
use PhpToken;
use Tey\Mod\Rename\Contribution;
use Tey\Mod\Rename\Contributor;
use Tey\Mod\Rename\Edit;
use Tey\Mod\Rename\Inputs;

/** @internal Recipe-owned declaration validation and original byte edits. */
final class Declarations implements Contributor
{
    public function contribute(Inputs $inputs): Contribution
    {
        $edits = [];
        $blockers = [];
        foreach ($inputs->members as $member) {
            $file = $inputs->files[$member->old->path()] ?? null;
            if ($file === null || $file->historicalMigration || ! $member->old->fileType->isClass()) {
                continue;
            }
            try {
                $tokens = PhpToken::tokenize($file->bytes, TOKEN_PARSE);
            } catch (ParseError) {
                $blockers[] = "mod:rename cannot parse {$file->path}. Fix its PHP syntax before retrying. Nothing was written.";

                continue;
            }
            $namespace = '';
            $namespaceToken = null;
            $names = [];
            foreach ($tokens as $index => $token) {
                if ($token->id === T_NAMESPACE) {
                    $namespace = '';
                    $namespaceToken = null;
                    $next = $index + 1;
                    while (isset($tokens[$next]) && $tokens[$next]->isIgnorable()) {
                        $next++;
                    }
                    if (isset($tokens[$next]) && in_array($tokens[$next]->id, [T_STRING, T_NAME_QUALIFIED], true)) {
                        $namespaceToken = $tokens[$next];
                        $namespace = $namespaceToken->text;
                    }
                }
                if (in_array($token->id, [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)) {
                    $next = $index + 1;
                    while (isset($tokens[$next]) && $tokens[$next]->isIgnorable()) {
                        $next++;
                    }
                    if (isset($tokens[$next]) && $tokens[$next]->id === T_STRING) {
                        $names[] = [$tokens[$next], $namespace, $namespaceToken];
                    }
                }
            }
            $expected = $member->old->fqcn();
            if (count($names) !== 1 || ltrim($names[0][1].'\\'.$names[0][0]->text, '\\') !== $expected) {
                $blockers[] = "mod:rename expected {$expected} in {$file->path}. Restore the declaration or use the correct recipe. Nothing was written.";

                continue;
            }
            $namespace = $names[0][1];
            $namespaceToken = $names[0][2];
            $newNamespace = $member->new->namespace() ?? '';
            if ($namespace !== $newNamespace) {
                if ($namespaceToken === null || $newNamespace === '') {
                    $blockers[] = "mod:rename cannot safely change namespace structure in {$file->path}. Choose a recipe with compatible declarations. Nothing was written.";
                } else {
                    $edits[] = new Edit($file->path, $namespaceToken->pos, $namespaceToken->text, $newNamespace, 'php-declaration', $namespaceToken->line);
                }
            }
            $name = $names[0][0];
            $newName = class_basename($member->new->fqcn() ?? '');
            if ($name->text !== $newName) {
                $edits[] = new Edit($file->path, $name->pos, $name->text, $newName, 'php-declaration', $name->line);
            }
        }

        return new Contribution($edits, blockers: $blockers);
    }
}
