<?php

namespace Tey\Mod\Discovery;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Testing\Fakes\EventFake;
use Tey\Mod\Discovery\Exceptions\InvalidDiscoveryCache;
use Tey\Mod\Preset\Preset;
use WeakMap;

/**
 * Discovery for one application: its preset, settings, inventory and the
 * dispatchers its listeners were registered on.
 *
 * Bound as an instance in that application's container by DiscoveryRegistrar.
 * Nothing here is static, so two applications in one process never share an
 * inventory or a registration record.
 */
final class Discovery
{
    /** @var list<DiscoveryDefinition> */
    private readonly array $definitions;

    private ?Inventory $inventory = null;

    private ?string $source = null;

    /** @var WeakMap<object, true> */
    private WeakMap $dispatchers;

    public function __construct(
        public readonly Preset $preset,
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
     * A fresh cold scan, ignoring any cache and leaving the current inventory alone.
     */
    public function scan(): Inventory
    {
        return (new DiscoveryScanner($this->preset, $this->basePath, new Eligibility, $this->options->candidates))->scan($this->definitions);
    }

    public function cache(): DiscoveryCache
    {
        $path = $this->options->cachePath;
        $absolute = str_starts_with($path, '/') || preg_match('#^[A-Za-z]:[\\\\/]#', $path) === 1
            ? $path
            : rtrim($this->basePath, '/\\').DIRECTORY_SEPARATOR.$path;

        return new DiscoveryCache($absolute);
    }

    /**
     * Scan cold and write the result to the cache file.
     */
    public function writeCache(): Inventory
    {
        $inventory = $this->scan();
        $this->cache()->write($inventory, $this->presetFingerprint(), $this->definitionsFingerprint());

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
     * Register the discovered listeners and subscribers on a dispatcher once.
     * Repeated calls, and fakes wrapping a dispatcher that already has them,
     * add nothing.
     *
     * @return int the number of listeners and subscribers added
     */
    public function registerListeners(Dispatcher $events): int
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

        foreach ($this->inventory()->ofType(DiscoveryType::Listener) as $listener) {
            foreach ($listener->events as $binding) {
                $events->listen($binding['event'], $listener->class.'@'.$binding['method']);
                $added++;
            }
        }

        foreach ($this->inventory()->ofType(DiscoveryType::Subscriber) as $subscriber) {
            $events->subscribe($subscriber->class);
            $added++;
        }

        return $added;
    }
}
