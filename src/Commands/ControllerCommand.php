<?php

namespace Tey\Mod\Commands;

use Illuminate\Console\GeneratorCommand;
use Illuminate\Routing\Console\ControllerMakeCommand;
use InvalidArgumentException;
use Tey\Mod\Artifact\ResolvedArtifact;
use Tey\Mod\Commands\Concerns\PlacesGeneratedClass;
use Tey\Mod\Generation\ClassMembers;
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
 *
 * @api
 */
class ControllerCommand extends ControllerMakeCommand implements GeneratorAdapter
{
    use PlacesGeneratedClass;

    /**
     * @return list<RelationResolution>
     *
     * @api
     */
    protected function plannedRelations(ResolvedArtifact $primary): array
    {
        if (! $this->option('requests') || ! $this->option('model')) {
            return [];
        }

        return $this->relationsTo($primary, $this->relatedFileType('request'));
    }

    /**
     * @param  string  $model
     * @return string
     *
     * @internal
     */
    protected function parseModel($model)
    {
        if (preg_match('/[^A-Za-z0-9_\/\\\\]/', $model)) {
            throw new InvalidArgumentException('Model name contains invalid characters.');
        }

        return $this->placeSibling($this->relatedFileType('model'), $model);
    }

    /**
     * @return array<string, string>
     *
     * @internal
     */
    protected function buildParentReplacements()
    {
        $parentModelClass = $this->parseModel($this->stringOption('parent'));

        if (! $this->classExists($parentModelClass) &&
            confirm("A {$parentModelClass} model does not exist. Do you want to generate it?", default: true)) {
            $this->generateOwnedClass($parentModelClass, $this->relatedFileType('model'));
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
     *
     * @internal
     */
    protected function buildModelReplacements(array $replace)
    {
        $modelClass = $this->parseModel($this->stringOption('model'));

        if (! $this->classExists($modelClass) && confirm("A {$modelClass} model does not exist. Do you want to generate it?", default: true)) {
            $this->generateOwnedClass($modelClass, $this->relatedFileType('model'));
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
     *
     * @internal
     */
    protected function buildFormRequestReplacements(array $replace, $modelClass)
    {
        if (! $this->option('requests')) {
            return parent::buildFormRequestReplacements($replace, $modelClass);
        }

        foreach ($this->plannedRelationsTo($this->relatedFileType('request')) as $relation) {
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

        $source = GeneratorCommand::buildClass((string) $this->primary()->fqcn());
        $source = str_replace(['use {{ namespacedRequests }}', 'use {{namespacedRequests}}'], '', $source);
        $members = new ClassMembers($source);
        $imports = [];
        if (! $members->hasImport($store, $storeName)) {
            $imports[] = 'use '.$store.';';
        }
        if ($store !== $update && ! $members->hasImport($update, $updateName)) {
            $imports[] = 'use '.$updateImport.';';
        }

        return array_merge($replace, [
            'use {{ namespacedRequests }}' => implode("\n", $imports),
            'use {{namespacedRequests}}' => implode("\n", $imports),
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
