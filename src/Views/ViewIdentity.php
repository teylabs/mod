<?php

namespace Tey\Mod\Views;

use Illuminate\Support\Str;
use Tey\Mod\Artifact\ArtifactIdentity;
use Tey\Mod\Exceptions\GenerationRefused;
use Tey\Mod\Layout\CompiledLayout;
use Tey\Mod\Placement\PlacementContext;
use Tey\Mod\Support\Path;

/** @internal View and component values shared with plain-file members. */
final readonly class ViewIdentity implements ArtifactIdentity
{
    public function __construct(public ?string $group, private string $view, private string $file) {}

    public static function namespace(?string $group): ?string
    {
        return $group === null ? null : implode('.', array_map(Str::kebab(...), explode('/', $group)));
    }

    public static function resolve(CompiledLayout $layout, PlacementContext $context, string $name, string $extension = 'blade.php'): self
    {
        $root = $layout->frontend()['views'];
        if ($root === null) {
            throw GenerationRefused::because('This layout has no views folder. Declare ->frontend(views: ...).');
        }
        $name = str_replace(['\\', '/'], '.', $name);
        if (! preg_match('/^[a-zA-Z0-9_-]+(?:\.[a-zA-Z0-9_-]+)*$/D', $name) || ! preg_match('/^[a-zA-Z0-9]+(?:\.[a-zA-Z0-9]+)*$/D', $extension)) {
            throw GenerationRefused::because('Use a dot-separated view name and a file extension without directory separators.');
        }
        foreach ($context->toArray() as $key => $value) {
            $root = str_replace(['{'.$key.'}', '{'.$key.'?}'], [$value, Str::kebab($value)], $root);
        }
        $root = (string) preg_replace('#/?\{\w+\?\}#', '', $root);
        if (str_contains($root, '{')) {
            throw GenerationRefused::because('The views folder needs a group. Pass --in=<group> or prefix the name with Group:.');
        }
        $group = implode('/', $context->only($layout->dimensionNames())->toArray()) ?: null;

        return new self($group, $name, Path::join($root, str_replace('.', '/', $name).'.'.$extension));
    }

    public function name(): string
    {
        $namespace = self::namespace($this->group);

        return ($namespace === null ? '' : $namespace.'::').$this->view;
    }

    public function tag(): string
    {
        $namespace = self::namespace($this->group);
        $name = str_starts_with($this->view, 'components.') ? substr($this->view, 11) : $this->view;

        return 'x-'.($namespace === null ? '' : $namespace.'::').$name;
    }

    public function path(): string
    {
        return $this->file;
    }

    public function equals(ArtifactIdentity $other): bool
    {
        return $other instanceof self && $other->name() === $this->name() && $other->file === $this->file;
    }
}
