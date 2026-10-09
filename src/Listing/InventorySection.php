<?php

namespace Tey\Mod\Listing;

use Illuminate\Foundation\Application;
use Tey\Mod\Discovery\Discovery;
use Tey\Mod\Discovery\DiscoveryOptions;
use Tey\Mod\Discovery\Inventory;
use Tey\Mod\Layout\CompiledLayout;

/** @internal Each topic registers its data and an additive JSON Schema fragment.
 * @phpstan-import-type PartialReport from LayoutInventory
 *
 * @phpstan-type SchemaFragment array{type: string, required: list<string>, properties: array<string, mixed>}
 */
interface InventorySection
{
    /** @return PartialReport */
    public function read(Application $app, CompiledLayout $layout, DiscoveryOptions $options, Inventory $inventory, ?Discovery $discovery): array;

    /** @return SchemaFragment */
    public function schema(): array;
}
