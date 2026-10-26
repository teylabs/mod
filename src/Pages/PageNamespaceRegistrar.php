<?php

namespace Tey\Mod\Pages;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\View\FileViewFinder;
use Tey\Mod\Discovery\DisabledGroups;

/** @internal Decorate each finder, including Inertia's non-singleton test finder. */
final class PageNamespaceRegistrar
{
    public static function register(Application $app): void
    {
        foreach (['inertia.view-finder', 'inertia.testing.view-finder'] as $binding) {
            $app->afterResolving($binding, function ($finder) use ($app): void {
                if (! $finder instanceof FileViewFinder) {
                    return;
                }
                $directories = $app->make(PageDirectories::class);
                $disabled = DisabledGroups::of($app);
                foreach ($directories->namespaces() as $namespace => $path) {
                    if ($disabled->contains($directories->group($namespace))) {
                        continue;
                    }
                    if (array_key_exists($namespace, $finder->getHints())) {
                        $app->make('log')->warning('mod pages: skipped page namespace ['.$namespace.']; it is already registered.');

                        continue;
                    }
                    $finder->addNamespace($namespace, $path);
                }
            });
        }
    }
}
