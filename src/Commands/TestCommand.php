<?php

namespace Tey\Mod\Commands;

use Illuminate\Foundation\Console\TestMakeCommand;
use Tey\Mod\Artifact\ClassIdentity;
use Tey\Mod\Artifact\ResolvedArtifact;
use Tey\Mod\Commands\Concerns\PlacesGeneratedClass;
use Tey\Mod\Generation\GenerationPlan;
use Tey\Mod\Generation\GeneratorAdapter;

/**
 * Native make:test generator. The preset declares the default test folder;
 * --unit replaces that first folder with the native alternative, retaining placement.
 */
class TestCommand extends TestMakeCommand implements GeneratorAdapter
{
    use PlacesGeneratedClass {
        plan as placedPlan;
    }

    protected function plan(): GenerationPlan
    {
        $plan = $this->placedPlan();

        if (! $this->option('unit')) {
            return $plan;
        }

        $primary = $plan->primary;
        $root = $this->preset()->rule($this->kind()->id)->root();
        $segments = explode('/', (string) $root->pathRemainder($primary->path()));
        $segments[0] = class_basename($this->getDefaultNamespace($this->rootNamespace()));
        $file = array_pop($segments);
        $identity = new ClassIdentity($root->namespaceFor($segments), $primary->name, $root->pathFor($segments, $file));

        return new GenerationPlan(new ResolvedArtifact($primary->kind, $primary->context, $primary->name, $identity, $primary->nested), $plan->relations);
    }
}
