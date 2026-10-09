<?php

namespace Tey\Mod\Listing;

use Illuminate\Console\Command;
use Illuminate\Foundation\Application;
use Tey\Mod\Discovery\Discovery;
use Tey\Mod\Discovery\DiscoveryOptions;
use Tey\Mod\Discovery\Inventory;
use Tey\Mod\Generation\GeneratorRegistry;
use Tey\Mod\Generation\PackageDetector;
use Tey\Mod\Generation\PlainFile\Casing;
use Tey\Mod\Generation\StubRegistry;
use Tey\Mod\Layout\CompiledLayout;
use Tey\Mod\Support\Path;
use Tey\Mod\Support\Stack;

/** @internal A topic in the additive inventory.
 * @phpstan-import-type SchemaFragment from InventorySection
 * @phpstan-import-type PartialReport from LayoutInventory
 */
final class TypesSection implements InventorySection
{
    /** @return PartialReport */
    public function read(Application $app, CompiledLayout $layout, DiscoveryOptions $options, Inventory $inventory, ?Discovery $discovery): array
    {
        $config = $app->make('config');
        $types = [];
        $commands = $app->make(GeneratorRegistry::class)->commands($layout, $app);
        foreach ($layout->kinds() as $id => $kind) {
            if ($kind->command === null || ($id === 'page' && $app->make(Stack::class)->inertia() === null)) {
                continue;
            }
            $command = null;
            foreach ($commands as $candidate) {
                if ($candidate->getName() === $kind->command) {
                    $command = $candidate;
                    break;
                }
            }
            if ($command === null) {
                continue;
            }
            if ($command instanceof Command) {
                $command->setLaravel($app);
            }
            $selection = method_exists($command, 'stubSelection') ? $command->stubSelection() : $app->make(StubRegistry::class)->select(
                $id, $layout->stub($id), $app->basePath(), $app->make(PackageDetector::class),
                static fn (string $key): mixed => $config->get($key), native: true,
            );
            $definitions = [];
            foreach ($options->definitionsFor($layout) as $definition) {
                if ($definition->kindId === $id && $definition->enabled) {
                    $definitions[] = $definition->type->value;
                }
            }
            $types[] = ['id' => $id, ...($kind->extension === null ? [] : ['plain' => true, 'extension' => $kind->extension, 'case' => $kind->case ?? Casing::forExtension($kind->extension)]), 'command' => $kind->command, 'aliases' => $kind->aliases, 'folder' => LayoutInventory::folder($layout, $id), 'source' => $selection->source, 'stub' => $selection->file === null ? null : (Path::relative($app->basePath(), $selection->file) ?? Path::normalize($selection->file)), 'base' => $selection->choice?->base, 'discovery' => $options->enabled ? $definitions : [], 'classes' => array_map(static fn ($entry): array => $entry->toArray(), $inventory->ofKind($id)), 'relations' => array_map(static fn ($relation): string => $relation->id, $layout->relationsFrom($id))];
        }
        usort($types, static fn (array $a, array $b): int => $a['id'] <=> $b['id']);

        return ['types' => $types];
    }

    /** @return SchemaFragment */
    public function schema(): array
    {
        return InventorySectionRegistry::fragment('types');
    }
}
