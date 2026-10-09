<?php

namespace Tey\Mod\Layout;

use Tey\Mod\Exceptions\InvalidLayout;
use Tey\Mod\Generation\Stub;
use Tey\Mod\Placement\Segment;
use Tey\Mod\Preset\PresetIssue;
use Tey\Mod\Preset\PresetIssueCode;
use Tey\Mod\Preset\PresetValidator;
use Tey\Mod\Relation\RelationMode;
use Tey\Mod\Support\Path;
use Tey\Mod\Templates\TemplateCatalog;

/**
 * Turns a layout's chain into the core preset: infers the placement
 * dimensions from the placeholders, resolves roots and exclusions, and
 * reports every problem against the call that caused it.
 *
 * @internal used by Layout::compile()
 */
final class LayoutCompiler
{
    private const ROOT_PREFIX = '/^([A-Za-z_][A-Za-z0-9_-]*):(.*)$/';

    private const PLACEHOLDER = '/^\{[A-Za-z_][A-Za-z0-9_]*\+?\??\}$/';

    /** @var list<PresetIssue> */
    private array $issues = [];

    public function __construct(private readonly Layout $layout, private readonly ?TemplateCatalog $templates = null) {}

    /**
     * @throws InvalidLayout
     */
    public function compile(): CompiledLayout
    {
        $this->issues = [];
        $chain = $this->layout->toArray();
        if ($chain['errors'] !== []) {
            throw new InvalidLayout($this->layout->name, [], "Layout [{$this->layout->name}] is invalid:\n".implode("\n", $chain['errors']));
        }
        [$types, $roots, $rename] = GroupPath::resolve($this->layout->name, $chain['path'], $chain['kinds'], $chain['roots'], $chain['nesting']);

        $types = $this->templates?->merge($this->layout->name, $chain['path'], $types, $roots) ?? $types;

        $kinds = [];
        $placeholders = [];
        $failed = [];

        foreach ($types as $id => $kind) {
            $compiled = $this->kind($id, $kind->toArray(), $roots);

            if ($compiled === null) {
                $failed[] = $id;

                continue;
            }

            [$kinds[$id], $names] = $compiled;

            foreach ($names as $name) {
                $placeholders[$name][] = $id;
            }
        }

        $this->checkPlaceholderTypos($placeholders);

        [$excluded, $excludedCalls] = $this->excluded($chain['excluded'], $roots);

        $relations = $chain['relations'];
        foreach ($relations as &$relation) {
            if (is_array($relation['scope'])) {
                array_walk_recursive($relation['scope'], static function (&$value) use ($rename): void {
                    if (is_string($value)) {
                        $value = $rename[$value] ?? $value;
                    }
                });
            }
        }
        unset($relation);

        $definition = [
            'commands' => $chain['commands'],
            'roots' => array_map(
                static fn (array $root): array => array_filter($root, static fn (?string $value): bool => $value !== null),
                $roots,
            ),
            'dimensions' => array_keys($placeholders),
            'excluded' => $excluded,
            'kinds' => $kinds,
            'relations' => array_map($this->relation(...), $relations),
            'placement_options' => [],
        ];

        $reported = array_flip(array_map(static fn (PresetIssue $issue): string => $issue->subject, $this->issues));

        foreach ((new PresetValidator)->validate($definition) as $issue) {
            $subject = $this->callFor($issue->subject, $excludedCalls, $definition['dimensions']);

            if (! isset($reported[$subject]) && ! $this->mentionsFailedKind($issue, $failed)) {
                $this->issues[] = new PresetIssue($issue->code, $subject, $issue->message);
            }
        }

        if ($this->issues !== []) {
            throw new InvalidLayout($this->layout->name, $this->issues);
        }

        return CompiledLayout::fromArray($definition)->withTemplates($this->templates?->templates() ?? []);
    }

    /**
     * @param  array{in: ?string, fallback: ?string, root: ?string, name: 'as-given'|'timestamped'|array{suffix: string}|array{fixed: string}|null, file: bool, command: string|false|null, aliases: list<string>, stub: ?Stub, label: ?string, priority: ?int, nested: ?bool, discover: ?string, except: list<string>|null, place: ?\Closure, reads: list<string>}  $kind
     * @param  array<string, array{namespace: ?string, path: string}>  $roots
     * @return array{array<string, mixed>, list<string>}|null the internal kind definition and the placeholders it reads
     */
    private function kind(string $id, array $kind, array $roots): ?array
    {
        $call = "->generates('{$id}')";
        $in = $kind['in'];

        if ($in === null && $kind['place'] === null) {
            $this->issue(PresetIssueCode::InvalidKind, $call, "needs in: (its path below the root, e.g. 'Models'; '' for the root itself)");

            return null;
        }

        $rootName = $kind['root'];
        $path = $in ?? '';

        if (preg_match(self::ROOT_PREFIX, $path, $match) === 1) {
            [, $rootName, $path] = $match;
        }

        $rootName ??= array_key_first($roots);

        if ($rootName === null) {
            $this->issue(PresetIssueCode::UnknownRoot, $call, 'no root is declared; add ->mounts() before compiling');

            return null;
        }

        if (! isset($roots[$rootName])) {
            $this->issue(PresetIssueCode::UnknownRoot, $call, "root [{$rootName}] is not declared (declared: ".implode(', ', array_keys($roots)).')');

            return null;
        }

        $name = $kind['name'] ?? 'as-given';
        $file = $kind['file'] || $name === 'timestamped' || $roots[$rootName]['namespace'] === null;

        $definition = [
            'shape' => $file ? 'file' : 'class',
            'name' => $name,
            'root' => $rootName,
        ];

        if ($kind['command'] !== false) {
            $definition['command'] = $kind['command'] ?? 'mod:'.$id;
        }

        if ($kind['aliases'] !== []) {
            $definition['aliases'] = $kind['aliases'];
        }

        if ($kind['stub'] !== null) {
            $definition['stub'] = $kind['stub'];
        }

        if ($kind['label'] !== null) {
            $definition['label'] = $kind['label'];
        }

        if ($kind['fallback'] !== null) {
            $definition['fallback'] = $kind['fallback'];
        }

        if ($kind['priority'] !== null) {
            $definition['priority'] = $kind['priority'];
        }

        if ($kind['nested'] !== null) {
            $definition['nested'] = $kind['nested'];
        }

        if ($kind['discover'] !== null) {
            $definition['discover'] = $kind['discover'];
            $definition['except'] = $kind['except'] ?? [];
        }

        if ($kind['place'] !== null) {
            $definition['place'] = $kind['place'];
            $definition['dimensions'] = $kind['reads'];

            return [$definition, $kind['reads']];
        }

        $segments = array_values(array_filter(explode('/', trim($path, '/')), static fn (string $segment): bool => $segment !== ''));
        $names = [];

        foreach ($segments as $segment) {
            if (str_contains($segment, '{') || str_contains($segment, '}')) {
                if (preg_match(self::PLACEHOLDER, $segment) !== 1) {
                    $this->issue(PresetIssueCode::InvalidKind, $call, "placeholder [{$segment}] must be a whole folder such as {name}, {name?}, {name+} or {name+?}");

                    return null;
                }

                $placeholder = Segment::parse($segment)->dimension;

                if ($placeholder !== null && ! in_array($placeholder, $names, true)) {
                    $names[] = $placeholder;
                }
            }
        }

        $definition['segments'] = $segments;

        return [$definition, $names];
    }

    /**
     * A placeholder only one kind uses, spelled almost like one other kinds share, is a typo.
     *
     * @param  array<string, list<string>>  $placeholders  placeholder → kinds that use it
     */
    private function checkPlaceholderTypos(array $placeholders): void
    {
        $slots = array_merge([], ...array_column($this->templates?->templates() ?? [], 'slots'));
        foreach ($placeholders as $name => $kinds) {
            if (in_array($name, $slots, true)) {
                continue;
            }
            if (count($kinds) !== 1) {
                continue;
            }

            foreach ($placeholders as $other => $users) {
                if ($other === $name || count($users) < 2) {
                    continue;
                }

                if (strcasecmp($name, $other) === 0 || levenshtein($name, $other) <= 2) {
                    $this->issue(PresetIssueCode::UnknownDimension, "->generates('{$kinds[0]}')", "placeholder {{$name}} is used by no other file type; did you mean {{$other}}?");

                    break;
                }
            }
        }
    }

    /**
     * @param  list<string>  $entries
     * @param  array<string, array{namespace: ?string, path: string}>  $roots
     * @return array{list<array<string, string>>, list<string>} the internal exclusions and the call behind each
     */
    private function excluded(array $entries, array $roots): array
    {
        $excluded = [];
        $calls = [];

        foreach ($entries as $entry) {
            $call = "->excludes('{$entry}')";
            $resolved = str_contains($entry, '\\') ? $this->excludedNamespace($entry, $roots) : $this->excludedPath($entry, $roots);

            if ($resolved === null) {
                $this->issue(PresetIssueCode::UnknownRoot, $call, 'lies inside no declared root');

                continue;
            }

            $excluded[] = $resolved;
            $calls[] = $call;
        }

        return [$excluded, $calls];
    }

    /**
     * @param  array<string, array{namespace: ?string, path: string}>  $roots
     * @return array<string, string>|null
     */
    private function excludedNamespace(string $entry, array $roots): ?array
    {
        $namespace = trim($entry, '\\').'\\';
        $best = null;

        foreach ($roots as $root) {
            if ($root['namespace'] !== null && str_starts_with($namespace, $root['namespace'])
                && ($best === null || strlen($root['namespace']) > strlen((string) $best['namespace']))) {
                $best = $root;
            }
        }

        if ($best === null) {
            return null;
        }

        $remainder = Path::normalize(trim(substr($namespace, strlen((string) $best['namespace'])), '\\'));

        return ['namespace' => $namespace, 'path' => CompiledRoot::normalisePath(Path::join($best['path'], $remainder))];
    }

    /**
     * @param  array<string, array{namespace: ?string, path: string}>  $roots
     * @return array<string, string>
     */
    private function excludedPath(string $entry, array $roots): array
    {
        $path = CompiledRoot::normalisePath($entry);
        $best = null;

        foreach ($roots as $root) {
            $rootPath = CompiledRoot::normalisePath($root['path']);

            if ($root['namespace'] !== null && Path::relative($rootPath, $path) !== null
                && ($best === null || strlen($rootPath) > strlen(CompiledRoot::normalisePath($best['path'])))) {
                $best = $root;
            }
        }

        if ($best === null) {
            return ['path' => $path];
        }

        $remainder = (string) Path::relative(CompiledRoot::normalisePath($best['path']), $path);

        return ['namespace' => $best['namespace'].($remainder === '' ? '' : str_replace('/', '\\', $remainder).'\\'), 'path' => $path];
    }

    /**
     * @param  array{from: ?string, to: ?string, scope: string|list<string>|array{keep?: list<string>, nested?: 'keep'|'drop', name?: string}|null, name: string|array<string, string>|null, mode: string|RelationMode|null}  $relation
     * @return array<string, mixed>
     */
    private function relation(array $relation): array
    {
        $scope = $relation['scope'] ?? 'same';

        if (is_array($scope) && ! isset($scope['keep']) && ! array_key_exists('nested', $scope) && ! array_key_exists('name', $scope)) {
            $scope = ['keep' => array_values($scope)];
        }

        $mode = $relation['mode'] ?? RelationMode::Generate;

        $definition = [
            'from' => $relation['from'],
            'to' => $relation['to'],
            'scope' => $scope,
            'mode' => $mode instanceof RelationMode ? $mode->value : $mode,
        ];

        if ($relation['name'] !== null) {
            $definition['name'] = $relation['name'];
        }

        return $definition;
    }

    /**
     * The chain call behind a core validation subject such as "kinds.model".
     *
     * @param  list<string>  $excludedCalls
     * @param  list<string>  $placeholders
     */
    private function callFor(string $subject, array $excludedCalls, array $placeholders): string
    {
        [$section, $key] = array_pad(explode('.', $subject, 2), 2, '');

        return match ($section) {
            'kinds' => "->generates('{$key}')",
            'relations' => "->relates('{$key}')",
            'roots' => "->mounts('{$key}')",
            'excluded' => $excludedCalls[(int) $key] ?? '->excludes()',
            'dimensions' => 'placeholder {'.($placeholders[(int) $key] ?? $key).'}',
            'commands' => '->withoutCommands()',
            'placement_options' => "->path('app/{{$key}}')",
            default => $subject,
        };
    }

    /**
     * A relation to a kind that already failed is reported once, on the kind.
     *
     * @param  list<string>  $failed
     */
    private function mentionsFailedKind(PresetIssue $issue, array $failed): bool
    {
        if ($issue->code !== PresetIssueCode::UnknownRelationTarget) {
            return false;
        }

        foreach ($failed as $id) {
            if (str_contains($issue->message, "[{$id}]")) {
                return true;
            }
        }

        return false;
    }

    private function issue(PresetIssueCode $code, string $call, string $message): void
    {
        $this->issues[] = new PresetIssue($code, $call, $message);
    }
}
