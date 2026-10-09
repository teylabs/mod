<?php

namespace Tey\Mod\Discovery;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Testing\Fakes\EventFake;
use Tey\Mod\Exceptions\InvalidDiscoveryCache;
use Tey\Mod\Layout\CompiledLayout;
use Tey\Mod\Support\Path;
use WeakMap;

/**
 * Discovery for one application: its preset, settings, inventory and the
 * dispatchers its listeners were registered on.
 *
 * Bound as an instance in that application's container by DiscoveryRegistrar.
 * Nothing here is static, so two applications in one process never share an
 * inventory or a registration record.
 *
 * @internal
 *
 * @phpstan-import-type Node from \Tey\Mod\Scaffolds\ScaffoldRegistry
 */
final class Discovery
{
    /** @var list<DiscoveryDefinition> */
    private readonly array $definitions;

    private ?Inventory $inventory = null;

    private ?string $source = null;

    /** Why an existing cache file was ignored under the scan policy, once known. */
    private ?string $staleCache = null;

    /** @var WeakMap<object, true> */
    private WeakMap $dispatchers;

    public function __construct(
        public readonly CompiledLayout $preset,
        public readonly DiscoveryOptions $options,
        public readonly string $basePath,
    ) {
        $this->definitions = $options->definitionsFor($preset);
        $this->dispatchers = new WeakMap;
    }

    /**
     * @return list<DiscoveryDefinition>
     */
    public function definitions(): array
    {
        return $this->definitions;
    }

    /**
     * The inventory: replayed from the cache file when one exists, otherwise a cold scan.
     *
     * @throws InvalidDiscoveryCache when the cache cannot be trusted and the policy is Fail
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

        $this->source = 'scan';

        return $this->inventory = $this->scan();
    }

    /**
     * Where the current inventory came from: 'cache', 'scan', or null before it is built.
     */
    public function source(): ?string
    {
        return $this->source;
    }

    /**
     * Why an existing cache file was ignored (scan policy): the validation
     * message, or null when the cache was used or there was none.
     */
    public function staleCacheReason(): ?string
    {
        return $this->staleCache;
    }

    /**
     * A fresh cold scan, ignoring any cache and leaving the current inventory alone.
     */
    public function scan(): Inventory
    {
        return (new DiscoveryScanner($this->preset, $this->basePath, new Eligibility, $this->options->candidates))->scan($this->definitions);
    }

    public function cache(): DiscoveryCache
    {
        $path = Path::normalize($this->options->cachePath);
        $absolute = str_starts_with($path, '/') || preg_match('#^[A-Za-z]:[\\\\/]#', $path) === 1
            ? $path
            : Path::resolve($this->basePath, $path);

        return new DiscoveryCache($absolute);
    }

    /**
     * Scan cold and write the result to the cache file.
     *
     * @param  array<string, Node>  $scaffolds
     */
    public function writeCache(array $scaffolds = []): Inventory
    {
        $inventory = $this->scan();
        $this->cache()->write($inventory, $this->presetFingerprint(), $this->definitionsFingerprint(), $this->preset->templates(), $scaffolds);

        return $inventory;
    }

    public function clearCache(): bool
    {
        return $this->cache()->clear();
    }

    public function presetFingerprint(): string
    {
        return PresetFingerprint::of($this->preset);
    }

    /**
     * The definitions digest; a custom candidate-file source is recorded by
     * presence only (a closure cannot be hashed), so rebuild the cache on
     * deploy when the source changes.
     */
    public function definitionsFingerprint(): string
    {
        return PresetFingerprint::ofDefinitions($this->definitions).($this->options->candidates !== null ? ':custom-candidates' : '');
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

        foreach ($this->inventory()->ofType(DiscoveryType::Listener) as $listener) {
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

        foreach ($this->inventory()->ofType(DiscoveryType::Subscriber) as $subscriber) {
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
