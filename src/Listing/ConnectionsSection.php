<?php

namespace Tey\Mod\Listing;

use Illuminate\Foundation\Application;
use Tey\Mod\Database\GroupConnections;
use Tey\Mod\Discovery\Discovery;
use Tey\Mod\Discovery\DiscoveryOptions;
use Tey\Mod\Discovery\Inventory;
use Tey\Mod\Generation\GroupFolders;
use Tey\Mod\Layout\CompiledLayout;

/** @internal Database connections for layout groups.
 * @phpstan-import-type PartialReport from LayoutInventory
 * @phpstan-import-type SchemaFragment from InventorySection
 */
final class ConnectionsSection implements InventorySection
{
    /** @return PartialReport */
    public function read(Application $app, CompiledLayout $layout, DiscoveryOptions $options, Inventory $inventory, ?Discovery $discovery): array
    {
        $declarations = $app->make(GroupConnections::class)->declarations();
        $groups = array_values(array_unique([...(new GroupFolders($app->basePath()))->groups($layout), ...array_keys($declarations)]));
        sort($groups);

        return ['connections' => array_map(fn (string $group): array => ['group' => $group, 'connection' => $declarations[$group] ?? $app->make('db')->getDefaultConnection(), 'declared' => isset($declarations[$group])], $groups)];
    }

    /** @return SchemaFragment */
    public function schema(): array
    {
        return InventorySectionRegistry::fragment('connections');
    }
}
