<?php

namespace Tey\Mod\Discovery;

use Closure;
use Illuminate\Contracts\Foundation\Application;
use ReflectionClass;
use ReflectionNamedType;
use Tey\Mod\Layout\BuiltIn\GeneratorSources;
use Tey\Mod\Scaffolds\Scaffold;
use Tey\Mod\Scaffolds\ScaffoldRegistry;
use Tey\Mod\Support\Path;

/** @internal Convention-only discovery; no new file type or runtime listener. */
final class GroupScaffoldRegistrar
{
    public static function register(Application $app): void
    {
        $registry = $app->make(ScaffoldRegistry::class);
        foreach ($registry->groupDirectories() as $group => $directory) {
            if ($directory['namespace'] === null) {
                continue;
            }
            $folder = Path::join(Path::resolve($app->basePath(), $directory['path']), 'Scaffolds');
            foreach (glob($folder.'/*.php') ?: [] as $file) {
                $class = $directory['namespace'].'\\Scaffolds\\'.basename($file, '.php');
                if (! class_exists($class, false)) {
                    require_once $file;
                }
                if (! class_exists($class, false)) {
                    continue;
                }
                $reflection = new ReflectionClass($class);
                if (! $reflection->isInstantiable() || ! $reflection->hasMethod('__invoke') || ! $reflection->hasProperty('name')) {
                    continue;
                }
                $method = $reflection->getMethod('__invoke');
                $type = ($method->getParameters()[0] ?? null)?->getType();
                if (! $method->isPublic() || ! $type instanceof ReflectionNamedType || $type->getName() !== Scaffold::class) {
                    continue;
                }
                $instance = $app->make($class);
                $property = $reflection->getProperty('name');
                if (! $property->isPublic() || ! $property->isInitialized($instance)) {
                    continue;
                }
                $name = $property->getValue($instance);
                if (is_string($name) && $name !== '' && is_callable($instance)) {
                    $registry->register($name, Closure::fromCallable($instance), GeneratorSources::PREFIX.$group, Path::relative($app->basePath(), $file) ?? $file);
                }
            }
        }
    }
}
