<?php

namespace Tey\Mod\Preset;

use Closure;
use Tey\Mod\Artifact\ArtifactKind;
use Tey\Mod\Artifact\IdentityShape;
use Tey\Mod\Artifact\NamePolicy;
use Tey\Mod\Exceptions\InvalidPreset;
use Tey\Mod\Placement\Dimension;
use Tey\Mod\Placement\OpaquePlacementRule;
use Tey\Mod\Placement\PlacementContext;
use Tey\Mod\Placement\PlacementRule;
use Tey\Mod\Placement\Root;
use Tey\Mod\Placement\Segment;
use Tey\Mod\Placement\TemplateRule;
use Tey\Mod\Relation\NameDerivation;
use Tey\Mod\Relation\Relation;
use Tey\Mod\Relation\RelationPolicy;
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
 *    ]],
 *    'relations'  => ['factory' => [
 *        'from' => 'model', 'to' => 'factory', 'scope' => 'same'|['keep' => ['feature'], 'nested' => 'keep'|'drop'],
 *        'name' => 'explicit'|['strip-suffix' => 'Controller', 'prefix' => 'Store', 'suffix' => 'Request'], 'policy' => 'generate',
 *    ]],
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

        if ($this->issues !== []) {
            return null;
        }

        return new Preset($roots, array_values($dimensions), $kinds, $rules, $relations, $excluded, $commands);
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
            $this->issue(PresetIssueCode::InvalidShape, 'kinds', 'must be a map of kind id to definition');

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
                $this->issue(PresetIssueCode::DuplicateKind, $subject, "kind [{$id}] is already declared by [{$seen[$id]}]");

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
                    $this->issue(PresetIssueCode::DuplicateCommandName, $subject, "command [{$command}] is already used by kind [{$commands[$command]}]");

                    continue;
                }
                $commands[$command] = $id;
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
                $pattern = $rule->pattern().'|'.$policy->describe().'|'.$priority;

                if (isset($patterns[$pattern])) {
                    $this->issue(PresetIssueCode::DuplicatePlacementPattern, $subject, "places exactly like kind [{$patterns[$pattern]}] with the same priority; reverse mapping could never tell them apart");

                    continue;
                }
                $patterns[$pattern] = $id;
            }

            $kinds[$id] = new ArtifactKind($id, $shape, $policy, $command);
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

        return new TemplateRule($id, $root, $parsed, $priority, $nested, $anywhere, $except);
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
                    'is multi-segment ({%s+}) in kind [%s] but single in kind [%s]; use one form everywhere',
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
                    $this->issue(PresetIssueCode::UnknownRelationTarget, $subject, "{$end} kind [".(is_scalar($kindId) ? (string) $kindId : '').'] is not declared');
                    $valid = false;
                }
            }

            $policyValue = $entry['policy'] ?? null;
            $policy = is_string($policyValue) ? RelationPolicy::tryFrom($policyValue) : null;

            $scope = $this->scope($subject, $entry['scope'] ?? 'same', $dimensions);
            $name = $this->nameDerivation($subject, $entry['name'] ?? null);

            if ($policy === null) {
                $this->issue(PresetIssueCode::InvalidRelation, $subject, 'policy must be "generate", "reference" or "none"');

                continue;
            }

            if (! $valid || $scope === null || $name === null || ! is_string($from) || ! is_string($to)) {
                continue;
            }

            $relations[$id] = new Relation($id, $from, $to, $scope, $name, $policy);
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

        if (is_array($definition) && (is_array($definition['keep'] ?? null) || array_key_exists('nested', $definition))) {
            $nestedValue = $definition['nested'] ?? 'keep';

            if (! in_array($nestedValue, ['keep', 'drop'], true)) {
                $this->issue(PresetIssueCode::InvalidRelation, $subject, 'scope nested must be "keep" or "drop"');

                return null;
            }

            $keepNested = $nestedValue === 'keep';

            if (! isset($definition['keep'])) {
                return ScopeMap::same($keepNested);
            }

            $keep = [];

            foreach ((array) $definition['keep'] as $name) {
                if (! is_string($name) || ! isset($dimensions[$name])) {
                    $this->issue(PresetIssueCode::UnknownDimension, $subject, 'scope keeps dimension ['.(is_scalar($name) ? (string) $name : '').'] which is not declared');

                    return null;
                }
                $keep[] = $name;
            }

            return ScopeMap::keep($keep, $keepNested);
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
