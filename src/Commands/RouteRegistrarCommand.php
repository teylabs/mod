<?php

namespace Tey\Mod\Commands;

use Tey\Mod\Layout\BuiltIn\RouteContent;
use Tey\Mod\Routing\RoutePaths;

final class RouteRegistrarCommand extends RouteFilesCommand
{
    protected $signature = RouteContent::REGISTRAR_SIGNATURE;

    protected $description = RouteContent::REGISTRAR_DESCRIPTION;

    protected function files(RoutePaths $paths, string $group): array
    {
        $registrar = $paths->registrar($group);
        $position = (int) strrpos($registrar['class'], '\\');
        $source = str_replace(['{{ namespace }}', '{{ class }}'], [substr($registrar['class'], 0, $position), substr($registrar['class'], $position + 1)], (string) file_get_contents(__DIR__.'/../../resources/routes/registrar.php.stub'));

        return [['path' => $registrar['path'], 'source' => $source, 'type' => 'route-registrar', 'class' => $registrar['class']]];
    }
}
