<?php

namespace Tey\Mod\Discovery;

use Composer\Autoload\ClassLoader;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Testing\Fakes\EventFake;
use Tey\Mod\Exceptions\InvalidDiscoveryCache;
use Tey\Mod\Layout\CompiledLayout;
use Tey\Mod\Placement\TemplateRule;
use Tey\Mod\Support\Path;
use Throwable;
use WeakMap;

/**
 * Discovery for one application: its preset, settings, inventory and the
 * dispatchers its listeners were registered on.
 *
 * Bound as an instance in that application's container by DiscoveryRegistrar.
 * Registration records stay application-local. Without a cache file, the
 * automatic cache (`discovery.auto_cache`) keeps the scan with a fingerprint
 * of what it depends on; otherwise an unchanged inventory can be reused by
 * later applications in this process.
 *
 * @phpstan-import-type Node from \Tey\Mod\Scaffolds\ScaffoldRegistry
 *
 * @api
 */
final class Discovery
{
    /** @var list<DiscoveryDefinition> */
    private readonly array $definitions;

    /** @internal */
    public readonly CompiledLayout $preset;

    private ?Inventory $inventory = null;

    private ?string $source = null;

    /** Why an existing cache file was ignored under the scan policy, once known. */
    private ?string $staleCache = null;

    /** The automatic cache file that could not be written, once known. */
    private ?string $unwritable = null;

    /** @var WeakMap<object, true> */
    private WeakMap $dispatchers;

    private DisabledGroups $disabled;

    /** @api */
    public function __construct(
        /** @internal */
        public readonly CompiledLayout $layout,
        /** @internal */
        public readonly DiscoveryOptions $options,
        /** @internal */
        public readonly string $basePath,
    ) {
        $this->preset = $layout;
        $this->definitions = $options->definitionsFor($layout);
        $this->dispatchers = new WeakMap;
        $this->disabled = new DisabledGroups;
    }

    /**
     * Switch groups off (`mod.disabled`) before anything registers.
     *
     * @internal
     */
    public function disable(DisabledGroups $groups): void
    {
        $this->disabled = $groups;
    }

    /**
     * @return list<DiscoveryDefinition>
     *
     * @internal
     */
    public function definitions(): array
    {
        return $this->definitions;
    }

    /**
     * The inventory: replayed from a cache file, from an unchanged automatic cache, reused in this process, or scanned cold.
     *
     * @throws InvalidDiscoveryCache when the cache cannot be trusted and the policy is Fail
     *
     * @internal
     */
    public function inventory(): Inventory
    {
        if ($this->inventory !== null) {
            return $this->inventory;
        }

        $cache = $this->cache();

        if ($cache->exists()) {
            try {
                $this->source = 'cache';

                return $this->inventory = $cache->read($this->presetFingerprint(), $this->definitionsFingerprint());
            } catch (InvalidDiscoveryCache $exception) {
                if ($this->options->onStaleCache === CacheMismatchPolicy::Fail) {
                    throw $exception;
                }

                $this->staleCache = $exception->getMessage();
            }
        }

        if ($this->staleCache === null && $this->autoCaches()) {
            return $this->inventory = $this->autoInventory();
        }

        $this->source = 'scan';

        if ($this->staleCache === null && $this->options->reuseInProcess && $this->options->candidates === null) {
            return $this->inventory = InProcessInventory::instance()->remember(
                $this->basePath,
                $this->presetFingerprint().':'.$this->definitionsFingerprint(),
                $this->roots(),
                fn (): Inventory => $this->scan(),
            );
        }

        return $this->inventory = $this->scan();
    }

    /**
     * The inventory less the groups `mod.disabled` switches off; what registers.
     *
     * @internal
     */
    public function active(): Inventory
    {
        return $this->disabled->filter($this->inventory(), $this->preset);
    }

    /**
     * Where the current inventory came from: 'cache', 'auto-cache', 'scan', or null before it is built.
     *
     * @internal
     */
    public function source(): ?string
    {
        return $this->source;
    }

    /**
     * Why an existing cache file was ignored (scan policy): the validation
     * message, or null when the cache was used or there was none.
     *
     * @internal
     */
    public function staleCacheReason(): ?string
    {
        return $this->staleCache;
    }

    /**
     * The automatic cache file that could not be written, or null; the inventory was scanned instead.
     *
     * @internal
     */
    public function unwritableAutoCache(): ?string
    {
        return $this->unwritable;
    }

    /**
     * A fresh cold scan, ignoring any cache and leaving the current inventory alone.
     *
     * @api
     */
    public function scan(): Inventory
    {
        return $this->scanWatching(null);
    }

    private function scanWatching(?WatchedPaths $watched): Inventory
    {
        $observer = InProcessInventory::instance()->onScan;
        if ($observer !== null) {
            $observer();
        }

        return (new DiscoveryScanner($this->preset, $this->basePath, new Eligibility, $this->options->candidates, watched: $watched))->scan($this->definitions);
    }

    /** Read a validated cache only; never scan or mutate memoised inventory. @api */
    public function readCache(): Inventory
    {
        return $this->cache()->read($this->presetFingerprint(), $this->definitionsFingerprint());
    }

    /** Scan and write the host inventory, with no scaffold payload. @api */
    public function cacheInventory(): Inventory
    {
        return $this->writeCache();
    }

    /** @internal */
    public function cache(): DiscoveryCache
    {
        $path = Path::normalize($this->options->cachePath);
        $absolute = str_starts_with($path, '/') || preg_match('#^[A-Za-z]:[\\\\/]#', $path) === 1
            ? $path
            : Path::resolve($this->basePath, $path);

        return new DiscoveryCache($absolute);
    }

    /** The automatic cache: beside the cache file, named *.auto.php. Never read while the cache file exists. */
    private function autoCache(): DiscoveryCache
    {
        $path = $this->cache()->path;

        return new DiscoveryCache((str_ends_with($path, '.php') ? substr($path, 0, -4) : $path).'.auto.php');
    }

    /**
     * Scan cold and write the result to the cache file.
     *
     * @param  array<string, Node>  $scaffolds
     * @param  list<array{group: ?string, namespace: string, path: string}>  $translations
     *
     * @internal
     */
    public function writeCache(array $scaffolds = [], array $translations = []): Inventory
    {
        $inventory = $this->scan();
        $this->cache()->write($inventory, $this->presetFingerprint(), $this->definitionsFingerprint(), $this->preset->templates(), $scaffolds, $translations);

        return $inventory;
    }

    /**
     * Remove the cache file and the automatic cache; whether either existed.
     *
     * @internal
     */
    public function clearCache(): bool
    {
        $cleared = $this->cache()->clear();

        return $this->autoCache()->clear() || $cleared;
    }

    /** @internal */
    public function presetFingerprint(): string
    {
        return PresetFingerprint::of($this->preset);
    }

    /**
     * The definitions digest; a custom candidate-file source is recorded by
     * presence only (a closure cannot be hashed), so rebuild the cache on
     * deploy when the source changes.
     *
     * @internal
     */
    public function definitionsFingerprint(): string
    {
        return PresetFingerprint::ofDefinitions($this->definitions).($this->options->candidates !== null ? ':custom-candidates' : '');
    }

    /**
     * Without a custom candidate source, which no fingerprint can cover, and with something to discover.
     */
    private function autoCaches(): bool
    {
        if (! $this->options->autoCache || $this->options->candidates !== null) {
            return false;
        }

        foreach ($this->definitions as $definition) {
            if ($definition->enabled) {
                return true;
            }
        }

        return false;
    }

    /**
     * The automatic cache's inventory when nothing it watched changed; else a
     * scan, written with what it depends on when that can be trusted. A cache
     * that cannot be written is left alone and the scan is used.
     */
    private function autoInventory(): Inventory
    {
        $cache = $this->autoCache();
        $preset = $this->presetFingerprint();
        $definitions = $this->definitionsFingerprint();
        $inventory = $cache->readUnchanged($preset, $definitions, $this->basePath);

        if ($inventory !== null) {
            $this->source = 'auto-cache';

            return $inventory;
        }

        $this->source = 'scan';
        $started = time();
        $watched = new WatchedPaths;

        // A callback placement can name a related class from code no path covers.
        foreach ($this->definitions as $definition) {
            if ($definition->enabled && $definition->type->isRelationType() && ! $this->preset->rule($definition->type->value) instanceof TemplateRule) {
                $watched->unresolved();
            }
        }

        $inventory = $this->scanWatching($watched);
        $watched->directories($this->roots());

        // Installing, removing or re-dumping packages changes what autoloads.
        $watched->file(Path::resolve($this->basePath, 'composer.lock'));
        foreach (array_keys(ClassLoader::getRegisteredLoaders()) as $vendor) {
            $watched->file($vendor.'/composer/installed.json');
            $watched->file($vendor.'/composer/autoload_static.php');
        }

        $signatures = $watched->signatures($started);

        if ($signatures === null) {
            return $inventory;
        }

        // A read-only or missing directory reports a warning; the write fails and the scan stands.
        set_error_handler(static fn (): bool => true);
        try {
            $cache->write($inventory, $preset, $definitions, watched: ['base' => $this->basePath, 'paths' => $signatures]);
        } catch (Throwable) {
            $this->unwritable = $cache->path;
        } finally {
            restore_error_handler();
        }

        return $inventory;
    }

    /**
     * The absolute root of every enabled definition, with the related roots of factory and policy pairs.
     *
     * @return list<string>
     */
    private function roots(): array
    {
        $roots = [];
        foreach ($this->definitions as $definition) {
            if (! $definition->enabled) {
                continue;
            }
            $roots[] = Path::resolve($this->basePath, $this->preset->rule($definition->kindId)->root()->path);
            if ($definition->type->isRelationType()) {
                $roots[] = Path::resolve($this->basePath, $this->preset->rule($definition->type->value)->root()->path);
            }
        }

        return array_values(array_unique($roots));
    }

    /**
     * Register the discovered listeners and subscribers on a dispatcher: once
     * per dispatcher, and never twice for the same binding. A binding the
     * dispatcher already holds (the application's own event discovery, its
     * events cache, a manual listen()) is left alone; a subscriber whose
     * listeners are already present is not subscribed again. Classes whose
     * files lie below $skipBelow (the application's event-discovery paths)
     * are skipped entirely, so it does not matter which side registers first.
     *
     * @param  list<string>  $skipBelow  absolute directories the application discovers listeners in itself
     * @return int bindings and subscriptions added
     *
     * @internal
     */
    public function registerListeners(Dispatcher $events, array $skipBelow = []): int
    {
        // A fake forwards listen() to the dispatcher it wraps, which is what really holds listeners.
        $target = $events;

        while ($target instanceof EventFake) {
            $target = $target->dispatcher;
        }

        if (isset($this->dispatchers[$target])) {
            return 0;
        }

        $this->dispatchers[$target] = true;
        $added = 0;
        $raw = $target instanceof \Illuminate\Events\Dispatcher ? $target->getRawListeners() : [];

        foreach ($this->active()->ofType(DiscoveryType::Listener) as $listener) {
            if ($this->isBelow($listener->path, $skipBelow)) {
                continue;
            }

            foreach ($listener->events as $binding) {
                if ($this->holds($raw, $binding['event'], $listener->class, $binding['method'])) {
                    continue;
                }

                $events->listen($binding['event'], $listener->class.'@'.$binding['method']);
                $added++;
            }
        }

        foreach ($this->active()->ofType(DiscoveryType::Subscriber) as $subscriber) {
            if ($this->isBelow($subscriber->path, $skipBelow) || $this->refersTo($raw, $subscriber->class)) {
                continue;
            }

            $events->subscribe($subscriber->class);
            $added++;
        }

        return $added;
    }

    /**
     * Whether the dispatcher already holds this listener binding, however it was registered.
     *
     * @param  array<string, mixed>  $raw
     */
    private function holds(array $raw, string $event, string $class, string $method): bool
    {
        foreach ((array) ($raw[$event] ?? []) as $registered) {
            if ($registered === $class.'@'.$method || $registered === [$class, $method]) {
                return true;
            }

            if ($registered === $class && in_array($method, ['handle', '__invoke'], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether any registered listener belongs to the class (a subscriber already subscribed).
     *
     * @param  array<string, mixed>  $raw
     */
    private function refersTo(array $raw, string $class): bool
    {
        foreach ($raw as $listeners) {
            foreach ((array) $listeners as $registered) {
                if ($registered === $class
                    || (is_string($registered) && str_starts_with($registered, $class.'@'))
                    || (is_array($registered) && ($registered[0] ?? null) === $class)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $directories  absolute
     */
    private function isBelow(string $relativePath, array $directories): bool
    {
        if ($directories === []) {
            return false;
        }

        $file = realpath(Path::resolve($this->basePath, $relativePath));

        if ($file === false) {
            return false;
        }

        foreach ($directories as $directory) {
            $real = realpath($directory);

            if ($real !== false && Path::relative($real, $file) !== null && ! Path::same($real, $file)) {
                return true;
            }
        }

        return false;
    }
}
