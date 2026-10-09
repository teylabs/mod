<?php

namespace Tey\Mod\Rename\Tables;

use Illuminate\Support\Str;
use ParseError;
use PhpToken;
use Tey\Mod\Exceptions\ModException;
use Tey\Mod\Rename\Checklist;
use Tey\Mod\Rename\ClusterMember;
use Tey\Mod\Rename\Contribution;
use Tey\Mod\Rename\Inputs;

/** @internal Report database names; selected generation returns candidates only. */
final readonly class Contributor implements \Tey\Mod\Rename\Contributor
{
    public function __construct(private Inspector $inspector, private Candidate $candidate) {}

    public function contribute(Inputs $inputs): Contribution
    {
        $checklist = [];
        $files = [];
        $blockers = [];
        $tables = $this->inspector->inspect($inputs);
        foreach ($tables as $table) {
            $member = $table->member;
            $path = $member->old->path();
            if ($table->old === null || $table->new === null) {
                $checklist[] = new Checklist($path, $table->line, 'table-ambiguity', 'Custom table or connection logic, or an unresolved parent/trait, prevents safe source-only inference.', 'Review table naming without executing the model.');
                if ($inputs->request->tableMigration) {
                    $blockers[] = 'mod:rename --table-migration requires unambiguous table names. Remove custom table/connection logic or unresolved inheritance/traits, or decline the migration and create it separately. Nothing was written.';
                }
            } elseif ($table->explicit) {
                $checklist[] = new Checklist($path, $table->line, 'database-name', "'{$table->old}' is unchanged.", 'The explicit $table keeps both models on the same table.');
            } elseif ($table->changes()) {
                $oldName = class_basename($member->old->fqcn() ?? '');
                $newName = class_basename($member->new->fqcn() ?? '');
                $checklist[] = new Checklist($path, $table->line, 'inferred-table', "{$oldName} uses {$table->old}; {$newName} would use {$table->new}. Decide whether to pin \$table or migrate before using the renamed model.", 'Use --table-migration for a reversible migration; it is never executed.');
            }
            if ($member->old->fqcn() !== $member->new->fqcn()) {
                $checklist[] = new Checklist($path, $table->line, 'model-compatibility', 'Review inferred relation foreign keys, implicit route bindings and serialized model identities.', 'Computed runtime values require application review.');
            }
            if ($inputs->request->tableMigration && $table->changes()) {
                try {
                    $files[] = $this->candidate->build($table, $inputs->basePath);
                } catch (ModException $exception) {
                    $blockers[] = $exception->getMessage();
                }
            }
        }
        if ($inputs->request->tableMigration && $tables !== [] && $files === [] && $blockers === []) {
            $blockers[] = 'mod:rename --table-migration has no changed table names: the resolved old and new table names are unchanged. Omit --table-migration. Nothing was written.';
        }
        $names = [];
        $classes = array_values(array_filter(array_map(static fn (ClusterMember $member): ?string => $member->old->fqcn(), $inputs->members)));

        $foreignKeys = [];
        foreach ($tables as $table) {
            if ($table->old !== null) {
                $names[] = $table->old;
            }
            $foreignKeys[] = Str::snake(class_basename($table->member->old->fqcn() ?? '')).'_id';
        }
        foreach ($inputs->files as $file) {
            if (! str_ends_with($file->path, '.php')) {
                continue;
            }
            try {
                $tokens = array_values(array_filter(PhpToken::tokenize($file->bytes, TOKEN_PARSE), static fn (PhpToken $token): bool => ! $token->isIgnorable()));
            } catch (ParseError) {
                continue;
            }
            $historical = false;
            foreach ($tokens as $index => $token) {
                $literal = $token->id === T_CONSTANT_ENCAPSED_STRING ? substr($token->text, 1, -1) : null;
                if ($file->historicalMigration && (in_array(ltrim($token->text, '\\'), $classes, true) || ($literal !== null && in_array($literal, $names, true)))) {
                    if (! $historical) {
                        $checklist[] = new Checklist($file->path, $token->line, 'migration', 'Existing migration is unchanged.', 'Review historical database names and old class references; do not rewrite history.');
                        $historical = true;
                    }
                }
                $tableProperty = ($tokens[$index - 1]->text ?? '') === '=' && ($tokens[$index - 2]->text ?? '') === '$table';
                if ($literal !== null && in_array($literal, $names, true) && ! $tableProperty) {
                    $checklist[] = new Checklist($file->path, $token->line, 'database-name', "'{$literal}' is unchanged.", 'Review the database name for runtime compatibility.');
                }
                if ($literal !== null && in_array($literal, $foreignKeys, true)) {
                    $checklist[] = new Checklist($file->path, $token->line, 'foreign-key', "'{$literal}' is unchanged.", 'Review relation foreign keys before using the renamed model.');
                }
            }
        }

        return new Contribution(checklist: $checklist, files: $files, blockers: $blockers);
    }
}
