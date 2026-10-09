<?php

namespace Tey\Mod\Rename;

use Illuminate\Foundation\Application;
use Tey\Mod\Discovery\PresetFingerprint;
use Tey\Mod\Exceptions\GenerationRefused;
use Tey\Mod\Exceptions\ModException;
use Tey\Mod\Generation\GroupFolders;
use Tey\Mod\Layout\CompiledLayout;
use Tey\Mod\Layout\LayoutRegistry;
use Tey\Mod\Placement\PlacementContext;
use Tey\Mod\Plans\Plan;
use Tey\Mod\Scaffolds\ScaffoldRegistry;
use Tey\Mod\Support\Path;
use Tey\Mod\Support\Stack;
use Tey\Mod\Templates\TemplateCatalog;

/** @internal Sole read-only cluster planner. It never executes application classes. */
final readonly class Planner
{
    public function __construct(private Application $app, private Contributors $contributors) {}

    public function build(Request $request): Result
    {
        [$oldGroup, $oldName] = $this->identity($request->old);
        [$newGroup, $newName] = $this->identity($request->new);
        $plan = new Plan('mod:rename', $oldGroup, $oldName);
        $plan->rename = ['selection' => ['scaffold' => $request->scaffold, 'source' => null, 'answers' => $request->answers], 'target' => ['group' => $newGroup, 'name' => $newName], 'moves' => [], 'rewrites' => [], 'retained' => [], 'checklist' => [], 'scan_roots' => []];
        if ($request->recover) {
            $plan->warning('mod:rename recovery inspector is unavailable. Install the recovery implementation before retrying. Nothing was written.');

            return new Result($plan);
        }
        $basePath = Path::normalize($this->app->basePath());
        $git = (new GitProbe)->inspect($basePath);
        if ($git->root === null) {
            $plan->warning('mod:rename requires a Git repository. Initialise and commit the project before retrying. Nothing was written.');
        } elseif (! $git->clean()) {
            $plan->warning('mod:rename requires a clean Git working tree and index. Commit or stash your changes, including untracked files, then retry. Nothing was written.');
        }
        try {
            $active = $this->app->make(CompiledLayout::class);
            $snapshot = new Snapshot;
            $roots = $snapshot->roots($active, $basePath);
            $plan->rename['scan_roots'] = $roots;
            if ($request->scaffold === null || $request->scaffold === '') {
                $plan->warning('mod:rename requires --scaffold. Run mod:list --json to find recipes, then retry with --scaffold=crud. Nothing was written.');

                return new Result($plan);
            }
            if ($oldName === null || $newName === null) {
                $plan->warning('mod:rename requires old and new identities. Pass Group:Old Group:New with --scaffold=<recipe>. Nothing was written.');

                return new Result($plan);
            }
            if ($active->dimensionNames() !== [] && ($oldGroup === null || $newGroup === null)) {
                $plan->warning('mod:rename requires qualified group identities. Pass Group:Old Group:New. Nothing was written.');

                return new Result($plan);
            }
            $groups = (new GroupFolders($basePath))->groups($active);
            if ($newGroup !== null && ! in_array($newGroup, $groups, true)) {
                $plan->warning("mod:rename target group {$newGroup} is not configured. Create it first, then retry. Nothing was written.");
            }
            $layoutName = $this->app->make('config')->get('mod.layout', 'laravel');
            $layoutName = is_string($layoutName) ? $layoutName : 'laravel';
            $layouts = $this->app->make(LayoutRegistry::class);
            $overrides = $layouts->has($layoutName) ? $layouts->layout($layoutName)->scaffoldRecipes() : [];
            $registry = $this->app->make(ScaffoldRegistry::class)->forGroup($oldGroup ?? '');
            $recipes = $registry->resolve($active, $layoutName, $overrides);
            $recipe = $recipes[$request->scaffold] ?? null;
            if ($recipe === null) {
                $plan->warning($registry->problems()[$request->scaffold] ?? "mod:rename scaffold {$request->scaffold} is unavailable. Choose an effective source recipe from mod:list --json. Nothing was written.");

                return new Result($plan);
            }
            $plan->rename['selection']['source'] = $registry->sources()[$request->scaffold] ?? 'app';
            $sourceLayout = $this->layoutFor($active, $layoutName, $oldGroup);
            $targetLayout = $this->layoutFor($active, $layoutName, $newGroup);
            $evaluator = new RecipeEvaluator($basePath, $registry, $request->scaffold, fn (?string $group): CompiledLayout => $this->layoutFor($active, $layoutName, $group));
            $old = $evaluator->evaluate($recipe, $sourceLayout, $oldName, PlacementContext::fromOption($oldGroup ?? '', $sourceLayout), $request->answers);
            $new = $evaluator->evaluate($recipe, $targetLayout, $newName, PlacementContext::fromOption($newGroup ?? '', $targetLayout), $request->answers);
            if ($old === []) {
                $plan->warning('mod:rename recipe has no resolved members. Choose a recipe matching the cluster. Nothing was written.');
            }
            $roots = $snapshot->roots($sourceLayout, $basePath);
            foreach ($old as $entry) {
                $roots = array_values(array_unique([...$roots, ...$snapshot->roots($entry['layout'], $basePath)]));
            }
            sort($roots);
            $roots = array_values(array_filter($roots, static fn (string $root): bool => array_filter($roots, static fn (string $parent): bool => $root !== $parent && Path::relative($parent, $root) !== null) === []));
            $membership = $snapshot->membership($basePath, $roots, $git);
            $files = $snapshot->files($basePath, $membership);
            $members = [];
            $destinations = [];
            $sources = [];
            $guard = new PathGuard($basePath);
            foreach ($old as $alias => $entry) {
                $before = $entry['artifact'];
                $after = $new[$alias]['artifact'] ?? throw GenerationRefused::because('mod:rename target recipe membership drifted. Review the effective recipe. Nothing was written.');
                $definition = $entry['member'];
                $type = $before->fileType->id;
                $oldTemplate = $entry['layout']->templates()[$type] ?? null;
                $newTemplate = $new[$alias]['layout']->templates()[$type] ?? null;
                if ($before->fileType->isClass() !== $after->fileType->isClass() || $before->fileType->extension !== $after->fileType->extension || ($oldTemplate['file'] ?? null) !== ($newTemplate['file'] ?? null) || ($oldTemplate['digest'] ?? null) !== ($newTemplate['digest'] ?? null)) {
                    $plan->warning("mod:rename template source or file type changes for member {$alias}. Review the target templates before retrying. Nothing was written.");
                }
                $from = $before->path();
                $to = $after->path();
                if ($before->fileType->isTimestamped()) {
                    $suffix = preg_replace('/^\d{4}_\d{2}_\d{2}_\d{6}_/', '', basename($from));
                    $matches = array_values(array_filter($membership, static fn (string $path): bool => dirname($path) === dirname($from) && str_ends_with(basename($path), '_'.$suffix)));
                    if (count($matches) !== 1) {
                        $plan->warning("mod:rename historical migration member {$alias} is missing or ambiguous. Account for its migration identity explicitly. Nothing was written.");
                    } else {
                        $plan->rename['retained'][] = ['alias' => $alias, 'path' => $matches[0], 'reason' => 'historical migration'];
                    }

                    continue;
                }
                foreach ([$from, $to] as $path) {
                    $problem = $guard->problem($path);
                    if ($problem !== null) {
                        $plan->warning($problem);
                    }
                }
                if (is_file(Path::resolve($basePath, $from)) && ! isset($files[$from]) && $guard->problem($from) === null) {
                    $plan->warning("mod:rename source {$from} is ignored or outside the owned scan roots. Track it within a project-owned root before retrying. Nothing was written.");
                }
                if (! is_file(Path::resolve($basePath, $from))) {
                    $plan->warning("mod:rename source {$from} is missing. Restore it or choose a recipe matching the cluster. Nothing was written.");
                }
                if (isset($sources[strtolower($from)])) {
                    $plan->warning("mod:rename duplicate source {$from}. Give every member one ownership identity. Nothing was written.");
                }
                $sources[strtolower($from)] = true;
                $member = new ClusterMember($alias, $definition, $before, $after, $entry['identity'], $new[$alias]['identity'], $entry['layout'], $new[$alias]['layout']);
                $members[] = $member;
                $stable = $from === $to && $before->fqcn() === $after->fqcn() && $entry['identity'] === $new[$alias]['identity'];
                if ($definition->existing === 'keep') {
                    if (! $stable) {
                        $oldLabel = class_basename($before->fqcn() ?? $before->name);
                        $newLabel = class_basename($after->fqcn() ?? $after->name);
                        $plan->warning("mod:rename cannot move kept member {$alias} from {$oldLabel} to {$newLabel}. Give this shared member a stable name or choose a recipe that excludes it. Nothing was written.");
                    } else {
                        $plan->rename['retained'][] = ['alias' => $alias, 'path' => $from, 'reason' => 'stable kept member'];
                    }

                    continue;
                }
                if ($stable) {
                    $plan->rename['retained'][] = ['alias' => $alias, 'path' => $from, 'reason' => 'stable shared member'];

                    continue;
                }
                if (strtolower($from) === strtolower($to)) {
                    $plan->warning('mod:rename cannot safely perform this case-only path move on this filesystem. Choose a temporary distinct name first. Nothing was written.');
                }
                if (file_exists(Path::resolve($basePath, $to))) {
                    $plan->warning("mod:rename destination {$to} already exists. Choose another target. Nothing was written.");
                }
                if (isset($destinations[strtolower($to)])) {
                    $plan->warning("mod:rename duplicate destination {$to}. Correct the recipe. Nothing was written.");
                }
                $destinations[strtolower($to)] = true;
                $plan->rename['moves'][] = ['alias' => $alias, 'type' => $type, 'from' => $from, 'to' => $to, 'old_class' => $before->fqcn(), 'new_class' => $after->fqcn()];
            }
            if ($members !== [] && array_filter($members, static fn (ClusterMember $member): bool => strcasecmp($member->old->path(), $member->new->path()) !== 0 || strcasecmp($member->old->fqcn() ?? '', $member->new->fqcn() ?? '') !== 0) === []) {
                $plan->warning('mod:rename source and target resolve to the same identity. Choose a different name. Nothing was written.');
            }
            foreach ($membership as $path) {
                if (($problem = $guard->problem($path)) !== null) {
                    $plan->warning($problem);
                }
                if (Snapshot::migration($path) || isset($sources[strtolower($path)])) {
                    continue;
                }
                // Conservative candidate boundary: same resolved member directory or
                // cluster page directory, with the exact cluster name as a token.
                foreach ($members as $member) {
                    $parent = dirname($member->old->path());
                    $inside = dirname($path) === $parent || Path::relative($parent.'/'.$oldName, $path) !== null;
                    if ($inside && preg_match('/'.preg_quote($oldName, '/').'(?![a-z0-9])/', $path) === 1) {
                        $plan->warning("mod:rename unaccounted candidate {$path}. Correct the recipe or pass every existing part before retrying. Nothing was written.", file: $path);
                        break;
                    }
                }
            }
            usort($plan->rename['moves'], static fn (array $a, array $b): int => [$a['from'], $a['alias']] <=> [$b['from'], $b['alias']]);
            usort($plan->rename['retained'], static fn (array $a, array $b): int => [$a['path'], $a['alias']] <=> [$b['path'], $b['alias']]);
            $dependencies = [];
            foreach ([__DIR__.'/../../resources/rename/excluded-paths.json', 'composer.json', 'composer.lock', 'package.json', 'package-lock.json', 'pnpm-lock.yaml', 'yarn.lock', ...array_column($sourceLayout->templates(), 'file'), ...array_column($targetLayout->templates(), 'file'), ...array_values($registry->origins())] as $path) {
                $absolute = Path::resolve($basePath, $path);
                if (is_file($absolute)) {
                    $dependencies[$absolute] = (string) hash_file('sha256', $absolute);
                }
            }
            foreach ($members as $member) {
                foreach ([...array_column($member->oldLayout->templates(), 'file'), ...array_column($member->newLayout->templates(), 'file')] as $path) {
                    if (is_file($path)) {
                        $dependencies[$path] = (string) hash_file('sha256', $path);
                    }
                }
            }
            // Provider/config sources are read dependencies, even though templates
            // and historical migrations are excluded from rewrite operations.
            foreach ($git->paths as $path) {
                if ($snapshot->excluded($path) && ! (new ExcludedPaths)->contains($path, dependenciesOnly: true)) {
                    $absolute = Path::resolve($basePath, $path);
                    if (is_file($absolute) && ! is_link($absolute)) {
                        $dependencies[$absolute] = (string) hash_file('sha256', $absolute);
                    }
                }
            }
            ksort($dependencies);
            $definitionHash = hash('sha256', serialize([PresetFingerprint::of($sourceLayout), PresetFingerprint::of($targetLayout), $sourceLayout->frontend(), $targetLayout->frontend(), $registry->nodes(), $request, array_map(static fn (ClusterMember $member): array => [$member->alias, $member->oldIdentity, $member->newIdentity, PresetFingerprint::of($member->oldLayout), PresetFingerprint::of($member->newLayout)], $members)]));
            $inputs = new Inputs($basePath, $request, $sourceLayout, $members, $files, $roots, $dependencies, $membership, $git, $definitionHash);
            $plan->rename['scan_roots'] = $roots;
            $contributions = $this->contributors->collect($inputs);
            if ($request->tableMigration && array_filter($contributions, static fn (Contribution $contribution): bool => $contribution->files !== []) === []) {
                $plan->warning('mod:rename --table-migration has no exact migration candidate. Configure the table contributor and resolve unambiguous table names. Nothing was written.');
            }

            return (new EditComposer)->compose($plan, $inputs, $contributions);
        } catch (ModException $exception) {
            $message = $exception->getMessage();
            $plan->warning(str_starts_with($message, 'mod:rename') ? $message : 'mod:rename cannot resolve the selected recipe: '.$message.' Review the recipe and configured layout. Nothing was written.');

            return new Result($plan);
        }
    }

    /** @return array{?string, ?string} */
    private function identity(?string $value): array
    {
        if ($value === null || trim($value) === '') {
            return [null, null];
        }
        $parts = explode(':', $value, 2);

        return count($parts) === 2 ? [$parts[0], $parts[1]] : [null, $parts[0]];
    }

    private function layoutFor(CompiledLayout $active, string $name, ?string $group): CompiledLayout
    {
        $catalog = $this->app->make(TemplateCatalog::class);
        if (! $catalog->hasGroupTemplates() || is_array($this->app->make('config')->get('mod.preset'))) {
            return $active;
        }

        return $this->app->make(LayoutRegistry::class)->compile($name, $catalog->forGroup($group ?? ''))->withStack($this->app->make(Stack::class));
    }
}
