<?php

namespace Tey\Mod\Routing;

use Tey\Mod\Artifact\ArtifactRequest;
use Tey\Mod\Exceptions\GenerationRefused;
use Tey\Mod\Layout\CompiledLayout;
use Tey\Mod\Placement\PlacementContext;
use Tey\Mod\Placement\PlacementResolver;
use Tey\Mod\Support\Path;

/** @internal Routes use the layout's plain-file root and application HTTP placement. */
final readonly class RoutePaths
{
    public function __construct(private CompiledLayout $layout) {}

    public function directory(string $group): string
    {
        $path = $this->layout->roots()['routes']->path ?? null;
        if ($path === null) {
            throw GenerationRefused::because('mod:routes needs a routes root. Add ->mounts(\'routes\', null, …) to the layout.');
        }
        $context = PlacementContext::fromOption($group, $this->layout);
        foreach ($context->toArray() as $name => $value) {
            $path = str_replace(['{'.$name.'}', '{'.$name.'+}', '{'.$name.'?}'], $value, $path);
        }
        if (str_contains($path, '{')) {
            throw GenerationRefused::because('mod:routes needs a complete group name. Pass every layout group.');
        }

        return Path::normalize($path);
    }

    /** @return array{path: string, class: string} */
    public function registrar(string $group): array
    {
        $context = PlacementContext::fromOption($group, $this->layout);
        $values = array_values($context->toArray());
        $name = $values === [] ? 'App' : basename((string) end($values));
        $artifact = (new PlacementResolver($this->layout))->resolve(ArtifactRequest::for('controller', $name, $context));
        $class = $artifact->fqcn() ?? throw GenerationRefused::because('mod:route-registrar needs a namespaced controller placement.');
        $namespace = substr($class, 0, (int) strrpos($class, '\\'));
        $namespace = substr($namespace, 0, (int) strrpos($namespace, '\\')).'\\Routing';

        return ['path' => Path::join(dirname(dirname($artifact->path())), 'Routing', $name.'Routes.php'), 'class' => $namespace.'\\'.$name.'Routes'];
    }

    public function registrarDirectoryTemplate(): ?string
    {
        if (array_diff($this->layout->dimensionNames(), $this->layout->rule('controller')->dimensions()) !== []) {
            return null;
        }
        $values = [];
        $replace = [];
        foreach ($this->layout->dimensions() as $index => $dimension) {
            $value = 'RouteGroup'.$index;
            $values[] = $value;
            $replace[$value] = '{'.$dimension->name.($dimension->multi ? '+' : '').'}';
        }

        return strtr(dirname($this->registrar(implode('/', $values))['path']), $replace);
    }
}
