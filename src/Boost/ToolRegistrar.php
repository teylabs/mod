<?php

namespace Tey\Mod\Boost;

use Illuminate\Contracts\Foundation\Application;
use Laravel\Boost\Mcp\Boost;
use Laravel\Mcp\Server\Tool;

/** @internal Optional Boost integration; no MCP classes load without Boost. */
final class ToolRegistrar
{
    public static function register(Application $app): void
    {
        if (! class_exists(Boost::class) || ! class_exists(Tool::class)) {
            return;
        }

        $config = $app->make('config');
        $includes = (array) $config->get('boost.mcp.tools.include', []);
        foreach ([InventoryTool::class, PlanTool::class] as $tool) {
            if (! in_array($tool, $includes, true)) {
                $includes[] = $tool;
            }
        }
        $config->set('boost.mcp.tools.include', array_values($includes));
    }
}
