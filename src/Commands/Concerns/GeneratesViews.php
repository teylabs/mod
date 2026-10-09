<?php

namespace Tey\Mod\Commands\Concerns;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Tey\Mod\Artifact\ArtifactKind;
use Tey\Mod\Artifact\ResolvedArtifact;
use Tey\Mod\Exceptions\GenerationRefused;
use Tey\Mod\Generation\GenerationPlan;
use Tey\Mod\Plans\Plan;
use Tey\Mod\Plans\PlanWriter;
use Tey\Mod\Relation\NameDerivation;
use Tey\Mod\Relation\Relation;
use Tey\Mod\Relation\RelationMode;
use Tey\Mod\Relation\RelationResolution;
use Tey\Mod\Relation\ScopeMap;
use Tey\Mod\Scaffolds\ScaffoldExecution;
use Tey\Mod\Scaffolds\ScaffoldPlan;
use Tey\Mod\Support\Path;
use Tey\Mod\Views\ViewIdentity;

/** Native generators retain their stubs; companion views join their plans. */
trait GeneratesViews
{
    use PlacesGeneratedClass {
        execute as private executePlaced;
        plan as private classPlan;
        refuseCollisions as private refuseClassCollisions;
    }

    /** A companion view's unqualified name, or null for class-only generation. */
    protected function companionViewName(): ?string
    {
        return null;
    }

    protected function viewArtifact(ResolvedArtifact $primary, string $name, string $extension = 'blade.php'): ResolvedArtifact
    {
        if ($this->layout()->frontend()['views'] === null) {
            $path = parent::viewPath(str_replace('.', '/', $name).'.'.$extension);
            $relative = Path::relative($this->laravel->basePath(), $path)
                ?? throw GenerationRefused::because($this->getName().' cannot plan a view outside the application folder. Check view.paths.');
            $identity = new ViewIdentity(null, $name, $relative);
        } else {
            $identity = ViewIdentity::resolve($this->layout(), $primary->context, $name, $extension);
        }

        return new ResolvedArtifact(ArtifactKind::file('view'), $primary->context, $name, $identity);
    }

    protected function plan(): GenerationPlan
    {
        $plan = $this->classPlan();
        $name = $this->companionViewName();
        if ($name === null) {
            return $plan;
        }
        $view = $this->viewArtifact($plan->primary, $name);
        $relation = new Relation('view', $plan->primary->kind->id, 'view', ScopeMap::same(), NameDerivation::explicit(), RelationMode::Generate);

        return new GenerationPlan($plan->primary, [...$plan->relations, RelationResolution::resolved($relation, $plan->primary, $view)]);
    }

    protected function generateScaffoldRelations(): void
    {
        // Native handle() writes the view with its native template, not mod:view's template.
        foreach (($this->currentPlan() ?? throw new \LogicException('A plan is required to generate companions.'))->relations as $relation) {
            if ($relation->relation->id !== 'view') {
                $this->followRelation($relation);
            }
        }
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (! $input->getOption('dry-run') || $this->scaffoldExecution() !== null) {
            return $this->executePlaced($input, $output);
        }

        return (new PlanWriter)->preview($this, $input, function (Plan $preview) use ($input, $output): void {
            $scope = new ScaffoldExecution;
            $this->laravel->instance(ScaffoldExecution::class, $scope);
            try {
                $this->executePlaced($input, $output);
                $plan = $scope->collected();
                if ($plan === null) {
                    throw GenerationRefused::because($this->getName().' could not describe its files. Nothing was written.');
                }
                $combined = new ScaffoldPlan;
                $combined->add($plan->primary->kind->id, $plan, $scope);
                foreach ($combined->files() as $file) {
                    $artifact = $file['artifact'];
                    if ($artifact->identity instanceof ViewIdentity) {
                        $identity = $artifact->identity;
                        $preview->group ??= $identity->group;
                        $preview->file($file['alias'], $artifact->kind->id, $artifact->path(), ['path' => $identity->path(), 'name' => $identity->name(), 'tag' => $identity->tag()], is_file($this->existingArtifacts()->absolute($artifact->path())));
                    } else {
                        $preview->artifact($file['alias'], $artifact, $this->laravel->basePath());
                    }
                }
                foreach ($scope->defaultStubs as $stub) {
                    if ($stub !== '' && ! is_file($stub)) {
                        throw GenerationRefused::because('Template ['.$stub.'] does not exist. Nothing was written.');
                    }
                }
                $combined->existing($this->existingArtifacts());
                $preview->collisions((bool) $input->getOption('force'));
            } finally {
                $this->laravel->forgetInstance(ScaffoldExecution::class);
            }
        });
    }

    protected function refuseCollisions(GenerationPlan $plan, bool $overwritePrimary): void
    {
        $this->beforeGeneration($plan);
        $this->refuseClassCollisions($plan, $overwritePrimary);
    }

    protected function beforeGeneration(GenerationPlan $plan): void
    {
        foreach ([$plan->primary, ...$plan->generated()] as $view) {
            if ($view->identity instanceof ViewIdentity && file_exists($this->existingArtifacts()->absolute($view->path())) && ! $this->option('force') && ! $this->scaffoldExecution()?->force) {
                throw GenerationRefused::because($this->getName().' cannot write ['.$view->path().']; it already exists. Pass --force to replace it. Nothing was written.');
            }
        }
    }

    protected function viewIdentity(): ViewIdentity
    {
        $primary = $this->primary();
        if ($primary->identity instanceof ViewIdentity) {
            return $primary->identity;
        }
        foreach ($this->currentPlan()?->generated() ?? [] as $view) {
            if ($view->identity instanceof ViewIdentity) {
                return $view->identity;
            }
        }
        throw new \LogicException('This invocation has no view.');
    }

    protected function viewPath($path = '')
    {
        return $this->layout()->frontend()['views'] === null ? parent::viewPath($path) : $this->existingArtifacts()->absolute($this->viewIdentity()->path());
    }
}
