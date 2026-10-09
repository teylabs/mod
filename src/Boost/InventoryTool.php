<?php

namespace Tey\Mod\Boost;

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Tey\Mod\Exceptions\ModException;
use Tey\Mod\Listing\LayoutInventory;
use Throwable;

#[IsReadOnly]
/** @internal Read the same inventory used by mod:list --json. */
final class InventoryTool extends Tool
{
    protected string $name = 'mod-inventory';

    protected string $description = 'Read the mod:list --json inventory before generating files: layouts, file types, templates, scaffolds, frontend paths, views, routes and wiring. This tool writes nothing.';

    public function __construct(private readonly LayoutInventory $inventory) {}

    public function handle(Request $request): Response
    {
        try {
            return Response::json($this->inventory->read());
        } catch (Throwable $exception) {
            if (! $exception instanceof ModException) {
                throw $exception;
            }

            return Response::error('mod-inventory: '.$exception->getMessage());
        }
    }
}
