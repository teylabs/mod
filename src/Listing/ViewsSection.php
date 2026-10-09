<?php

namespace Tey\Mod\Listing;

use Illuminate\Foundation\Application;
use Symfony\Component\Finder\Finder;
use Tey\Mod\Discovery\Discovery;
use Tey\Mod\Discovery\DiscoveryOptions;
use Tey\Mod\Discovery\Inventory;
use Tey\Mod\Layout\CompiledLayout;
use Tey\Mod\Support\Path;
use Tey\Mod\Views\ViewDirectories;
use Tey\Mod\Views\ViewIdentity;

/** @internal
 * @phpstan-import-type PartialReport from LayoutInventory
 * @phpstan-import-type SchemaFragment from InventorySection
 */
final class ViewsSection implements InventorySection
{
    /** @return PartialReport */
    public function read(Application $app, CompiledLayout $layout, DiscoveryOptions $options, Inventory $inventory, ?Discovery $discovery): array
    {
        $views = [];
        foreach ((new ViewDirectories($layout, $app->basePath()))->entries() as $entry) {
            $components = [];
            $directory = Path::resolve($app->basePath(), $entry['path'].'/components');
            if (is_dir($directory)) {
                foreach ((new Finder)->files()->in($directory)->name('*.blade.php')->sortByName() as $file) {
                    $name = 'components.'.str_replace('/', '.', substr(Path::normalize($file->getRelativePathname()), 0, -10));
                    $path = Path::join($entry['path'], 'components', $file->getRelativePathname());
                    $components[] = ['path' => $path, 'tag' => (new ViewIdentity($entry['group'], $name, $path))->tag()];
                }
            }
            $views[] = ['group' => $entry['group'], 'namespace' => $entry['namespace'], 'path' => $entry['path'], 'components' => $components];
        }

        return ['views' => $views];
    }

    /** @return SchemaFragment */
    public function schema(): array
    {
        return InventorySectionRegistry::fragment('views');
    }
}
