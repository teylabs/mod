<?php

namespace Tey\Mod\Listing;

use Illuminate\Foundation\Application;
use Tey\Mod\Discovery\Discovery;
use Tey\Mod\Discovery\DiscoveryOptions;
use Tey\Mod\Discovery\DiscoveryType;
use Tey\Mod\Discovery\Inventory;
use Tey\Mod\Layout\CompiledLayout;

/** @internal A topic in the additive inventory.
 * @phpstan-import-type SchemaFragment from InventorySection
 * @phpstan-import-type PartialReport from LayoutInventory
 */
final class DiscoverySection implements InventorySection
{
    /** @return PartialReport */
    public function read(Application $app, CompiledLayout $layout, DiscoveryOptions $options, Inventory $inventory, ?Discovery $discovery): array
    {
        $counts = [];
        foreach (DiscoveryType::cases() as $type) {
            $counts[$type->value] = count($inventory->ofType($type));
        }
        $counts['rejected'] = count($inventory->rejections);

        return ['discovery' => ['enabled' => $options->enabled, 'source' => $discovery?->source(), 'counts' => $counts, 'entries' => array_map(static fn ($entry): array => $entry->toArray(), $inventory->entries), 'rejections' => array_map(static fn ($entry): array => $entry->toArray(), $inventory->rejections), 'stale_cache' => $discovery?->staleCacheReason()]];
    }

    /** @return SchemaFragment */
    public function schema(): array
    {
        return InventorySectionRegistry::fragment('discovery');
    }
}
