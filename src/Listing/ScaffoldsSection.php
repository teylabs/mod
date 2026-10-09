<?php

namespace Tey\Mod\Listing;

use Illuminate\Foundation\Application;
use Tey\Mod\Discovery\Discovery;
use Tey\Mod\Discovery\DiscoveryOptions;
use Tey\Mod\Discovery\Inventory;
use Tey\Mod\Layout\BuiltIn\GeneratorSources;
use Tey\Mod\Layout\CompiledLayout;
use Tey\Mod\Scaffolds\ScaffoldRegistry;

/** @internal A topic in the additive inventory.
 * @phpstan-import-type SchemaFragment from InventorySection
 * @phpstan-import-type PartialReport from LayoutInventory
 */
final class ScaffoldsSection implements InventorySection
{
    /** @return PartialReport */
    public function read(Application $app, CompiledLayout $layout, DiscoveryOptions $options, Inventory $inventory, ?Discovery $discovery): array
    {
        $scaffolds = $app->make(ScaffoldRegistry::class);
        $nodes = [];
        foreach ([$scaffolds, ...array_map($scaffolds->forGroup(...), $scaffolds->groups())] as $registry) {
            $origins = $registry->origins();
            foreach ($registry->nodes() as $key => $node) {
                $nodes[$key.'|'.$node['source']] = [...$node, 'origin' => $origins[$key]];
            }
        }
        $items = [];
        foreach ($nodes as $node) {
            $scaffoldName = $node['key'];
            $members = [];
            foreach ($node['members'] as $alias => $member) {
                $members[] = ['alias' => $alias, 'type' => $member['fileType'], 'name' => $member['name'], 'stub' => $member['stub'], 'options' => $member['options'], 'folder' => LayoutInventory::folder($layout, $member['fileType']), 'ungrouped' => $member['ungrouped'], 'group' => $member['group'], 'existing' => $member['existing']];
            }
            $source = $node['source'];
            $label = in_array($source, ['app', 'layout'], true) || str_starts_with($source, GeneratorSources::PREFIX) ? $source : 'package:'.$source;
            $row = ['name' => $scaffoldName, 'command' => 'mod:'.$scaffoldName, 'source' => $label, 'members' => $members];
            if ($node['from'] !== $label || str_starts_with($source, GeneratorSources::PREFIX)) {
                $row['from'] = $node['from'];
            }
            if (str_starts_with($source, GeneratorSources::PREFIX)) {
                $row['origin'] = $node['origin'];
            }
            if ($node['children'] !== [] || str_contains($scaffoldName, '.')) {
                $row['uses'] = $node['uses'];
                $row['children'] = $node['children'];
            }
            $items[] = $row;
        }
        usort($items, static fn (array $a, array $b): int => $a['name'] <=> $b['name']);
        $scaffoldProblems = [];
        foreach ($scaffolds->problems() as $scaffoldName => $reason) {
            $scaffoldProblems[] = ['name' => $scaffoldName, 'reason' => $reason];
        }

        return ['scaffolds' => ['items' => $items, 'problems' => $scaffoldProblems]];
    }

    /** @return SchemaFragment */
    public function schema(): array
    {
        return InventorySectionRegistry::fragment('scaffolds');
    }
}
