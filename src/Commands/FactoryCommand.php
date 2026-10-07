<?php

namespace Tey\Mod\Commands;

use Illuminate\Console\GeneratorCommand;
use Illuminate\Database\Console\Factories\FactoryMakeCommand;
use Tey\Mod\Artifact\ResolvedArtifact;
use Tey\Mod\Commands\Concerns\PlacesGeneratedClass;
use Tey\Mod\Generation\FactoryConvention;
use Tey\Mod\Generation\GeneratorAdapter;
use Tey\Mod\Relation\RelationResolution;

/**
 * Native make:factory, placed by the preset.
 *
 * The model is --model (placed as the model kind when bare), else the target
 * of a declared factory -> model relation, else the native guess. When
 * Laravel's naming convention would not link the pair, the factory names
 * its model with $model, fully qualified (older native stubs import nothing).
 */
class FactoryCommand extends FactoryMakeCommand implements GeneratorAdapter
{
    use PlacesGeneratedClass;

    /**
     * @return list<RelationResolution>
     */
    protected function plannedRelations(ResolvedArtifact $primary): array
    {
        return $this->option('model') ? [] : $this->relationsTo($primary, 'model', required: false);
    }

    /**
     * @param  string  $name
     * @return string
     */
    protected function buildClass($name)
    {
        $primary = $this->primary();
        $model = $this->modelClass();
        $basename = (string) $primary->class()?->basename;

        $replace = [
            '{{ factoryNamespace }}' => (string) $primary->class()?->namespace,
            'NamespacedDummyModel' => $model,
            '{{ namespacedModel }}' => $model,
            '{{namespacedModel}}' => $model,
            'DummyModel' => class_basename($model),
            '{{ model }}' => class_basename($model),
            '{{model}}' => class_basename($model),
            '{{ factory }}Factory' => $basename,
            '{{factory}}Factory' => $basename,
            '{{ factory }}' => $primary->name,
            '{{factory}}' => $primary->name,
        ];

        $stub = str_replace(array_keys($replace), array_values($replace), GeneratorCommand::buildClass($name));

        if (! (new FactoryConvention($this->laravel->getNamespace()))->links($model, (string) $primary->fqcn())) {
            $stub = preg_replace(
                '/(class '.preg_quote($basename, '/').' extends Factory\R\{\R)/',
                '$1    protected $model = \\'.$model.'::class;'."\n\n",
                $stub,
                1,
            ) ?? $stub;
        }

        return $stub;
    }

    private function modelClass(): string
    {
        $option = $this->option('model');

        if (is_string($option) && $option !== '') {
            return $this->placeSibling('model', $option);
        }

        $related = ($this->plannedRelationsTo('model')[0] ?? null)?->target?->fqcn();

        return $related ?? $this->qualifyModel($this->guessModelName((string) $this->primary()->class()?->basename));
    }
}
