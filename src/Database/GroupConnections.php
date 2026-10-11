<?php

namespace Tey\Mod\Database;

use Illuminate\Foundation\Application;
use Tey\Mod\Layout\CompiledLayout;
use Tey\Mod\Placement\PlacementContext;
use Tey\Mod\Reverse\ReverseMapper;

/** @internal Application-owned connection declarations and migration directories. */
final class GroupConnections
{
    /** @var array<string, string> */
    private array $connections = [];

    /** @var array<string, list<string>> */
    private array $directories = [];

    private readonly string $defaultConnection;

    public function __construct(private readonly Application $app)
    {
        $this->defaultConnection = $app->make('db')->getDefaultConnection();
    }

    public function declare(string $group, string $connection): void
    {
        if (trim($connection) === '' || isset($this->connections[$group]) && $this->connections[$group] !== $connection) {
            throw new \LogicException("Connection for group [{$group}] must be nonempty and declared consistently.");
        }
        $this->connections[$group] = $connection;
    }

    /** @param array<string, string> $context */
    public function forContext(array $context): ?string
    {
        if ($this->connections === []) {
            return null;
        }
        $layout = $this->app->make(CompiledLayout::class);
        $values = [];
        foreach ($layout->dimensionNames() as $dimension) {
            if (! isset($context[$dimension])) {
                return null;
            }
            $values[] = $context[$dimension];
        }

        return $this->connections[implode('/', $values)] ?? null;
    }

    public function forClass(string $class): ?string
    {
        $match = (new ReverseMapper($this->app->make(CompiledLayout::class)))->fromClass($class);

        return $match->isMatched() && $match->artifact !== null
            ? $this->forContext($match->artifact->context->toArray()) : null;
    }

    /** @return array<string, string> */
    public function declarations(): array
    {
        if ($this->connections === []) {
            return [];
        }
        $layout = $this->app->make(CompiledLayout::class);
        foreach ($this->connections as $group => $connection) {
            $context = PlacementContext::fromOption($group, $layout);
            if (count($context->toArray()) !== count($layout->dimensionNames())) {
                throw new \LogicException("Connection declaration [{$group}] needs a complete layout group.");
            }
            if (! is_array($this->app->make('config')->get('database.connections.'.$connection))) {
                throw new \LogicException("Database connection [{$connection}] is not configured.");
            }
        }

        return $this->connections;
    }

    public function directory(string $connection, string $directory): void
    {
        $this->directories[$connection][] = $directory;
        $this->directories[$connection] = array_values(array_unique($this->directories[$connection]));
    }

    public function isDefault(?string $connection): bool
    {
        return $connection === null || $connection === $this->defaultConnection;
    }

    /** @return list<string>|null Null means native Laravel selection. */
    public function paths(?string $connection): ?array
    {
        $this->declarations();
        $connection ??= $this->defaultConnection;

        return isset($this->directories[$connection]) ? $this->directories[$connection] : (in_array($connection, $this->connections, true) ? [] : null);
    }
}
