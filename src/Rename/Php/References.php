<?php

namespace Tey\Mod\Rename\Php;

use ParseError;
use PhpToken;
use Tey\Mod\Rename\Checklist;
use Tey\Mod\Rename\Contribution;
use Tey\Mod\Rename\Contributor;
use Tey\Mod\Rename\Edit;
use Tey\Mod\Rename\Inputs;

/** @internal Pure PHP binding and original-byte candidates; never loads app classes. */
final class References implements Contributor
{
    public function contribute(Inputs $inputs): Contribution
    {
        $declarations = (new Declarations)->contribute($inputs);
        $edits = $declarations->edits;
        $blockers = $declarations->blockers;
        $checklist = [];
        $classes = [];
        $identities = [];
        $sources = [];
        $targets = [];
        foreach ($inputs->members as $member) {
            $old = $member->old->fqcn();
            $new = $member->new->fqcn();
            if ($old !== null && $new !== null && $old !== $new) {
                $classes[strtolower($old)] = $new;
                $sources[strtolower($old)] = $member->old->path();
                $targets[strtolower($new)] = true;
            }
            if (isset($member->oldIdentity['name'], $member->newIdentity['name'])) {
                $kind = str_ends_with($member->old->path(), '.blade.php') ? 'view' : 'inertia';
                $identities[$kind][$member->oldIdentity['name']] = $member->newIdentity['name'];
            }
        }
        $parsed = [];
        $shadows = [];
        foreach ($inputs->files as $file) {
            if (! str_ends_with($file->path, '.php')) {
                continue;
            }
            try {
                $tokens = str_ends_with($file->path, '.blade.php') ? (new Blade)->tokens($file->bytes) : PhpToken::tokenize($file->bytes, TOKEN_PARSE);
            } catch (ParseError) {
                if (! $file->historicalMigration) {
                    $blockers[] = "mod:rename cannot parse {$file->path}. Fix its PHP syntax before retrying. Nothing was written.";
                } else {
                    $checklist[] = new Checklist($file->path, 1, 'historical-migration', 'Historical PHP cannot be parsed; review its class references manually.');
                }

                continue;
            }

            $parsed[$file->path] = $tokens;
            $binding = new Symbols($tokens, []);
            $binding->analyse();
            $shadows += $binding->declaredFunctions();
            if (! $file->historicalMigration) {
                foreach ($binding->declaredTypes() as $type => $line) {
                    if (isset($targets[$type]) || (isset($sources[$type]) && $sources[$type] !== $file->path)) {
                        $blockers[] = "mod:rename declaration collision for {$type} in {$file->path}:{$line}. Choose a non-conflicting target or correct duplicate declarations. Nothing was written.";
                    }
                }
            }
        }
        foreach ($parsed as $path => $tokens) {
            $file = $inputs->files[$path];
            $namespaceChanges = [];
            foreach ($inputs->members as $member) {
                if ($member->old->path() === $file->path && $member->old->fqcn() !== null) {
                    $namespaceChanges[$member->old->namespace() ?? ''] = $member->new->namespace() ?? '';
                }
            }
            $analysis = new Symbols($tokens, $classes, $namespaceChanges, $shadows);
            $output = $analysis->analyse();
            foreach ($output->edits as $candidate) {
                if ($file->historicalMigration) {
                    $checklist[] = new Checklist($file->path, $candidate->line, 'historical-migration', "{$candidate->before} is unchanged in historical migration.", $candidate->after);
                } else {
                    // Declaration validation owns exactly these bytes.
                    $owned = false;
                    foreach ($declarations->edits as $edit) {
                        $owned = $owned || ($edit->file === $file->path && $edit->offset === $candidate->offset);
                    }
                    if (! $owned) {
                        $edits[] = new Edit($file->path, $candidate->offset, $candidate->before, $candidate->after, $candidate->category, $candidate->line);
                    }
                }
            }
            foreach ($output->blockers as $blocker) {
                if (! $file->historicalMigration) {
                    $blockers[] = "mod:rename {$file->path}: {$blocker} Nothing was written.";
                }
            }
            foreach ($output->checklist as $item) {
                $checklist[] = new Checklist($file->path, $item->line, $item->category, $item->message, $item->suggestion);
            }
            $literals = (new Literals)->analyse($file, $inputs, $analysis, $identities);
            array_push($edits, ...$literals->edits);
            array_push($checklist, ...$literals->checklist);
        }
        // Parse complete prospective PHP after this contributor's own disjoint edits.
        foreach ($inputs->files as $file) {
            $items = array_values(array_filter($edits, static fn (Edit $edit): bool => $edit->file === $file->path));
            if ($items === []) {
                continue;
            }
            usort($items, static fn (Edit $a, Edit $b): int => $b->offset <=> $a->offset);
            $body = $file->bytes;
            foreach ($items as $edit) {
                $body = substr_replace($body, $edit->after, $edit->offset, strlen($edit->before));
            }
            try {
                if (str_ends_with($file->path, '.blade.php')) {
                    (new Blade)->tokens($body);
                } else {
                    PhpToken::tokenize($body, TOKEN_PARSE);
                }
            } catch (ParseError) {
                $blockers[] = "mod:rename prospective PHP is invalid in {$file->path}. Resolve its symbol/import collision before retrying. Nothing was written.";
            }
        }
        $unique = [];
        foreach ($checklist as $item) {
            $unique[serialize([$item->file, $item->line, $item->category, $item->message, $item->suggestion])] = $item;
        }

        return new Contribution($edits, array_values($unique), blockers: array_values(array_unique($blockers)));
    }
}
