<?php

namespace Tey\Mod\Listing;

use Illuminate\Foundation\Application;
use Tey\Mod\Discovery\Discovery;
use Tey\Mod\Discovery\DiscoveryOptions;
use Tey\Mod\Discovery\Inventory;
use Tey\Mod\Install\InertiaEdits;
use Tey\Mod\Layout\CompiledLayout;
use Tey\Mod\Support\Stack;

/** @internal
 * @phpstan-import-type PartialReport from LayoutInventory
 * @phpstan-import-type SchemaFragment from InventorySection
 */
final class WiringSection implements InventorySection
{
    /** @return PartialReport */
    public function read(Application $app, CompiledLayout $layout, DiscoveryOptions $options, Inventory $inventory, ?Discovery $discovery): array
    {
        return ['wiring' => (new InertiaEdits($app->basePath(), $layout, $app->make(Stack::class)))->wiring()];
    }

    /** @return SchemaFragment */
    public function schema(): array
    {
        return InventorySectionRegistry::fragment('wiring');
    }
}
