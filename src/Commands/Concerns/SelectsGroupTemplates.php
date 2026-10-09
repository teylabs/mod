<?php

namespace Tey\Mod\Commands\Concerns;

use Illuminate\Container\Container;
use Tey\Mod\Exceptions\GenerationRefused;
use Tey\Mod\Layout\BuiltIn\GeneratorSources;
use Tey\Mod\Layout\LayoutRegistry;
use Tey\Mod\Placement\PlacementContext;
use Tey\Mod\Support\Stack;
use Tey\Mod\Templates\TemplateCatalog;

/** @internal Rebind only this command for the target module, then restore it. */
trait SelectsGroupTemplates
{
    /** @return list<string> */
    private function templateSlots(): array
    {
        $slots = $this->layout()->templates()[$this->kind()->id]['slots'] ?? [];
        $container = Container::getInstance();
        if ($container->resolved(TemplateCatalog::class)) {
            foreach ($container->make(TemplateCatalog::class)->variants()[$this->kind()->id] ?? [] as $record) {
                $slots = [...$slots, ...$record['slots']];
            }
        }

        return array_values(array_unique($slots));
    }

    /** @param callable(): int $run */
    private function withGroupTemplates(callable $run): int
    {
        $original = $this->layout();
        $kind = $this->kind();
        $this->bindGroupTemplates($this->groupTemplateContext());
        try {
            return $run();
        } finally {
            $this->forKind($original, $kind);
        }
    }

    protected function groupTemplateContext(): PlacementContext
    {
        return $this->placementContext();
    }

    private function bindGroupTemplates(PlacementContext $context): void
    {
        $catalog = $this->laravel->make(TemplateCatalog::class);
        if (! $catalog->hasGroupTemplates()) {
            return;
        }
        $kind = $this->kind();
        $group = implode('/', $context->only($this->layout()->dimensionNames())->toArray());
        $name = $this->layoutName();
        $registry = $this->laravel->make(LayoutRegistry::class);
        if (! $registry->has($name)) {
            return;
        }
        $selected = $catalog->forGroup($group);
        $layout = $registry->compile($name, $selected)->withStack($this->laravel->make(Stack::class));
        $command = $this->getName() ?? 'mod:'.$kind->id;
        if (isset($selected->conflicts()[$command])) {
            throw GenerationRefused::because($selected->conflicts()[$command]);
        }
        if (! $layout->hasKind($kind->id)) {
            throw GenerationRefused::because(sprintf(GeneratorSources::NO_TEMPLATE, $command, $group === '' ? 'the app' : $group));
        }
        $this->forKind($layout, $layout->kind($kind->id));
    }
}
