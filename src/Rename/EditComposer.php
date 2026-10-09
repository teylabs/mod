<?php

namespace Tey\Mod\Rename;

use Tey\Mod\Plans\Plan;

/** @internal Disjoint edits compose against original bytes; overlaps always block. */
final class EditComposer
{
    /** @param list<Contribution> $contributions */
    public function compose(Plan $plan, Inputs $inputs, array $contributions): Result
    {
        $edits = [];
        $generated = [];
        $bodies = [];
        $checklist = [];
        $guard = new PathGuard($inputs->basePath);
        $dependencies = $inputs->dependencies;
        foreach ($contributions as $contribution) {
            foreach ($contribution->dependencies as $path => $hash) {
                if (! is_file($path) || hash_file('sha256', $path) !== $hash || (isset($dependencies[$path]) && $dependencies[$path] !== $hash)) {
                    $plan->warning('mod:rename read dependency changed during analysis. Preview again. Nothing was written.', file: $path);
                } else {
                    $dependencies[$path] = $hash;
                }
            }
            foreach ($contribution->blockers as $blocker) {
                $plan->warning($blocker);
            }
            foreach ($contribution->edits as $edit) {
                $edits[$edit->file][] = $edit;
            }
            array_push($checklist, ...$contribution->checklist);
            foreach ($contribution->files as $file) {
                $problem = $guard->problem($file->path);
                if ($problem !== null) {
                    $plan->warning($problem);
                } elseif (isset($generated[$file->path]) || file_exists($inputs->basePath.'/'.$file->path) || in_array($file->path, array_column($plan->rename['moves'], 'to'), true)) {
                    $plan->warning("mod:rename destination {$file->path} already exists or is duplicated. Choose another target. Nothing was written.");
                } else {
                    $generated[$file->path] = $file;
                    $plan->file($file->alias, $file->type, $file->path, $file->identity);
                }
            }
        }
        ksort($edits);
        foreach ($edits as $path => $items) {
            $source = $inputs->files[$path] ?? null;
            if ($source === null || $source->historicalMigration || $guard->problem($path) !== null) {
                $plan->warning("mod:rename contributor tried to edit excluded or missing input {$path}. Correct the contributor. Nothing was written.");

                continue;
            }
            usort($items, static fn (Edit $a, Edit $b): int => [$a->offset, strlen($a->before), $a->category] <=> [$b->offset, strlen($b->before), $b->category]);
            $end = -1;
            $previous = -1;
            $valid = true;
            foreach ($items as $edit) {
                $line = substr_count(substr($source->bytes, 0, max(0, $edit->offset)), "\n") + 1;
                if ($edit->offset < 0 || $edit->offset < $end || $edit->offset === $previous || $edit->offset + strlen($edit->before) > strlen($source->bytes) || substr($source->bytes, $edit->offset, strlen($edit->before)) !== $edit->before || $edit->line !== $line) {
                    $plan->warning("mod:rename conflicting or stale byte edits in {$path}. Correct the contributors and preview again. Nothing was written.");
                    $valid = false;
                }
                $previous = $edit->offset;
                $end = $edit->offset + strlen($edit->before);
            }
            if (! $valid) {
                continue;
            }
            $body = $source->bytes;
            foreach (array_reverse($items) as $edit) {
                $body = substr_replace($body, $edit->after, $edit->offset, strlen($edit->before));
            }
            $bodies[$path] = $body;
            foreach ($items as $edit) {
                $plan->rename['rewrites'][] = ['file' => $path, 'after_file' => $inputs->afterPath($path), 'line' => $edit->line, 'category' => $edit->category, 'before' => $edit->before, 'after' => $edit->after];
            }
        }
        usort($checklist, static fn (Checklist $a, Checklist $b): int => [$a->file, $a->line, $a->category, $a->message] <=> [$b->file, $b->line, $b->category, $b->message]);
        foreach ($checklist as $item) {
            $plan->rename['checklist'][] = ['file' => $item->file, 'after_file' => $inputs->afterPath($item->file), 'line' => $item->line, 'category' => $item->category, 'message' => $item->message, 'suggestion' => $item->suggestion];
        }
        foreach ($inputs->members as $member) {
            $source = $inputs->files[$member->old->path()] ?? null;
            if ($source !== null && ! $source->historicalMigration) {
                $bodies[$source->path] ??= $source->bytes;
            }
        }
        usort($plan->rename['rewrites'], static fn (array $a, array $b): int => [$a['file'], $a['line'], $a['category'], $a['before'], $a['after']] <=> [$b['file'], $b['line'], $b['category'], $b['before'], $b['after']]);
        ksort($generated);

        ksort($dependencies);
        $inputs = new Inputs($inputs->basePath, $inputs->request, $inputs->layout, $inputs->members, $inputs->files, $inputs->roots, $dependencies, $inputs->membership, $inputs->git, $inputs->definitionHash);

        return new Result($plan, $inputs, $bodies, array_values($generated));
    }
}
