<?php

namespace Tey\Mod\Discovery;

use Illuminate\Console\Command;
use Illuminate\Support\ServiceProvider;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionUnionType;

/**
 * Semantic eligibility: a class registers only when it really is what its
 * discovery type needs. Names never decide; an application message class
 * called Command is not an Artisan command.
 */
final readonly class Eligibility
{
    /**
     * @param  class-string  $class
     * @return list<array{event: string, method: string}>|string the listener events (empty for other types), or why the class is ineligible
     */
    public function check(DiscoveryType $type, string $class, string $absolutePath): array|string
    {
        $reflection = new ReflectionClass($class);
        $file = $reflection->getFileName();

        if ($file === false || realpath($file) !== realpath($absolutePath)) {
            return sprintf('class [%s] is loaded from [%s], not from the scanned file', $class, $file === false ? 'internal' : $file);
        }

        if (! $reflection->isInstantiable()) {
            return sprintf('[%s] is not a concrete class', $class);
        }

        return match ($type) {
            DiscoveryType::Provider => $reflection->isSubclassOf(ServiceProvider::class)
                ? []
                : sprintf('[%s] does not extend %s', $class, ServiceProvider::class),
            DiscoveryType::Command => $reflection->isSubclassOf(Command::class)
                ? []
                : sprintf('[%s] does not extend %s', $class, Command::class),
            DiscoveryType::Listener => $this->events($reflection),
            DiscoveryType::Subscriber => $this->subscribes($reflection)
                ? []
                : sprintf('[%s] has no public subscribe() method taking exactly one parameter', $class),
            DiscoveryType::Directory => sprintf('[%s] is a class; directories are discovered from file kinds', $class),
        };
    }

    /**
     * Public handle*() and __invoke() methods whose first parameter is typed with event classes.
     *
     * @param  ReflectionClass<object>  $reflection
     * @return list<array{event: string, method: string}>|string
     */
    private function events(ReflectionClass $reflection): array|string
    {
        $events = [];

        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->isStatic() || ! ($method->name === '__invoke' || str_starts_with($method->name, 'handle'))) {
                continue;
            }

            $type = ($method->getParameters()[0] ?? null)?->getType();
            $named = match (true) {
                $type instanceof ReflectionNamedType => [$type],
                $type instanceof ReflectionUnionType => $type->getTypes(),
                default => [],
            };

            foreach ($named as $candidate) {
                if ($candidate instanceof ReflectionNamedType && ! $candidate->isBuiltin()) {
                    $events[] = ['event' => $candidate->getName(), 'method' => $method->name];
                }
            }
        }

        return $events === []
            ? sprintf('[%s] has no public handle or __invoke method whose first parameter is typed with an event class', $reflection->name)
            : $events;
    }

    /**
     * Laravel's Event::subscribe() contract: a public, non-static subscribe() with exactly one parameter.
     *
     * @param  ReflectionClass<object>  $reflection
     */
    private function subscribes(ReflectionClass $reflection): bool
    {
        if (! $reflection->hasMethod('subscribe')) {
            return false;
        }

        $method = $reflection->getMethod('subscribe');

        return $method->isPublic() && ! $method->isStatic() && $method->getNumberOfParameters() === 1;
    }
}
