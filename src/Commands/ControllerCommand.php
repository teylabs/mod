<?php

namespace Tey\Mod\Commands;

use Illuminate\Routing\Console\ControllerMakeCommand;
use InvalidArgumentException;
use Tey\Mod\Artifact\ResolvedArtifact;
use Tey\Mod\Commands\Concerns\PlacesGeneratedClass;
use Tey\Mod\Generation\GeneratorAdapter;
use Tey\Mod\Relation\RelationResolution;

use function Laravel\Prompts\confirm;

/**
 * Native make:controller, placed by the preset.
 *
 * A bare --model / --parent is placed as the model kind; a missing model is
 * offered through mod:model, as natively. --requests (with --model, as
 * natively) follows the declared controller -> request relations; the ones
 * with ids `store-request` and `update-request` fill the stub's store and
 * update request placeholders by resolved identity.
 */
class ControllerCommand extends ControllerMakeCommand implements GeneratorAdapter
{
    use PlacesGeneratedClass;

    /**
     * @return list<RelationResolution>
     */
    protected function plannedRelations(ResolvedArtifact $primary): array
    {
        if (! $this->option('requests') || ! $this->option('model')) {
            return [];
        }

        return $this->relationsTo($primary, 'request');
    }

    /**
     * @param  string  $model
     * @return string
     */
    protected function parseModel($model)
    {
        if (preg_match('/[^A-Za-z0-9_\/\\\\]/', $model)) {
            throw new InvalidArgumentException('Model name contains invalid characters.');
        }

        return $this->placeSibling('model', $model);
    }

    /**
     * @return array<string, string>
     */
    protected function buildParentReplacements()
    {
        $parentModelClass = $this->parseModel($this->stringOption('parent'));

        if (! $this->classExists($parentModelClass) &&
            confirm("A {$parentModelClass} model does not exist. Do you want to generate it?", default: true)) {
            $this->generateOwnedClass($parentModelClass, 'model');
        }

        return [
            'ParentDummyFullModelClass' => $parentModelClass,
            '{{ namespacedParentModel }}' => $parentModelClass,
            '{{namespacedParentModel}}' => $parentModelClass,
            'ParentDummyModelClass' => class_basename($parentModelClass),
            '{{ parentModel }}' => class_basename($parentModelClass),
            '{{parentModel}}' => class_basename($parentModelClass),
            'ParentDummyModelVariable' => lcfirst(class_basename($parentModelClass)),
            '{{ parentModelVariable }}' => lcfirst(class_basename($parentModelClass)),
            '{{parentModelVariable}}' => lcfirst(class_basename($parentModelClass)),
        ];
    }

    /**
     * @param  array<string, string>  $replace
     * @return array<string, string>
     */
    protected function buildModelReplacements(array $replace)
    {
        $modelClass = $this->parseModel($this->stringOption('model'));

        if (! $this->classExists($modelClass) && confirm("A {$modelClass} model does not exist. Do you want to generate it?", default: true)) {
            $this->generateOwnedClass($modelClass, 'model');
        }

        $replace = $this->buildFormRequestReplacements($replace, $modelClass);

        return array_merge($replace, [
            'DummyFullModelClass' => $modelClass,
            '{{ namespacedModel }}' => $modelClass,
            '{{namespacedModel}}' => $modelClass,
            'DummyModelClass' => class_basename($modelClass),
            '{{ model }}' => class_basename($modelClass),
            '{{model}}' => class_basename($modelClass),
            'DummyModelVariable' => lcfirst(class_basename($modelClass)),
            '{{ modelVariable }}' => lcfirst(class_basename($modelClass)),
            '{{modelVariable}}' => lcfirst(class_basename($modelClass)),
        ]);
    }

    /**
     * @param  array<string, string>  $replace
     * @param  string  $modelClass
     * @return array<string, string>
     */
    protected function buildFormRequestReplacements(array $replace, $modelClass)
    {
        if (! $this->option('requests')) {
            return parent::buildFormRequestReplacements($replace, $modelClass);
        }

        foreach ($this->plannedRelationsTo('request') as $relation) {
            $this->followRelation($relation);
        }

        $store = $this->plannedRelation('controller-store-request')?->target?->fqcn() ?? 'Illuminate\Http\Request';
        $update = $this->plannedRelation('controller-update-request')?->target?->fqcn() ?? 'Illuminate\Http\Request';

        $storeName = class_basename($store);
        $updateName = class_basename($update);
        $updateImport = $update;

        if ($store !== $update && strcasecmp($storeName, $updateName) === 0) {
            $updateName = 'Update'.$updateName;
            $updateImport .= ' as '.$updateName;
        }

        $namespacedRequests = $store.';';

        if ($store !== $update) {
            $namespacedRequests .= PHP_EOL.'use '.$updateImport.';';
        }

        return array_merge($replace, [
            '{{ storeRequest }}' => $storeName,
            '{{storeRequest}}' => $storeName,
            '{{ updateRequest }}' => $updateName,
            '{{updateRequest}}' => $updateName,
            '{{ namespacedStoreRequest }}' => $store,
            '{{namespacedStoreRequest}}' => $store,
            '{{ namespacedUpdateRequest }}' => $update,
            '{{namespacedUpdateRequest}}' => $update,
            '{{ namespacedRequests }}' => $namespacedRequests,
            '{{namespacedRequests}}' => $namespacedRequests,
        ]);
    }

    private function stringOption(string $name): string
    {
        $value = $this->option($name);

        return is_string($value) ? $value : '';
    }
}
