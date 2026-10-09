<?php

namespace Tey\Mod\Commands\Concerns;

use Illuminate\Container\Container;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Tey\Mod\Artifact\ResolvedArtifact;
use Tey\Mod\Exceptions\GenerationRefused;
use Tey\Mod\Exceptions\ModException;
use Tey\Mod\Generation\StubRegistry;
use Tey\Mod\Placement\PlacementContext;
use Tey\Mod\Placement\TemplateRule;
use Tey\Mod\Scaffolds\AnchorWriter;
use Tey\Mod\Scaffolds\Part;
use Tey\Mod\Scaffolds\Placeholders;
use Tey\Mod\Scaffolds\QuestionAnswers;
use Tey\Mod\Scaffolds\Scaffold;
use Tey\Mod\Scaffolds\ScaffoldExecution;
use Tey\Mod\Scaffolds\ScaffoldPlan;
use Tey\Mod\Scaffolds\ScaffoldRegistry;
use Tey\Mod\Support\Path;
use Throwable;

use function Laravel\Prompts\confirm;

/** @internal Creation and growing share the same subtree planner. */
trait RunsScaffoldTree
{
    /** @var list<array{0: string, 1: array<string, mixed>}> */
    private array $treeCalls = [];

    /** @var list<array{path: string, at: string, stub: string, label: string}> */
    private array $treeInserts = [];

    /** @var array<string, string> routes files/anchors accepted by a prompt, staged until commit */
    private array $treeStarts = [];

    /** @var array<string, mixed> qualified aliases from all nodes */
    private array $treeAliases = [];

    private bool $treeCancelled = false;

    private function treeOptions(): void
    {
        if ($this->recipe instanceof Part) {
            $this->getDefinition()->addArgument(new InputArgument('value', InputArgument::REQUIRED, 'The part input, or recursive path below the root'));
        }
        $registry = $this->laravelRegistry();
        $recipes = [$this->recipe];
        if ($this->recipe instanceof Part && $this->recipe->scaffold() !== null) {
            $child = $registry->get($this->recipe->scaffold());
            if ($child !== null) {
                $recipes[] = $child;
            }
        }
        if (str_contains($this->recipeName, '.')) {
            $root = $registry->get(explode('.', $this->recipeName)[0]);
            if ($root !== null) {
                $recipes[] = $root;
            }
        }
        $seen = [];
        for ($i = 0; $i < count($recipes); $i++) {
            $recipe = $recipes[$i];
            $id = spl_object_id($recipe);
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            foreach ($recipe->parts() as $part) {
                $recipes[] = $part;
            }
            if ($recipe instanceof Part && $recipe->scaffold() !== null && ($used = $registry->get($recipe->scaffold())) !== null) {
                $recipes[] = $used;
            }
        }
        foreach ($recipes as $recipe) {
            foreach ($recipe->members() as $member) {
                if (! $this->preset->hasKind($member->fileType)) {
                    continue;
                }
                foreach ($this->preset->rule($member->fileType)->dimensions() as $slot) {
                    if (! in_array($slot, $this->preset->dimensionNames(), true) && ! $this->getDefinition()->hasOption($slot)) {
                        $this->getDefinition()->addOption(new InputOption($slot, null, InputOption::VALUE_REQUIRED, 'The '.$slot.' folder and template value'));
                    }
                }
            }
            foreach ($recipe->questions() as $question) {
                if (! $this->getDefinition()->hasOption($question->name)) {
                    $mode = match ($question->type) {
                        'list' => InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                        'confirm' => InputOption::VALUE_NEGATABLE,
                        default => InputOption::VALUE_REQUIRED,
                    };
                    $this->getDefinition()->addOption(new InputOption($question->name, null, $mode, $question->label ?? $question->name));
                }
            }
        }
    }

    private function laravelRegistry(): ScaffoldRegistry
    {
        return Container::getInstance()->make(ScaffoldRegistry::class);
    }

    private function handleTree(): int
    {
        $this->treeCalls = $this->treeInserts = $this->treeStarts = $this->treeAliases = [];
        $this->treeCancelled = false;
        $scope = new ScaffoldExecution;
        $scope->nestedNames = true;
        $previous = $this->scaffoldExecution();
        $this->laravel->instance(ScaffoldExecution::class, $scope);
        $backups = [];
        try {
            $plan = new ScaffoldPlan;
            $name = $this->shorthand()[1];
            $context = $this->placementContext();
            if ($this->recipe instanceof Part) {
                $this->growTree($plan, $scope, $name, $context);
            } else {
                $this->planTreeNode($this->recipe, $name, $context, [], '', '', $plan, $scope, 0);
            }
            if ($this->treeCancelled) {
                return self::SUCCESS;
            }
            $this->describePlan($plan);
            if ($this->dryPlan !== null) {
                foreach ($this->treeInserts as $insert) {
                    $this->dryPlan->inserts[] = ['into' => $insert['path'], 'at' => $insert['at'], 'stub' => $insert['stub']];
                }
            }
            $existing = $plan->existing($this->existingArtifacts());
            if ($existing !== [] && $this->dryPlan === null) {
                throw GenerationRefused::because('Item already exists: '.$existing[0].'. Nothing was written.');
            }
            // Validate every insert before displaying or writing the plan.
            $writer = new AnchorWriter;
            $sources = [];
            foreach ($this->treeInserts as $insert) {
                $path = $insert['path'];
                if (! isset($sources[$path])) {
                    $absolute = $this->laravel->basePath($path);
                    $template = $scope->variants[$path] ?? $scope->defaultStubs[$path] ?? null;
                    $sources[$path] = $this->treeStarts[$path] ?? (is_file($absolute) ? (string) file_get_contents($absolute) : ($template !== null && is_file($template) ? (string) file_get_contents($template) : ''));
                }
                $entry = rtrim(str_replace("\r\n", "\n", $insert['stub']), "\n");
                if ($entry !== '' && str_contains(str_replace("\r\n", "\n", $sources[$path]), $entry)) {
                    throw GenerationRefused::because("Insert already exists in {$path} at mod:{$insert['at']}. Nothing was written.");
                }
                try {
                    $sources[$path] = $writer->insert($sources[$path], $insert['at'], $insert['stub'], $path);
                } catch (GenerationRefused $exception) {
                    // Only routes files may be started; keep existing contents and append the missing marker.
                    if (! str_ends_with($path, '/routes/web.php') || str_contains($exception->getMessage(), 'more than one')) {
                        throw $exception;
                    }
                    $message = "{$path} has no mod:{$insert['at']} anchor.";
                    if (! $this->interactive()) {
                        throw GenerationRefused::because($message." Create {$path} with // mod:{$insert['at']}, then run mod:{$this->recipeName} again. Nothing was written.");
                    }
                    if (! confirm($message.' Create it with the anchor?', default: true)) {
                        return self::SUCCESS;
                    }
                    $newline = str_contains($sources[$path], "\r\n") ? "\r\n" : "\n";
                    $start = $sources[$path] === '' ? '<?php'.$newline.$newline.'use Illuminate\Support\Facades\Route;'.$newline : $sources[$path];
                    $this->treeStarts[$path] = rtrim($start, "\r\n").$newline.'// mod:'.$insert['at'].$newline;
                    $sources[$path] = $writer->insert($this->treeStarts[$path], $insert['at'], $insert['stub'], $path);
                }
            }
            if ($this->dryPlan !== null) {
                foreach ($this->treeInserts as $insert) {
                    $anchor = $writer->anchor($sources[$insert['path']], $insert['at'], $insert['path']);
                    $this->dryPlan->insertDetails[] = ['anchor' => trim($anchor['line']), 'label' => $insert['label']];
                }

                return self::SUCCESS;
            }
            $count = count($plan->files());
            $inserts = count($this->treeInserts);
            $suffix = $inserts === 0 ? '' : " and {$inserts} inserts";
            $this->components->info("mod:{$this->recipeName} will write {$count} ".($count === 1 ? 'file' : 'files').$suffix.' for '.$this->rawNameInput().'.');
            foreach ($plan->files() as ['artifact' => $artifact, 'alias' => $alias]) {
                $this->treeLine($artifact->path(), $alias);
            }
            foreach ($this->treeInserts as $insert) {
                $anchor = $writer->anchor($sources[$insert['path']], $insert['at'], $insert['path']);
                $this->treeLine('+ at '.trim($anchor['line']).' in '.basename($insert['path']), $insert['label']);
            }
            $this->newLine();
            $question = $inserts === 0 ? "Write these {$count} files?" : ($count === 1 ? "Write this file and {$inserts} inserts?" : "Write these {$count} files and {$inserts} inserts?");
            if ($this->interactive() && ! confirm($question, default: true)) {
                return self::SUCCESS;
            }
            $paths = array_unique([...array_map(static fn (array $file): string => $file['artifact']->path(), $plan->files()), ...array_column($this->treeInserts, 'path')]);
            foreach ($paths as $path) {
                $absolute = $this->laravel->basePath($path);
                $backups[$path] = is_file($absolute) ? (string) file_get_contents($absolute) : null;
            }
            $scope->planning = false;
            foreach ($this->treeCalls as [$command, $arguments]) {
                if ($this->call($command, $arguments) !== self::SUCCESS) {
                    throw GenerationRefused::because("Generating mod:{$this->recipeName} failed. Nothing was written.");
                }
            }
            $patched = $this->treeStarts;
            foreach ($this->treeInserts as $insert) {
                $path = $insert['path'];
                $source = $patched[$path] ?? (string) file_get_contents($this->laravel->basePath($path));
                $patched[$path] = $writer->insert($source, $insert['at'], $insert['stub'], $path);
            }
            foreach ($patched as $path => $contents) {
                $absolute = $this->laravel->basePath($path);
                $this->laravel->make('files')->ensureDirectoryExists(dirname($absolute));
                if (file_put_contents($absolute, $contents, LOCK_EX) === false) {
                    throw GenerationRefused::because("Could not write {$path}. Nothing was written.");
                }
            }
            foreach ($this->treeInserts as $insert) {
                $this->components->info("Inserted into [{$insert['path']}] at // mod:{$insert['at']}.");
            }

            return self::SUCCESS;
        } catch (Throwable $exception) {
            foreach ($backups as $path => $contents) {
                $absolute = $this->laravel->basePath($path);
                if ($contents === null) {
                    if (is_file($absolute)) {
                        unlink($absolute);
                    }
                } else {
                    file_put_contents($absolute, $contents, LOCK_EX);
                }
            }
            if (! $exception instanceof ModException) {
                throw $exception;
            }
            if ($this->dryPlan !== null) {
                throw $exception;
            }
            foreach (explode("\n", $exception->getMessage()) as $line) {
                $this->components->error($line);
            }

            return self::FAILURE;
        } finally {
            $previous === null ? $this->laravel->forgetInstance(ScaffoldExecution::class) : $this->laravel->instance(ScaffoldExecution::class, $previous);
        }
    }

    private function treeLine(string $path, string $alias): void
    {
        $this->line('  '.$path.' '.str_repeat('.', max(2, 72 - strlen($path) - strlen($alias) - 4)).' '.$alias);
    }

    /** @param array<string, mixed> $given
     * @return array<string, mixed>
     */
    private function treeAnswers(Scaffold $recipe, string $name, array $given, bool $locating = false): array
    {
        $values = [...$given, 'name' => $name];
        $resolver = new QuestionAnswers($this->preset, $this->laravel->basePath());
        $failure = null;
        foreach ($recipe->questions() as $question) {
            if (array_key_exists($question->name, $given)) {
                continue;
            }
            $option = $this->getDefinition()->hasOption($question->name) ? $this->option($question->name) : null;
            try {
                $values[$question->name] = $resolver->answer($question, $option, ! $locating && $this->interactive(), 'mod:'.$this->recipeName, $name);
            } catch (Throwable $exception) {
                if ($this->dryPlan === null || ! $exception instanceof ModException) {
                    throw $exception;
                }
                $this->dryPlan->warning($exception->getMessage());
                $failure = $exception;
            }
        }

        if ($failure !== null) {
            throw $failure;
        }

        return $values;
    }

    /** @param array<string, mixed> $given
     * @return array<string, ResolvedArtifact>
     */
    private function planTreeNode(Scaffold $recipe, string $name, PlacementContext $context, array $given, string $prefix, string $folder, ScaffoldPlan $plan, ScaffoldExecution $scope, int $depth, ?string $nodeKey = null): array
    {
        if ($depth > 10) {
            throw GenerationRefused::because('Scaffold path '.$prefix.' exceeds the depth limit of 10. Nothing was written.');
        }
        $nodeKey ??= $this->recipeName;
        $firstFile = count($plan->files());
        $values = $this->treeAnswers($recipe, $name, $given);
        $own = [];
        foreach ($recipe->members() as $alias => $member) {
            $command = $this->preset->kind($member->fileType)->command ?? throw GenerationRefused::because("File type [{$member->fileType}] has no command.");
            $stem = $member->name === null ? $name : (new Placeholders($values))->name($member->name);
            $arguments = ['name' => $folder.$stem, '--no-interaction' => true];
            $placement = $this->inOption($context);
            if ($placement !== '') {
                $arguments['--in'] = $placement;
            }
            foreach ($this->preset->rule($member->fileType)->dimensions() as $slot) {
                if (! in_array($slot, $this->preset->dimensionNames(), true) && $this->getDefinition()->hasOption($slot)) {
                    $arguments['--'.$slot] = $this->option($slot);
                }
            }
            foreach ($member->options as $option => $value) {
                if (is_int($option) && is_string($value)) {
                    $parts = explode('=', $value, 2);
                    $arguments[$parts[0]] = $parts[1] ?? true;
                } elseif (is_string($option)) {
                    $arguments[$option] = is_string($value) ? (new Placeholders($values))->render($value) : $value;
                }
            }
            $scope->reset();
            if ($this->call($command, $arguments) !== self::SUCCESS || $scope->collected() === null) {
                throw GenerationRefused::because("Planning mod:{$this->recipeName} failed. Nothing was written.");
            }
            $this->configurePrompts($this->input);
            $memberPlan = $scope->collected();
            $artifact = $memberPlan->primary;
            $own[$alias] = $values[$alias] = $artifact;
            $plan->add($prefix.$alias, $memberPlan, $scope);
            $this->treeCalls[] = [$command, $arguments];
            $context = $artifact->context;
            if ($member->stub !== null) {
                $scope->variants[$artifact->path()] = $this->treeStub($member->fileType.'.'.$member->stub);
            }
            $this->treeAliases[$prefix.$alias] = $artifact;
        }
        $lastFile = count($plan->files());
        foreach ($recipe->repetitions() as $question => $partName) {
            $part = $this->treePart($recipe, $nodeKey, $partName);
            if ($part === null || ! is_array($values[$question] ?? null)) {
                throw GenerationRefused::because("each('{$question}') needs a list question and a {$partName} part. Nothing was written.");
            }
            foreach ($values[$question] as $item) {
                if (! is_string($item)) {
                    throw GenerationRefused::because("{$question} needs text items.");
                }
                $outputs = $this->planTreePart($part, $partName, $item, $name, $context, $values, $prefix.$partName.'.'.$item.'.', $folder, $plan, $scope, $depth + 1, $nodeKey.'.'.$partName, array_keys($own));
                foreach ($outputs as $key => $output) {
                    $values[$partName.'.'.$item.'.'.$key] = $output;
                }
            }
        }
        foreach ($own as $artifact) {
            $scope->values[$artifact->path()] = [...$this->treeAliases, ...$values];
        }
        // Related generators and bases need the same node answers.
        foreach ($plan->files() as $index => ['artifact' => $artifact]) {
            if ($index >= $firstFile && $index < $lastFile) {
                $scope->values[$artifact->path()] = [...$this->treeAliases, ...$values];
            }
        }

        return $own;
    }

    private function treePart(Scaffold $recipe, string $nodeKey, string $name): ?Part
    {
        $path = $nodeKey.'.'.$name;
        $override = $this->laravelRegistry()->get($path);

        return $override instanceof Part ? $override : ($recipe->parts()[$name] ?? null);
    }

    private function treeStub(string $id): string
    {
        $file = $this->laravel->basePath('stubs/mod.'.$id.'.stub');
        if (! is_file($file)) {
            $registry = $this->laravel->make(StubRegistry::class);
            $stub = $registry->get($id);
            $file = $stub === null ? '' : $stub->path;
        }
        if ($file === '' || ! is_file($file)) {
            throw GenerationRefused::because("mod:{$this->recipeName} needs stubs/mod.{$id}.stub. Create the stub before running the command. Nothing was written.");
        }

        return $file;
    }

    private function childRecipe(Part $part): Scaffold
    {
        $recipe = new Scaffold;
        if ($part->scaffold() !== null) {
            $recipe = clone ($this->laravelRegistry()->get($part->scaffold()) ?? throw GenerationRefused::because("Scaffold [{$part->scaffold()}] is not defined."));
        }
        $recipe->overlay($part);

        return $recipe;
    }

    /** @param array<string, mixed> $parent
     * @param  list<string>  $owned
     * @return array<string, ResolvedArtifact>
     */
    private function planTreePart(Part $part, string $partName, string $item, string $name, PlacementContext $context, array $parent, string $prefix, string $folder, ScaffoldPlan $plan, ScaffoldExecution $scope, int $depth, ?string $nodeKey = null, array $owned = []): array
    {
        $values = $parent;
        foreach ($part->values() as $key => $value) {
            $values[$key] = is_string($value) ? (new Placeholders($parent))->render($value) : $value;
            // with: forwards fully qualified class identities, rather than their short display form.
            if (is_string($value) && preg_match('/^\{\{\s*([\w.-]+)\.fqcn\s*\}\}$/', $value, $match)) {
                $artifact = $parent[$match[1]] ?? null;
                if ($artifact instanceof ResolvedArtifact) {
                    $values[$key] = $artifact->fqcn();
                }
            }
        }
        $values[$partName] = $item;
        $recipe = $this->childRecipe($part);
        $outputs = $this->planTreeNode($recipe, $name, $context, $values, $prefix, $folder, $plan, $scope, $depth, $nodeKey);
        foreach ($outputs as $alias => $artifact) {
            $values[$partName.'.'.$alias] = $artifact;
        }
        foreach ($part->insertions() as $insert) {
            $target = $parent[$insert->into] ?? null;
            if ($target instanceof ResolvedArtifact && in_array($insert->into, $owned, true)) {
                $path = $target->path();
            } elseif (str_starts_with($insert->into, '@')) {
                $path = $this->treeInsertPath($insert->into, $context);
            } else {
                throw GenerationRefused::because("Insert target [{$insert->into}] is not owned by this node. Use one of its member aliases or an anchored path. Nothing was written.");
            }
            $stub = (new Placeholders($values))->render((string) file_get_contents($this->treeStub('insert.'.$insert->stub)));
            $label = str_contains($insert->stub, 'action') ? 'action' : (str_contains($insert->stub, 'entry') || str_contains($insert->stub, 'href') ? 'entry' : $insert->stub);
            $this->treeInserts[] = ['path' => $path, 'at' => $insert->at, 'stub' => $stub, 'label' => $label];
        }

        return $outputs;
    }

    private function treeInsertPath(string $target, PlacementContext $context): string
    {
        $target = Path::normalize($target);
        [$anchor, $tail] = array_pad(explode('/', $target, 2), 2, '');
        if ($tail === '' || in_array('..', explode('/', $tail), true) || str_contains($tail, '@')) {
            throw GenerationRefused::because("Invalid insert path [{$target}]. Use an anchored path inside the node's group.");
        }
        $token = substr($anchor, 1);
        $dimensions = $this->preset->dimensionNames();
        $token = $token === 'group' ? (end($dimensions) ?: '') : (in_array($token, $dimensions, true) ? $token : ($dimensions[0] ?? ''));
        foreach ($this->preset->rules() as $rule) {
            if (! $rule instanceof TemplateRule) {
                continue;
            }
            $path = $rule->root()->path;
            foreach ($rule->segments() as $segment) {
                if ($segment->literal !== null) {
                    $path = Path::join($path, $segment->literal);
                } elseif ($segment->dimension !== null) {
                    $value = $context->get($segment->dimension);
                    if ($value === null) {
                        break;
                    }
                    $path = Path::join($path, $value);
                    if ($segment->dimension === $token) {
                        return Path::join($path, $tail);
                    }
                }
            }
        }
        throw GenerationRefused::because("Cannot resolve insert path [{$target}] in this layout. Use a member alias.");
    }

    private function growTree(ScaffoldPlan $plan, ScaffoldExecution $scope, string $name, PlacementContext $context): void
    {
        $rootName = explode('.', $this->recipeName)[0];
        $root = $this->laravelRegistry()->get($rootName) ?? throw GenerationRefused::because("Scaffold [{$rootName}] is not defined.");
        $part = $this->recipe;
        if (! $part instanceof Part) {
            throw GenerationRefused::because('The dot path must name a part.');
        }
        $partName = substr($this->recipeName, (int) strrpos($this->recipeName, '.') + 1);
        $value = $this->argument('value');
        if (! is_string($value) || $value === '') {
            throw GenerationRefused::because('Pass the part input after Group:Name.');
        }
        $segments = explode('/', str_replace('\\', '/', $value));
        if (count($segments) > 10) {
            throw GenerationRefused::because("Scaffold path {$value} exceeds the depth limit of 10. Nothing was written.");
        }
        $recursive = $part->scaffold() === $rootName;
        $folder = '';
        $childName = $name;
        $parentName = $name;
        if ($recursive) {
            $childName = (string) array_pop($segments);
            $folder = $name.'/'.($segments === [] ? '' : implode('/', $segments).'/');
            if ($segments !== []) {
                $parentName = (string) array_pop($segments);
            }
        }
        $values = $this->treeAnswers($root, $name, [], locating: true);
        $pathParts = explode('.', $this->recipeName);
        array_shift($pathParts);
        if (count($pathParts) > 1) {
            $inputs = explode('/', $value);
            if (count($inputs) !== count($pathParts)) {
                throw GenerationRefused::because('Pass a slash-separated input for each part in '.$this->recipeName.'. Nothing was written.');
            }
            $path = $rootName;
            foreach ($pathParts as $index => $ancestor) {
                if ($index === count($pathParts) - 1) {
                    $value = $inputs[$index];
                    break;
                }
                $path .= '.'.$ancestor;
                $ancestorPart = $this->laravelRegistry()->get($path);
                if (! $ancestorPart instanceof Part) {
                    throw GenerationRefused::because("Part [{$path}] is not defined.");
                }
                $given = $values;
                foreach ($ancestorPart->values() as $key => $answer) {
                    $given[$key] = is_string($answer) ? (new Placeholders($values))->render($answer) : $answer;
                }
                $given[$ancestor] = $inputs[$index];
                $root = $this->childRecipe($ancestorPart);
                $values = $this->treeAnswers($root, $name, $given, locating: true);
            }
        }
        $values['name'] = $parentName;
        foreach ($root->members() as $alias => $member) {
            $stem = $member->name === null ? $parentName : (new Placeholders($values))->name($member->name);
            $parentFolder = $recursive && $parentName !== $name ? $name.'/'.($segments === [] ? '' : implode('/', $segments).'/') : '';
            $artifact = $this->resolveArtifact($member->fileType, $parentFolder.$stem, $context);
            if (! is_file($this->laravel->basePath($artifact->path()))) {
                $short = class_basename($artifact->fqcn() ?? $artifact->name);
                $where = $this->inOption($context);
                $message = "There is no {$short} in {$where}.";
                $list = array_search($partName, $root->repetitions(), true);
                $option = is_string($list) ? " --{$list}={$value}" : '';
                if (! $this->interactive()) {
                    throw GenerationRefused::because($message." Run mod:{$rootName} ".$this->rawNameInput().$option.' first.');
                }
                if (! confirm($message." Create the {$name} resource with mod:{$rootName} first?", default: true)) {
                    $this->treeCancelled = true;

                    return;
                }
                if ($recursive && $parentName !== $name) {
                    throw GenerationRefused::because("Create the parent section with mod:{$this->recipeName} first. Nothing was written.");
                }
                $given = is_string($list) ? [$list => [$value]] : [];
                $this->planTreeNode($root, $name, $context, $given, '', '', $plan, $scope, 0);

                return;
            }
            $values[$alias] = $artifact;
        }
        $values['name'] = $name;
        $this->planTreePart($part, $partName, $recursive ? $childName : $value, $childName, $context, $values, '', $folder, $plan, $scope, count(explode('/', $value)), $this->recipeName, array_keys($root->members()));
    }
}
