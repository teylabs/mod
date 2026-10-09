<?php

namespace Tey\Mod\Listing;

use Illuminate\Foundation\Application;
use Tey\Mod\Discovery\Discovery;
use Tey\Mod\Discovery\DiscoveryOptions;
use Tey\Mod\Discovery\Inventory;
use Tey\Mod\Layout\CompiledLayout;
use Tey\Mod\Templates\TemplateCatalog;

/** @internal A topic in the additive inventory.
 * @phpstan-import-type SchemaFragment from InventorySection
 * @phpstan-import-type PartialReport from LayoutInventory
 */
final class TemplatesSection implements InventorySection
{
    /** @return PartialReport */
    public function read(Application $app, CompiledLayout $layout, DiscoveryOptions $options, Inventory $inventory, ?Discovery $discovery): array
    {
        $catalog = $app->make(TemplateCatalog::class);
        $problems = [];
        foreach ($catalog->skipped() as $path => $reason) {
            $problems[] = ['path' => $path, 'reason' => $reason];
        }

        return ['templates' => ['problems' => $problems, 'notices' => $catalog->notices()]];
    }

    /** @return SchemaFragment */
    public function schema(): array
    {
        return InventorySectionRegistry::fragment('templates');
    }
}
