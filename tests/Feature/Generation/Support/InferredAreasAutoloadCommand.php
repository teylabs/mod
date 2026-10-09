<?php

namespace Tey\Mod\Tests\Feature\Generation\Support;

use Tey\Mod\Commands\AutoloadCommand;
use Tey\Mod\Layout\CompiledLayout;

/** Stand in for lane 3's folder-derived namespace, without changing the layout builder. */
final class InferredAreasAutoloadCommand extends AutoloadCommand
{
    protected function autoloadRoots(CompiledLayout $layout): array
    {
        return [['namespace' => 'Areas\\', 'path' => 'src/Areas', 'inferred' => true]];
    }
}
