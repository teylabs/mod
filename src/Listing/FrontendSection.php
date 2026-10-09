<?php

namespace Tey\Mod\Listing;

use Illuminate\Foundation\Application;
use Tey\Mod\Discovery\Discovery;
use Tey\Mod\Discovery\DiscoveryOptions;
use Tey\Mod\Discovery\Inventory;
use Tey\Mod\Layout\CompiledLayout;

/**
 * Frontend paths come from the compiled layout, including overrides and app casing.
 *
 * @internal A topic in the additive inventory.
 *
 * @phpstan-import-type SchemaFragment from InventorySection
 * @phpstan-import-type PartialReport from LayoutInventory
 */
final class FrontendSection implements InventorySection
{
    /** @return PartialReport */
    public function read(Application $app, CompiledLayout $layout, DiscoveryOptions $options, Inventory $inventory, ?Discovery $discovery): array
    {
        $frontend = $layout->frontend();
        $dimensions = $layout->dimensionNames();
        $namespace = $frontend['views'] === null || $dimensions === [] ? null
            : implode('.', array_map(static fn (string $name): string => '{'.$name.'.kebab}', $dimensions));

        return ['frontend' => [...$frontend, 'view_namespace' => $namespace]];
    }

    /** @return SchemaFragment */
    public function schema(): array
    {
        return InventorySectionRegistry::fragment('frontend');
    }
}
