<?php

namespace Tey\Mod\Plans;

use Tey\Mod\Artifact\ResolvedArtifact;
use Tey\Mod\Support\Path;
use Tey\Mod\Views\ViewIdentity;

/** @internal The additive, read-only description shared by every writing command. */
final class Plan
{
    /** @var list<array{alias: string, type: string, path: string, class?: string, identity?: array<string, mixed>, group: ?string, existing: bool, exists: bool}> */
    public array $files = [];

    /** @var list<array{into: string, at: string, stub: string}> */
    public array $inserts = [];

    /** @var list<array{file: ?string, line: ?int, message: string}> */
    public array $warnings = [];

    /** @var list<array{anchor: string, label: string}> Human labels retained by tree planners. */
    public array $insertDetails = [];

    public bool $wouldWrite = true;

    public function __construct(public readonly string $command, public ?string $group = null, public ?string $name = null) {}

    public function artifact(string $alias, ResolvedArtifact $artifact, string $basePath): void
    {
        $group = implode('/', $artifact->context->toArray()) ?: null;
        $exists = is_file(Path::resolve($basePath, $artifact->path()));
        $class = $artifact->fqcn();
        $identity = $class === null ? ['identity' => ['path' => $artifact->path()]] : ['class' => $class];
        if ($artifact->identity instanceof ViewIdentity) {
            $identity = ['identity' => ['path' => $artifact->path(), 'name' => $artifact->identity->name(), 'tag' => $artifact->identity->tag()]];
        }
        $this->files[] = ['alias' => $alias, 'type' => $artifact->kind->id, 'path' => $artifact->path(), ...$identity, 'group' => $group, 'existing' => $exists, 'exists' => $exists];
        $this->group ??= $group;
    }

    /** @param array<string, mixed> $identity */
    public function file(string $alias, string $type, string $path, array $identity, bool $exists = false, ?string $class = null): void
    {
        $this->files[] = ['alias' => $alias, 'type' => $type, 'path' => $path, ...($class === null ? ['identity' => $identity] : ['class' => $class]), 'group' => $this->group, 'existing' => $exists, 'exists' => $exists];
    }

    public function warning(string $message, ?string $file = null, ?int $line = null): void
    {
        $warning = ['file' => $file, 'line' => $line, 'message' => $message];
        if (! in_array($warning, $this->warnings, true)) {
            $this->warnings[] = $warning;
        }
        $this->wouldWrite = false;
    }

    public function collisions(bool $force = false, bool $skipExisting = false): void
    {
        if (! $force && ! $skipExisting && array_filter($this->files, static fn (array $file): bool => $file['exists']) !== []) {
            $this->wouldWrite = false;
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['command' => $this->command, 'group' => $this->group, 'name' => $this->name, 'files' => $this->files, 'inserts' => $this->inserts, 'warnings' => $this->warnings, 'would_write' => $this->wouldWrite];
    }
}
