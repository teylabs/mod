<?php

namespace Tey\Mod\Preset;

use Closure;
use Tey\Mod\Artifact\ArtifactKind;
use Tey\Mod\Artifact\IdentityShape;
use Tey\Mod\Artifact\NamePolicy;
use Tey\Mod\Exceptions\InvalidPreset;
use Tey\Mod\Generation\Stub;
use Tey\Mod\Placement\Dimension;
use Tey\Mod\Placement\OpaquePlacementRule;
use Tey\Mod\Placement\PlacementContext;
use Tey\Mod\Placement\PlacementRule;
use Tey\Mod\Placement\Root;
use Tey\Mod\Placement\Segment;
use Tey\Mod\Placement\TemplateRule;
use Tey\Mod\Relation\NameDerivation;
use Tey\Mod\Relation\Relation;
use Tey\Mod\Relation\RelationMode;
use Tey\Mod\Relation\ScopeMap;

/**
 * Validates and compiles the provisional internal preset definition:
 *
 *  [
 *    'commands'   => true,
 *    'roots'      => ['app' => ['namespace' => 'App\\', 'path' => 'app'], 'migrations' => ['path' => 'database/migrations']],
 *    'dimensions' => ['feature', 'slice'],
 *    'excluded'   => [['namespace' => 'App\\Support\\', 'path' => 'app/Support']],
 *    'kinds'      => ['model' => [
 *        'shape' => 'class'|'file', 'name' => 'as-given'|'timestamped'|['suffix' => 'Controller']|['fixed' => 'Request'],
 *        'command' => 'mod:model', 'root' => 'app', 'segments' => ['Models', '{feature}', '{slice?}'], 'priority' => 0,
 *        'nested' => true,                       // accepts "Billing/Invoice" and keeps the folders below the kind's own
 *        'discover' => 'anywhere', 'except' => ['Tests'],   // discovery widening below the dimension folders
 *        'place' => Closure(string $name, PlacementContext $context): string   // opaque alternative to 'segments'
 *        'aliases' => ['mod:records'], 'stub' => Stub::file(...), 'label' => 'Record',
 *    ]],
 *    'relations'  => ['factory' => [
 *        'from' => 'model', 'to' => 'factory', 'scope' => 'same'|['keep' => ['feature'], 'nested' => 'keep'|'drop'],
 *        'name' => 'explicit'|['strip-suffix' => 'Controller', 'prefix' => 'Store', 'suffix' => 'Request'], 'mode' => 'generate',
 *    ]],
 *    'placement_options' => ['feature' => 'topic'],   // option name per dimension; default: the dimension in kebab-case
 *  ]
 *
 * @internal validates the compiled array definition; define layouts with Mod::layout().
 */
final class PresetValidator
{
    private const NAMESPACE_PATTERN = '/^(?:[A-Za-z_][A-Za-z0-9_]*\\\\)+$/';

    /** @var list<PresetIssue> */
    private array $issues = [];

    /**
     * @param  array<string, mixed>  $definition
     * @return list<PresetIssue>
     */
    public function validate(array $definition): array
    {
        $this->build($definition);

        return $this->issues;
    }

    /**
     * @param  array<string, mixed>  $definition
     *
     * @throws InvalidPreset
     */
    public function compile(array $definition): Preset
    {
        $preset = $this->build($definition);

        if ($this->issues !== [] || $preset === null) {
            throw new InvalidPreset($this->issues);
        }

        return $preset;
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private function build(array $definition): ?Preset
    {
        $this->issues = [];

        $roots = $this->roots($definition['roots'] ?? []);
        $dimensions = $this->dimensions($definition['dimensions'] ?? []);
        $excluded = $this->excluded($definition['excluded'] ?? []);
        $commands = $definition['commands'] ?? true;

        if (! is_bool($commands)) {
            $this->issue(PresetIssueCode::InvalidShape, 'commands', 'must be a boolean');
            $commands = true;
        }

        [$kinds, $rules, $declaredKinds] = $this->kinds($definition['kinds'] ?? [], $roots, $dimensions, $commands);
        $dimensions = $this->multiDimensions($dimensions, $rules);
        $relations = $this->relations($definition['relations'] ?? [], $declaredKinds, $dimensions);
        $placementOptions = $this->placementOptions($definition['placement_options'] ?? [], $dimensions);

        if ($this->issues !== []) {
            return null;
        }

        $stubs = [];

        foreach (is_array($definition['kinds'] ?? null) ? $definition['kinds'] : [] as $id => $entry) {
            if (isset($kinds[$id]) && is_array($entry) && ($entry['stub'] ?? null) instanceof Stub) {
                $stubs[$id] = $entry['stub'];
            }
        }

        return new Preset($roots, array_values($dimensions), $kinds, $rules, $relations, $excluded, $commands, $placementOptions, $stubs);
    }

    /**
     * @return array<string, Root>
     */
    private function roots(mixed $definition): array
    {
        if (! is_array($definition)) {
            $this->issue(PresetIssueCode::InvalidShape, 'roots', 'must be a map of root name to definition');

            return [];
        }

        $roots = [];
        $namespaces = [];
        $paths = [];

        foreach ($definition as $name => $entry) {
            $name = (string) $name;
            $root = $this->root("roots.{$name}", $entry);

            if ($root === null) {
                continue;
            }

            if ($root->namespace !== null) {
                if (isset($namespaces[$root->namespace])) {
                    $this->issue(PresetIssueCode::DuplicateRoot, "roots.{$name}", "namespace [{$root->namespace}] is already declared by root [{$namespaces[$root->namespace]}]");
                }
                $namespaces[$root->namespace] = $name;
            }

            if (isset($paths[$root->path])) {
                $this->issue(PresetIssueCode::DuplicateRoot, "roots.{$name}", "path [{$root->path}] is already declared by root [{$paths[$root->path]}]");
            }
            $paths[$root->path] = $name;

            $roots[$name] = $root;
        }

        return $roots;
    }

    private function root(string $subject, mixed $entry): ?Root
    {
        if (! is_array($entry)) {
            $this->issue(PresetIssueCode::InvalidRoot, $subject, 'must be an array with a path and an optional namespace');

            return null;
        }

        $namespace = $entry['namespace'] ?? null;
        $path = $entry['path'] ?? null;

        if (! is_string($path) || trim($path) === '') {
            $this->issue(PresetIssueCode::InvalidRoot, $subject, 'path is required');

            return null;
        }

        if (str_starts_with($path, '/') || preg_match('/^[A-Za-z]:/', $path) === 1) {
            $this->issue(PresetIssueCode::InvalidRoot, $subject, "path [{$path}] must be relative to the application");

            return null;
        }

        if (in_array('..', explode('/', Root::normalisePath($path)), true)) {
            $this->issue(PresetIssueCode::InvalidRoot, $subject, "path [{$path}] must not leave the application");

            return null;
        }

        if ($namespace === null) {
            return Root::files($path);
        }

        if (! is_string($namespace) || preg_match(self::NAMESPACE_PATTERN, $namespace) !== 1) {
            $this->issue(PresetIssueCode::InvalidRoot, $subject, 'namespace must be a PSR-4 prefix ending with a backslash, e.g. App\\');

            return null;
        }

        return Root::psr4($namespace, $path);
    }

    /**
     * @return array<string, Dimension>
     */
    private function dimensions(mixed $definition): array
    {
        if (! is_array($definition)) {
            $this->issue(PresetIssueCode::InvalidShape, 'dimensions', 'must be a list of dimension names');

            return [];
        }

        $dimensions = [];

        foreach ($definition as $index => $name) {
            if (! is_string($name) || preg_match('/^[a-z][A-Za-z0-9]*$/', $name) !== 1) {
                $this->issue(PresetIssueCode::InvalidDimension, "dimensions.{$index}", 'must be a camelCase identifier');

                continue;
            }

            if (isset($dimensions[$name])) {
                $this->issue(PresetIssueCode::InvalidDimension, "dimensions.{$index}", "[{$name}] is declared twice");

                continue;
            }

            $dimensions[$name] = new Dimension($name);
        }

        return $dimensions;
    }

    /**
     * @return list<Root>
     */
    private function excluded(mixed $definition): array
    {
        if (! is_array($definition)) {
            $this->issue(PresetIssueCode::InvalidShape, 'excluded', 'must be a list of roots');

            return [];
        }

        $roots = [];

        foreach ($definition as $index => $entry) {
            $root = $this->root("excluded.{$index}", $entry);

            if ($root !== null) {
                $roots[] = $root;
            }
        }

        return $roots;
    }

    /**
     * @param  array<string, Root>  $roots
     * @param  array<string, Dimension>  $dimensions
     * @param  bool  $commandsEnabled  when false (a host dispatches its own commands) kinds may share a command name
     * @return array{array<string, ArtifactKind>, array<string, PlacementRule>, list<string>}
     */
    private function kinds(mixed $definition, array $roots, array $dimensions, bool $commandsEnabled = true): array
    {
        if (! is_array($definition)) {
            $this->issue(PresetIssueCode::InvalidShape, 'kinds', 'must be a map of file type id to definition');

            return [[], [], []];
        }

        $kinds = [];
        $rules = [];
        $seen = [];
        $commands = [];
        $patterns = [];

        foreach ($definition as $key => $entry) {
            $subject = 'kinds.'.$key;

            if (! is_array($entry)) {
                $this->issue(PresetIssueCode::InvalidKind, $subject, 'must be an array');

                continue;
            }

            $id = $entry['id'] ?? $key;

            if (! is_string($id) || preg_match('/^[a-z][a-z0-9-]*$/', $id) !== 1) {
                $this->issue(PresetIssueCode::InvalidKind, $subject, 'id must be a lowercase identifier such as "model" or "view-model"');

                continue;
            }

            if (isset($seen[$id])) {
                $this->issue(PresetIssueCode::DuplicateKind, $subject, "file type [{$id}] is already declared by [{$seen[$id]}]");

                continue;
            }
            $seen[$id] = $subject;

            $shapeValue = $entry['shape'] ?? null;
            $shape = is_string($shapeValue) ? IdentityShape::tryFrom($shapeValue) : null;

            if ($shape === null) {
                $this->issue(PresetIssueCode::InvalidKind, $subject, 'shape must be "class" or "file"');

                continue;
            }

            $policy = $this->namePolicy($subject, $entry['name'] ?? 'as-given');

            if ($policy === null) {
                continue;
            }

            $command = $entry['command'] ?? null;

            if ($command !== null) {
                if (! is_string($command) || $command === '') {
                    $this->issue(PresetIssueCode::InvalidKind, $subject, 'command must be a non-empty string');

                    continue;
                }

                if ($commandsEnabled && isset($commands[$command])) {
                    $this->issue(PresetIssueCode::DuplicateCommandName, $subject, "command [{$command}] is already used by file type [{$commands[$command]}]");

                    continue;
                }
                $commands[$command] = $id;
            }

            $aliases = $entry['aliases'] ?? [];

            if (! is_array($aliases) || ! array_is_list($aliases) || array_filter($aliases, static fn (mixed $alias): bool => ! is_string($alias) || $alias === '') !== []) {
                $this->issue(PresetIssueCode::InvalidKind, $subject, 'aliases must be a list of command names');

                continue;
            }

            if ($aliases !== [] && $command === null) {
                $this->issue(PresetIssueCode::InvalidKind, $subject, 'aliases need a command to stand for');

                continue;
            }

            $names = [];

            foreach ($aliases as $alias) {
                if (! is_string($alias)) {
                    continue;
                }

                $names[] = $alias;

                if ($commandsEnabled && isset($commands[$alias])) {
                    $this->issue(PresetIssueCode::DuplicateCommandName, $subject, "alias [{$alias}] is already used by file type [{$commands[$alias]}]");

                    continue 2;
                }
                $commands[$alias] = $id;
            }

            if (isset($entry['stub']) && ! $entry['stub'] instanceof Stub) {
                $this->issue(PresetIssueCode::InvalidKind, $subject, 'stub must be a '.Stub::class);

                continue;
            }

            $label = $entry['label'] ?? null;

            if ($label !== null && (! is_string($label) || trim($label) === '')) {
                $this->issue(PresetIssueCode::InvalidKind, $subject, 'label must be a non-empty string');

                continue;
            }

            $rootName = $entry['root'] ?? null;

            if (! is_string($rootName) || ! isset($roots[$rootName])) {
                $this->issue(PresetIssueCode::UnknownRoot, $subject, 'root ['.(is_scalar($rootName) ? (string) $rootName : '').'] is not declared');

                continue;
            }

            $root = $roots[$rootName];

            if ($shape === IdentityShape::PhpClass && ! $root->isClassRoot()) {
                $this->issue(PresetIssueCode::InvalidKind, $subject, "class artifacts need a namespaced root; [{$rootName}] has no namespace");

                continue;
            }

            $priority = $entry['priority'] ?? 0;

            if (! is_int($priority)) {
                $this->issue(PresetIssueCode::InvalidKind, $subject, 'priority must be an integer');

                continue;
            }

            $nested = $entry['nested'] ?? false;

            if (! is_bool($nested)) {
                $this->issue(PresetIssueCode::InvalidKind, $subject, 'nested must be a boolean');

                continue;
            }

            $discover = $entry['discover'] ?? null;

            if ($discover !== null && $discover !== 'anywhere') {
                $this->issue(PresetIssueCode::InvalidKind, $subject, 'discover must be "anywhere" when given');

                continue;
            }

            $except = $this->except($subject, $entry['except'] ?? []);

            if ($except === null) {
                continue;
            }

            if ($discover === null && $except !== []) {
                $this->issue(PresetIssueCode::InvalidKind, $subject, 'except needs discover: "anywhere"');

                continue;
            }

            $rule = $this->rule($subject, $id, $root, $priority, $entry, $dimensions, $nested, $discover === 'anywhere', $except);

            if ($rule === null) {
                continue;
            }

            if ($rule instanceof TemplateRule) {
                foreach ($rule->variants() as $variant) {
                    $pattern = $variant->pattern().'|'.$policy->describe().'|'.$priority;

                    if (isset($patterns[$pattern])) {
                        $this->issue(PresetIssueCode::DuplicatePlacementPattern, $subject, "places exactly like file type [{$patterns[$pattern]}] with the same priority; reverse mapping could never tell them apart");

                        continue 2;
                    }
                    $patterns[$pattern] = $id;
                }
            }

            $kinds[$id] = new ArtifactKind($id, $shape, $policy, $command, $names, $label);
            $rules[$id] = $rule;
        }

        // Relations are checked against every declared id so an invalid kind is reported once, not once per relation.
        return [$kinds, $rules, array_keys($seen)];
    }

    private function namePolicy(string $subject, mixed $definition): ?NamePolicy
    {
        if ($definition === 'as-given') {
            return NamePolicy::asGiven();
        }

        if ($definition === 'timestamped') {
            return NamePolicy::timestamped();
        }

        if (is_array($definition) && count($definition) === 1) {
            $value = reset($definition);
            $type = key($definition);

            if (is_string($value) && $value !== '') {
                if ($type === 'suffix') {
                    return NamePolicy::suffix($value);
                }

                if ($type === 'fixed') {
                    return NamePolicy::fixed($value);
                }
            }
        }

        $this->issue(PresetIssueCode::InvalidKind, $subject, 'name must be "as-given", "timestamped", [\'suffix\' => ...] or [\'fixed\' => ...]');

        return null;
    }

    /**
     * @param  array<array-key, mixed>  $entry
     * @param  array<string, Dimension>  $dimensions
     * @param  list<string>  $except
     */
    private function rule(string $subject, string $id, Root $root, int $priority, array $entry, array $dimensions, bool $nested = false, bool $anywhere = false, array $except = []): ?PlacementRule
    {
        $place = $entry['place'] ?? null;

        if ($place !== null) {
            if (isset($entry['fallback'])) {
                $this->issue(PresetIssueCode::InvalidFallback, $subject, 'fallback needs a declarative placement, not a callback');

                return null;
            }

            if (! $place instanceof Closure) {
                $this->issue(PresetIssueCode::InvalidKind, $subject, 'place must be a Closure');

                return null;
            }

            $reads = $entry['dimensions'] ?? [];

            if (! is_array($reads)) {
                $this->issue(PresetIssueCode::InvalidKind, $subject, 'dimensions must be a list of dimension names');

                return null;
            }

            $names = [];

            foreach ($reads as $name) {
                if (! is_string($name) || ! isset($dimensions[$name])) {
                    $this->issue(PresetIssueCode::UnknownDimension, $subject, 'dimension ['.(is_scalar($name) ? (string) $name : '').'] is not declared');

                    return null;
                }
                $names[] = $name;
            }

            if ($anywhere) {
                $this->issue(PresetIssueCode::InvalidKind, $subject, 'discover: "anywhere" needs a declarative placement (segments), not a callback');

                return null;
            }

            /** @var Closure(string, PlacementContext): string $place */
            return new OpaquePlacementRule($id, $root, $place, $names, $priority, $nested);
        }

        $segments = $entry['segments'] ?? [];

        if (! is_array($segments)) {
            $this->issue(PresetIssueCode::InvalidKind, $subject, 'segments must be a list');

            return null;
        }

        $parsed = [];

        foreach ($segments as $spec) {
            if (! is_string($spec) || $spec === '') {
                $this->issue(PresetIssueCode::InvalidKind, $subject, 'segments must be non-empty strings');

                return null;
            }

            $segment = Segment::parse($spec);

            if ($segment->dimension !== null && ! isset($dimensions[$segment->dimension])) {
                $this->issue(PresetIssueCode::UnknownDimension, $subject, "dimension [{$segment->dimension}] is not declared");

                return null;
            }

            if ($segment->literal !== null && preg_match('/^[A-Za-z_][A-Za-z0-9_.-]*$/', $segment->literal) !== 1) {
                $this->issue(PresetIssueCode::InvalidKind, $subject, "segment [{$segment->literal}] is not a valid folder name");

                return null;
            }

            $parsed[] = $segment;
        }

        $fallback = null;

        if (isset($entry['fallback'])) {
            $path = $entry['fallback'];

            if (! is_string($path) || ($path !== '' && preg_match('#^[A-Za-z_][A-Za-z0-9_.-]*(/[A-Za-z_][A-Za-z0-9_.-]*)*$#', $path) !== 1)) {
                $this->issue(PresetIssueCode::InvalidFallback, $subject, 'fallback must be a relative folder path under the same root, without placeholders');

                return null;
            }

            $folders = $path === '' ? [] : array_map(Segment::parse(...), explode('/', $path));
            $fallback = new TemplateRule($id, $root, $folders, $priority - 1, $nested);
        }

        return new TemplateRule($id, $root, $parsed, $priority, $nested, $anywhere, $except, $fallback);
    }

    /**
     * @return list<string>|null
     */
    private function except(string $subject, mixed $definition): ?array
    {
        if (! is_array($definition)) {
            $this->issue(PresetIssueCode::InvalidKind, $subject, 'except must be a list of folders');

            return null;
        }

        $folders = [];

        foreach ($definition as $folder) {
            if (! is_string($folder) || preg_match('#^[A-Za-z_][A-Za-z0-9_.-]*(/[A-Za-z_][A-Za-z0-9_.-]*)*$#', $folder) !== 1) {
                $this->issue(PresetIssueCode::InvalidKind, $subject, 'except folders must be relative folder paths such as "Tests" or "Database/Migrations"');

                return null;
            }

            $folders[] = $folder;
        }

        return $folders;
    }

    /**
     * The placement options of a kind that would shadow an option the
     * generating command already defines (its native options and their
     * shortcuts, and mod's own --in). The command registers without them;
     * the layout should rename them with ->placementOption().
     *
     * @param  list<string>  $taken  option names and shortcuts the command defines
     * @return array<string, PresetIssue> dimension name → issue
     */
    public function placementOptionCollisions(Preset $preset, ArtifactKind $kind, array $taken): array
    {
        $issues = [];
        $options = $preset->placementOptions();
        $command = $kind->command ?? $kind->id;

        foreach ($preset->rule($kind->id)->dimensions() as $dimension) {
            $option = $options[$dimension] ?? null;

            if ($option === null || ! in_array($option, $taken, true)) {
                continue;
            }

            $issues[$dimension] = new PresetIssue(
                PresetIssueCode::PlacementOptionCollision,
                $command,
                "placeholder {{$dimension}} would add --{$option}, which {$command} already defines. It is left out; use --in or the \"Group:Name\" prefix, or rename it with ->placementOption('...', '{{$dimension}}').",
            );
        }

        return $issues;
    }

    /**
     * The command option of every dimension: the declared override, else the dimension in kebab-case.
     *
     * @param  array<string, Dimension>  $dimensions
     * @return array<string, string> dimension → option name
     */
    private function placementOptions(mixed $definition, array $dimensions): array
    {
        if (! is_array($definition)) {
            $this->issue(PresetIssueCode::InvalidShape, 'placement_options', 'must be a map of dimension name to option name');

            return [];
        }

        foreach ($definition as $name => $option) {
            if (! is_string($name) || ! isset($dimensions[$name])) {
                $this->issue(PresetIssueCode::UnknownDimension, 'placement_options.'.$name, 'names no declared dimension');

                continue;
            }

            if (! is_string($option) || preg_match('/^[a-z][a-z0-9]*(?:-[a-z0-9]+)*$/', $option) !== 1) {
                $this->issue(PresetIssueCode::InvalidDimension, "placement_options.{$name}", 'option must be a lowercase name such as "area" or "sub-area"');
            }
        }

        $options = [];
        $owners = [];

        foreach ($dimensions as $name => $dimension) {
            $option = $definition[$name] ?? strtolower((string) preg_replace('/(?<!^)[A-Z]/', '-$0', $name));

            if (! is_string($option)) {
                continue;
            }

            if (isset($owners[$option])) {
                $this->issue(PresetIssueCode::InvalidDimension, "placement_options.{$name}", "--{$option} is already the option of {{$owners[$option]}}; pick another name for {{$name}}");

                continue;
            }

            $owners[$option] = $name;
            $options[$name] = $option;
        }

        return $options;
    }

    /**
     * A dimension is multi-segment when a rule reads it as `{name+}`; every rule must then agree.
     *
     * @param  array<string, Dimension>  $dimensions
     * @param  array<string, PlacementRule>  $rules
     * @return array<string, Dimension>
     */
    private function multiDimensions(array $dimensions, array $rules): array
    {
        $multi = [];
        $single = [];

        foreach ($rules as $kindId => $rule) {
            if (! $rule instanceof TemplateRule) {
                continue;
            }

            foreach ($rule->segments() as $segment) {
                if ($segment->dimension === null) {
                    continue;
                }

                if ($segment->multi) {
                    $multi[$segment->dimension][] = $kindId;
                } else {
                    $single[$segment->dimension][] = $kindId;
                }
            }
        }

        foreach ($multi as $name => $kinds) {
            if (isset($single[$name])) {
                $this->issue(PresetIssueCode::InvalidDimension, "dimensions.{$name}", sprintf(
                    'is multi-segment ({%s+}) in file type [%s] but single in file type [%s]; use one form everywhere',
                    $name,
                    $kinds[0],
                    $single[$name][0],
                ));

                continue;
            }

            if (isset($dimensions[$name])) {
                $dimensions[$name] = new Dimension($name, true);
            }
        }

        return $dimensions;
    }

    /**
     * @param  list<string>  $declaredKinds
     * @param  array<string, Dimension>  $dimensions
     * @return array<string, Relation>
     */
    private function relations(mixed $definition, array $declaredKinds, array $dimensions): array
    {
        if (! is_array($definition)) {
            $this->issue(PresetIssueCode::InvalidShape, 'relations', 'must be a map of relation id to definition');

            return [];
        }

        $relations = [];

        foreach ($definition as $key => $entry) {
            $id = (string) $key;
            $subject = "relations.{$id}";

            if (! is_array($entry)) {
                $this->issue(PresetIssueCode::InvalidRelation, $subject, 'must be an array');

                continue;
            }

            $from = $entry['from'] ?? null;
            $to = $entry['to'] ?? null;
            $valid = true;

            foreach (['from' => $from, 'to' => $to] as $end => $kindId) {
                if (! is_string($kindId) || ! in_array($kindId, $declaredKinds, true)) {
                    $this->issue(PresetIssueCode::UnknownRelationTarget, $subject, "{$end} file type [".(is_scalar($kindId) ? (string) $kindId : '').'] is not declared');
                    $valid = false;
                }
            }

            $modeValue = $entry['mode'] ?? null;
            $mode = is_string($modeValue) ? RelationMode::tryFrom($modeValue) : null;

            $scope = $this->scope($subject, $entry['scope'] ?? 'same', $dimensions);
            $name = $this->nameDerivation($subject, $entry['name'] ?? null);

            if ($mode === null) {
                $this->issue(PresetIssueCode::InvalidRelation, $subject, 'mode must be "generate", "reference" or "none"');

                continue;
            }

            if (! $valid || $scope === null || $name === null || ! is_string($from) || ! is_string($to)) {
                continue;
            }

            $relations[$id] = new Relation($id, $from, $to, $scope, $name, $mode);
        }

        return $relations;
    }

    /**
     * @param  array<string, Dimension>  $dimensions
     */
    private function scope(string $subject, mixed $definition, array $dimensions): ?ScopeMap
    {
        if ($definition === 'same') {
            return ScopeMap::same();
        }

        if (is_array($definition) && (is_array($definition['keep'] ?? null) || array_key_exists('nested', $definition) || array_key_exists('name', $definition))) {
            $nestedValue = $definition['nested'] ?? 'keep';

            if (! in_array($nestedValue, ['keep', 'drop'], true)) {
                $this->issue(PresetIssueCode::InvalidRelation, $subject, 'scope nested must be "keep" or "drop"');

                return null;
            }

            $nameDimension = $definition['name'] ?? null;

            if ($nameDimension !== null && (! is_string($nameDimension) || ! isset($dimensions[$nameDimension]))) {
                $this->issue(PresetIssueCode::UnknownDimension, $subject, 'scope name must identify a declared dimension');

                return null;
            }

            $keepNested = $nestedValue === 'keep';

            if (! isset($definition['keep'])) {
                return ScopeMap::same($keepNested, $nameDimension);
            }

            $keep = [];

            foreach ((array) $definition['keep'] as $name) {
                if (! is_string($name) || ! isset($dimensions[$name])) {
                    $this->issue(PresetIssueCode::UnknownDimension, $subject, 'scope keeps dimension ['.(is_scalar($name) ? (string) $name : '').'] which is not declared');

                    return null;
                }
                $keep[] = $name;
            }

            return ScopeMap::keep($keep, $keepNested, $nameDimension);
        }

        $this->issue(PresetIssueCode::InvalidRelation, $subject, 'scope must be "same" or [\'keep\' => [...], \'nested\' => \'keep\'|\'drop\']');

        return null;
    }

    private function nameDerivation(string $subject, mixed $definition): ?NameDerivation
    {
        if ($definition === 'explicit') {
            return NameDerivation::explicit();
        }

        if ($definition === null) {
            return NameDerivation::fromSource();
        }

        if (is_array($definition)) {
            $parts = [];

            foreach (['strip-suffix', 'prefix', 'suffix'] as $option) {
                $value = $definition[$option] ?? null;

                if ($value !== null && (! is_string($value) || $value === '')) {
                    $this->issue(PresetIssueCode::InvalidRelation, $subject, "name.{$option} must be a non-empty string");

                    return null;
                }

                $parts[$option] = $value;
            }

            return NameDerivation::fromSource($parts['strip-suffix'], $parts['prefix'], $parts['suffix']);
        }

        $this->issue(PresetIssueCode::InvalidRelation, $subject, 'name must be "explicit" or a map of strip-suffix/prefix/suffix');

        return null;
    }

    private function issue(PresetIssueCode $code, string $subject, string $message): void
    {
        $this->issues[] = new PresetIssue($code, $subject, $message);
    }
}
