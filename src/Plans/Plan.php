<?php

namespace Tey\Mod\Plans;

use Tey\Mod\Artifact\ResolvedArtifact;
use Tey\Mod\Generation\PlainFile\Identity;
use Tey\Mod\Layout\CompiledLayout;
use Tey\Mod\Support\Path;
use Tey\Mod\Views\ViewIdentity;

/** @internal The additive, read-only description shared by every writing command. */
final class Plan
{
    /** @var list<array{alias: string, type: string, path: string, class?: string, identity?: array<string, mixed>, group: ?string, existing: bool|string, exists: bool, state?: 'unchanged'|'edited'}> */
    public array $files = [];

    /** @var list<array{alias: string, type: string, path: string}> */
    public array $folders = [];

    /** @var list<array{alias: string, type: string, from: string, to: string, old_class: ?string, new_class: ?string}> */
    public array $folderContents = [];

    /** @var list<array{into: string, at: string, stub: string}> */
    public array $inserts = [];

    /** @var list<array{code: string, data: object, file: ?string, line: ?int, message: string}> */
    public array $warnings = [];

    /** @var list<array{alias: string, type: string, reason: string}>|null Members and inserts a scaffold left out; null when the recipe has no `when:` */
    public ?array $skipped = null;

    /** @var list<array{anchor: string, label: string}> Human labels retained by tree planners. */
    public array $insertDetails = [];

    /** @var list<array{name: string, line: int}> */
    public array $mentions = [];

    /**
     * @var array{selection: array{scaffold: ?string, source: ?string, answers: array<string, mixed>}, target: array{group: ?string, name: ?string}, moves: list<array{alias: string, type: string, from: string, to: string, old_class: ?string, new_class: ?string}>, rewrites: list<array{file: string, after_file: string, line: int, category: string, before: string, after: string}>, retained: list<array{alias: string, path: string, reason: string}>, checklist: list<array{file: string, after_file: string, line: int, category: string, message: string, suggestion: ?string}>, scan_roots: list<string>}
     */
    public array $rename = ['selection' => ['scaffold' => null, 'source' => null, 'answers' => []], 'target' => ['group' => null, 'name' => null], 'moves' => [], 'rewrites' => [], 'retained' => [], 'checklist' => [], 'scan_roots' => []];

    public bool $wouldWrite = true;

    public ?string $notice = null;

    /** @var null|array{journal: string, phase: string, operations: list<string>, checkpoint: string} */
    public ?array $recovery = null;

    public function __construct(public readonly string $command, public ?string $group = null, public ?string $name = null) {}

    /** @return list<array{alias: string, type: string, from: string, to: string, old_class: ?string, new_class: ?string}> */
    public function fileMoves(): array
    {
        return [...array_values(array_filter($this->rename['moves'], static fn (array $move): bool => $move['type'] !== 'folder')), ...$this->folderContents];
    }

    public function artifact(string $alias, ResolvedArtifact $artifact, string $basePath, ?string $existing = null, ?string $state = null): void
    {
        $group = implode('/', $artifact->context->only(app(CompiledLayout::class)->dimensionNames())->toArray()) ?: null;
        $exists = is_file(Path::resolve($basePath, $artifact->path()));
        $class = $artifact->fqcn();
        $identity = $class === null ? ['identity' => Identity::forms($artifact, app(CompiledLayout::class))] : ['class' => $class];
        if ($artifact->identity instanceof ViewIdentity) {
            $identity = ['identity' => ['path' => $artifact->path(), 'name' => $artifact->identity->name(), 'tag' => $artifact->identity->tag()]];
        }
        /** @var array{alias: string, type: string, path: string, class?: string, identity?: array<string, mixed>, group: ?string, existing: bool|string, exists: bool, state?: 'unchanged'|'edited'} $file */
        $file = ['alias' => $alias, 'type' => $artifact->kind->id, 'path' => $artifact->path(), ...$identity, 'group' => $group, 'existing' => $existing ?? $exists, 'exists' => $exists];
        if ($exists && $state !== null) {
            /** @var 'unchanged'|'edited' $state */
            $file['state'] = $state;
        }
        $this->files[] = $file;
        $this->group ??= $group;
    }

    /** @param array<string, mixed> $identity */
    public function file(string $alias, string $type, string $path, array $identity, bool $exists = false, ?string $class = null, ?string $state = null): void
    {
        /** @var array{alias: string, type: string, path: string, class?: string, identity?: array<string, mixed>, group: ?string, existing: bool|string, exists: bool, state?: 'unchanged'|'edited'} $file */
        $file = ['alias' => $alias, 'type' => $type, 'path' => $path, ...($class === null ? ['identity' => $identity] : ['class' => $class]), 'group' => $this->group, 'existing' => $exists, 'exists' => $exists];
        if ($exists && $state !== null) {
            /** @var 'unchanged'|'edited' $state */
            $file['state'] = $state;
        }
        $this->files[] = $file;
    }

    /** @param array<string, mixed> $data */
    public function warning(string $message, bool $blocking = true, ?string $file = null, ?int $line = null, ?string $code = null, array $data = []): void
    {
        $warning = ['code' => $code ?? ($blocking ? 'generation_refused' : 'advisory'), 'data' => (object) $data, 'file' => $file, 'line' => $line, 'message' => $message];
        if (! in_array($warning, $this->warnings)) {
            $this->warnings[] = $warning;
        }
        if ($blocking) {
            $this->wouldWrite = false;
        }
    }

    public function collisions(bool $force = false, bool $skipExisting = false): void
    {
        if (! $force && ! $skipExisting && array_filter($this->files, static fn (array $file): bool => $file['exists'] && $file['existing'] !== 'keep') !== []) {
            $this->wouldWrite = false;
        }
    }

    /** @return array<string, mixed> */
    private function renameFields(): array
    {
        $fields = $this->rename;
        if ($fields['selection']['answers'] === []) {
            $fields['selection']['answers'] = (object) [];
        }

        return $fields;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['command' => $this->command, 'group' => $this->group, 'name' => $this->name, 'files' => $this->files, 'inserts' => $this->inserts, 'warnings' => $this->warnings, 'would_write' => $this->wouldWrite, ...($this->folders === [] ? [] : ['folders' => $this->folders]), ...($this->folderContents === [] ? [] : ['folder_contents' => $this->folderContents]), ...($this->skipped === null ? [] : ['skipped' => $this->skipped]), ...($this->command === 'mod:rename' ? $this->renameFields() : []), ...($this->recovery === null ? [] : ['recovery' => $this->recovery]), ...($this->mentions === [] ? [] : ['mentions' => $this->mentions])];
    }
}
