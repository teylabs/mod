<?php

namespace Tey\Mod\Listing;

use Illuminate\Foundation\Application;
use Tey\Mod\Discovery\Discovery;
use Tey\Mod\Discovery\DiscoveryOptions;
use Tey\Mod\Discovery\Inventory;
use Tey\Mod\Generation\GroupFolders;
use Tey\Mod\Layout\CompiledLayout;
use Tey\Mod\Layout\LayoutRegistry;

/** @internal A topic in the additive inventory.
 * @phpstan-import-type SchemaFragment from InventorySection
 * @phpstan-import-type PartialReport from LayoutInventory
 */
final class LayoutSection implements InventorySection
{
    /** @return PartialReport */
    public function read(Application $app, CompiledLayout $layout, DiscoveryOptions $options, Inventory $inventory, ?Discovery $discovery): array
    {
        $config = $app->make('config');
        $configuredName = $config->get('mod.layout', 'laravel');
        $name = is_string($configuredName) ? $configuredName : 'layout';
        $registry = $app->make(LayoutRegistry::class);
        $builder = $registry->has($name) ? $registry->layout($name) : null;

        return ['layout' => $name, 'extends' => $builder?->parentName(), 'path' => $builder?->toArray()['path'], 'token' => implode('/', $layout->dimensionNames()) ?: null, 'groups' => (new GroupFolders($app->basePath()))->groups($layout)];
    }

    /** @return SchemaFragment */
    public function schema(): array
    {
        return InventorySectionRegistry::fragment('layout');
    }
}
