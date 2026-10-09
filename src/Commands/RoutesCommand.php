<?php

namespace Tey\Mod\Commands;

use Tey\Mod\Layout\BuiltIn\RouteContent;
use Tey\Mod\Routing\RoutePaths;

final class RoutesCommand extends RouteFilesCommand
{
    protected $signature = RouteContent::FILES_SIGNATURE;

    protected $description = RouteContent::FILES_DESCRIPTION;

    protected function files(RoutePaths $paths, string $group): array
    {
        $groups = ['web'];
        foreach (['api', 'console'] as $method) {
            if ($this->option($method)) {
                $groups[] = $method;
            }
        }

        return array_map(static fn (string $method): array => [
            'path' => $paths->directory($group).'/'.$method.'.php',
            'source' => (string) file_get_contents(__DIR__.'/../../resources/routes/'.$method.'.php.stub'),
            'type' => 'routes.'.$method,
            'class' => null,
        ], $groups);
    }
}
