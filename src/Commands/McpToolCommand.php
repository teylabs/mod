<?php

namespace Tey\Mod\Commands;

use Laravel\Mcp\Console\Commands\MakeToolCommand;
use Tey\Mod\Commands\Concerns\PlacesGeneratedClass;
use Tey\Mod\Generation\GeneratorAdapter;

/** @internal Native MCP generator with layout placement. */
class McpToolCommand extends MakeToolCommand implements GeneratorAdapter
{
    use PlacesGeneratedClass {
        getStub as private placedStub;
    }

    protected function getStub(): string
    {
        return $this->placedStub();
    }
}
