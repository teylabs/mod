<?php

namespace Tey\Mod\Discovery;

use Closure;
use Tey\Mod\Artifact\NamePolicyKind;
use Tey\Mod\Exceptions\InvalidDiscoveryConfig;
use Tey\Mod\Layout\CompiledLayout;
use Tey\Mod\Layout\CompiledRoot;
use Tey\Mod\Resolution\ModelRelations;

/**
 * Host settings for discovery (config key `mod.discovery`).
 *
 *     'discovery' => [
 *         'enabled' => true,                                   // false: discover nothing
 *         'kinds' => ['provider' => false, 'subscriber' => 'listener'],  // merged over the defaults
 *         'cache' => 'bootstrap/cache/mod-discovery.php',      // relative to the base path, or absolute
 *         'on_stale_cache' => 'scan',                          // or 'fail'
 *         'factories' => true,                                 // Model::factory() through the layout's factory relation
 *         'policies' => true,                                  // Gate::policy() through the layout's policy relation
 *     ]
 *
 * By default a preset kind whose id is `provider`, `command`, `listener` or
 * `subscriber` is discovered as that type. A `directory` type collects the
 * directories of a file kind (per-feature migration folders, say).
 *
 * When the layout relates its `model` kind to a `factory` or `policy` kind,
 * models are paired with the related class that exists (DiscoveryType::Factory,
 * ::Policy): factories resolve through ModelConventions, policies register
 * with the Gate. `factories` / `policies` false turns either off.
 *
 * A host may supply its own candidate-file source: a closure returning the
 * relative, '/'-separated .php paths below a root that discovery should
 * consider for one discovered kind (to skip generated or vendored subtrees,
 * to reuse an existing finder, or to scope candidates per kind). Ownership,
 * eligibility and registration stay with mod.
 */
final readonly class DiscoveryOptions
{
    public const DEFAULT_CACHE = 'bootstrap/cache/mod-discovery.php';

    /**
     * @param  array<string, DiscoveryType|false>  $kinds  overrides keyed by kind id
     * @param  (Closure(CompiledRoot, string, DiscoveryDefinition): iterable<string>)|null  $candidates  candidate-file source: (root, basePath, definition) → relative .php paths
     */
    public function __construct(
        public bool $enabled = true,
        public array $kinds = [],
        public string $cachePath = self::DEFAULT_CACHE,
        public CacheMismatchPolicy $onStaleCache = CacheMismatchPolicy::Scan,
        public ?Closure $candidates = null,
        public bool $factories = true,
        public bool $policies = true,
    ) {}

    /**
     * @param  array<array-key, mixed>  $config
     *
     * @throws InvalidDiscoveryConfig
     */
    public static function fromConfig(array $config): self
    {
        $enabled = $config['enabled'] ?? true;

        if (! is_bool($enabled)) {
            throw InvalidDiscoveryConfig::because('enabled', 'expected a boolean');
        }

        $rawKinds = $config['kinds'] ?? [];

        if (! is_array($rawKinds)) {
            throw InvalidDiscoveryConfig::because('kinds', 'expected an array of file type id => type|false');
        }

        $kinds = [];

        foreach ($rawKinds as $kindId => $type) {
            if (! is_string($kindId)) {
                throw InvalidDiscoveryConfig::because('kinds', 'keys must be file type ids');
            }

            if ($type === false) {
                $kinds[$kindId] = false;

                continue;
            }

            $resolved = is_string($type) ? DiscoveryType::tryFrom($type) : ($type instanceof DiscoveryType ? $type : null);

            if ($resolved === null || $resolved->isRelationType()) {
                throw InvalidDiscoveryConfig::because("kinds.{$kindId}", 'expected provider, command, listener, subscriber, directory or false');
            }

            $kinds[$kindId] = $resolved;
        }

        $cache = $config['cache'] ?? self::DEFAULT_CACHE;

        if (! is_string($cache) || trim($cache) === '') {
            throw InvalidDiscoveryConfig::because('cache', 'expected a file path');
        }

        $policy = $config['on_stale_cache'] ?? CacheMismatchPolicy::Scan->value;
        $policy = $policy instanceof CacheMismatchPolicy ? $policy : (is_string($policy) ? CacheMismatchPolicy::tryFrom($policy) : null);

        if ($policy === null) {
            throw InvalidDiscoveryConfig::because('on_stale_cache', 'expected fail or scan');
        }

        $switches = [];

        foreach (['factories', 'policies'] as $key) {
            $switches[$key] = $config[$key] ?? true;

            if (! is_bool($switches[$key])) {
                throw InvalidDiscoveryConfig::because($key, 'expected a boolean');
            }
        }

        return new self($enabled, $kinds, $cache, $policy, null, $switches['factories'], $switches['policies']);
    }

    /**
     * The same options with a candidate-file source.
     *
     * @param  Closure(CompiledRoot, string, DiscoveryDefinition): iterable<string>  $candidates
     */
    public function withCandidates(Closure $candidates): self
    {
        return new self($this->enabled, $this->kinds, $this->cachePath, $this->onStaleCache, $candidates, $this->factories, $this->policies);
    }

    /**
     * Every definition for this preset, disabled ones included, ordered by kind id.
     *
     * @return list<DiscoveryDefinition>
     *
     * @throws InvalidDiscoveryConfig
     */
    public function definitionsFor(CompiledLayout $preset): array
    {
        /** @var array<string, DiscoveryDefinition> $definitions */
        $definitions = [];

        foreach (DiscoveryType::cases() as $type) {
            if ($type->isClassType() && ! $type->isRelationType() && $preset->hasKind($type->value) && $preset->kind($type->value)->isClass()) {
                $definitions[$type->value] = new DiscoveryDefinition($type->value, $type);
            }
        }

        // Models paired with their factory / policy, when the layout relates them.
        $relations = new ModelRelations($preset);

        foreach ([DiscoveryType::Factory->value => $this->factories, DiscoveryType::Policy->value => $this->policies] as $target => $on) {
            if ($relations->declares($target)) {
                $definition = DiscoveryDefinition::related(DiscoveryType::from($target), ModelRelations::MODEL_KIND);
                $definitions[$definition->key()] = $on ? $definition : $definition->disabled();
            }
        }

        // Timestamped file kinds are migrations: their directories are collected so the
        // migrator loads them (opt out with 'kinds' => ['migration' => false]).
        foreach ($preset->kinds() as $kind) {
            if (! $kind->isClass() && $kind->namePolicy->kind === NamePolicyKind::Timestamped) {
                $definitions[$kind->id] = DiscoveryDefinition::directories($kind->id);
            }
        }

        foreach ($this->kinds as $kindId => $type) {
            if (! $preset->hasKind($kindId)) {
                $declared = array_keys($preset->kinds());
                sort($declared);

                throw InvalidDiscoveryConfig::because("kinds.{$kindId}", "the active layout has no [{$kindId}] file type. Map one of its file types: ".implode(', ', $declared));
            }

            if ($type === false) {
                if (isset($definitions[$kindId])) {
                    $definitions[$kindId] = $definitions[$kindId]->disabled();
                }

                continue;
            }

            if ($type->isClassType() && ! $preset->kind($kindId)->isClass()) {
                throw InvalidDiscoveryConfig::because("kinds.{$kindId}", 'only file types that hold classes can be discovered as '.$type->value);
            }

            if (! $type->isClassType() && $preset->kind($kindId)->isClass()) {
                throw InvalidDiscoveryConfig::because("kinds.{$kindId}", 'only file types that hold plain files, such as migrations, can be discovered as directories');
            }

            $definitions[$kindId] = new DiscoveryDefinition($kindId, $type);
        }

        if (! $this->enabled) {
            $definitions = array_map(static fn (DiscoveryDefinition $definition): DiscoveryDefinition => $definition->disabled(), $definitions);
        }

        ksort($definitions);

        return array_values($definitions);
    }
}
