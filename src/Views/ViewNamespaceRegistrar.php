<?php

namespace Tey\Mod\Views;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\View;
use Illuminate\View\FileViewFinder;
use Tey\Mod\Artifact\ArtifactRequest;
use Tey\Mod\Exceptions\InvalidLayout;
use Tey\Mod\Layout\CompiledLayout;
use Tey\Mod\Layout\LayoutRegistry;
use Tey\Mod\Placement\PlacementResolver;
use Tey\Mod\Support\Path;
use Throwable;

/** @internal Register namespaces after providers boot, independently of route and class discovery. */
final class ViewNamespaceRegistrar
{
    public static function register(Application $app): void
    {
        try {
            $definition = $app->make('config')->get('mod.preset');
            $name = $app->make('config')->get('mod.layout', 'laravel');
            if (! is_string($name) || $name === '') {
                throw InvalidLayout::notNamed();
            }
            // Read only the declared folders, without sealing layouts or scanning generator templates.
            if (is_array($definition)) {
                $data = [];
                foreach ($definition as $key => $value) {
                    if (is_string($key)) {
                        $data[$key] = $value;
                    }
                }
                $layout = CompiledLayout::fromArray($data);
            } else {
                $layout = $app->make(LayoutRegistry::class)->layout($name)->compile();
            }
        } catch (Throwable $exception) {
            if (! $exception instanceof InvalidLayout) {
                throw $exception;
            }

            return;
        }
        if (! $app->bound('view')) {
            return;
        }
        $finder = View::getFinder();
        if (! $finder instanceof FileViewFinder) {
            return;
        }
        $hints = $finder->getHints();
        foreach ((new ViewDirectories($layout, $app->basePath()))->entries() as $entry) {
            $namespace = $entry['namespace'];
            if ($namespace === null) {
                continue;
            }
            if (in_array($namespace, ['mail', 'notifications', 'pagination'], true) || array_key_exists($namespace, $hints)) {
                $app->make('log')->warning('mod views: skipped group ['.$entry['group'].']; view namespace ['.$namespace.'] is already registered or reserved.');

                continue;
            }
            $path = Path::resolve($app->basePath(), $entry['path']);
            View::addNamespace($namespace, $path);
            Blade::anonymousComponentPath(Path::join($path, 'components'), $namespace);
            if ($layout->hasKind('component')) {
                $component = (new PlacementResolver($layout))->resolve(ArtifactRequest::for('component', 'Component', $entry['context']));
                $class = $component->class();
                if ($class !== null) {
                    Blade::componentNamespace($class->namespace, $namespace);
                }
            }
            $hints[$namespace] = [$path];
        }
    }
}
