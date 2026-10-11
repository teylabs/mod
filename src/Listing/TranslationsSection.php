<?php

namespace Tey\Mod\Listing;

use Illuminate\Foundation\Application;
use Tey\Mod\Discovery\Discovery;
use Tey\Mod\Discovery\DiscoveryOptions;
use Tey\Mod\Discovery\Inventory;
use Tey\Mod\Layout\CompiledLayout;
use Tey\Mod\Translations\TranslationDirectories;

/**
 * Existing language folders and their predictable namespaces.
 *
 * @internal A topic in the additive inventory.
 *
 * @phpstan-import-type SchemaFragment from InventorySection
 * @phpstan-import-type PartialReport from LayoutInventory
 */
final class TranslationsSection implements InventorySection
{
    /** @return PartialReport */
    public function read(Application $app, CompiledLayout $layout, DiscoveryOptions $options, Inventory $inventory, ?Discovery $discovery): array
    {
        return ['translations' => $app->make(TranslationDirectories::class)->entries()];
    }

    /** @return SchemaFragment */
    public function schema(): array
    {
        return InventorySectionRegistry::fragment('translations');
    }
}
