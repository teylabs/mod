<?php

namespace Tey\Mod\Listing;

use Illuminate\Console\Command;
use Illuminate\Foundation\Application;
use Tey\Mod\Discovery\Discovery;
use Tey\Mod\Discovery\DiscoveryOptions;
use Tey\Mod\Discovery\Inventory;
use Tey\Mod\Layout\BuiltIn\RouteContent;
use Tey\Mod\Layout\CompiledLayout;
use Tey\Mod\Routing\ModRoutes;

/** @internal Explicit route entrypoints, never an invocation of their methods.
 * @phpstan-import-type PartialReport from LayoutInventory
 * @phpstan-import-type SchemaFragment from InventorySection
 */
final class RoutesSection implements InventorySection
{
    /** @return PartialReport */
    public function read(Application $app, CompiledLayout $layout, DiscoveryOptions $options, Inventory $inventory, ?Discovery $discovery): array
    {
        return ['routes' => $app->make(ModRoutes::class)->entries()];
    }

    /** @return SchemaFragment */
    public function schema(): array
    {
        return InventorySectionRegistry::fragment('routes');
    }

    /** @param list<array{group: string, entrypoint: string, kind: string, middleware_group: string, order: int, loaded_by: ?string}> $routes */
    public static function render(Command $command, array $routes, ModRoutes $loader): void
    {
        $unloaded = [];
        foreach ($routes as $route) {
            $entrypoint = $route['entrypoint'];
            if ($route['kind'] === 'file') {
                $entrypoint = basename(dirname($entrypoint)).'/'.basename($entrypoint);
            } else {
                $entrypoint = basename(str_replace('\\', '/', $entrypoint));
            }
            $by = $route['loaded_by'];
            $label = $by === null ? '(not loaded)' : (str_starts_with($by, 'provider: ') ? 'its provider (skipped by Mod::routes())' : 'Mod::routes()');
            $command->outputComponents()->twoColumnDetail('Routes '.$route['order'].' '.$route['group'], $entrypoint.'  '.$label);
            if ($command->getOutput()->isVerbose() && $by !== null) {
                $command->line('  '.$by);
            }
            if ($by === null) {
                $unloaded[$route['group']] = true;
            }
        }
        if ($unloaded !== []) {
            $notice = count($unloaded) === 1 ? RouteContent::UNLOADED_SINGLE : RouteContent::UNLOADED;
            $command->outputComponents()->warn(count($unloaded).$notice);
        }
        foreach ($loader->warnings() as $warning) {
            $command->outputComponents()->warn($warning);
        }
    }
}
