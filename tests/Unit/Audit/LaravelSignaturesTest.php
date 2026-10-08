<?php

use Symfony\Component\Console\Command\Command;

/*
 * Applications and packages subclass mod:* commands and override their
 * methods with the signatures Laravel (or Symfony Console) declares. A mod
 * command that narrows one, say getStub(): string over Laravel's untyped
 * getStub(), turns every such override into a fatal error. So each method a
 * mod command overrides keeps its framework parent's signature exactly, on
 * whichever Laravel and Symfony versions are installed.
 */

/**
 * @return array<string, array{class-string<Command>}> every command class in src, by short name
 */
function modCommandClasses(): array
{
    $root = dirname(__DIR__, 3).'/src';
    $classes = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $class = 'Tey\\Mod\\'.str_replace(['/', '\\'], '\\', substr($file->getPathname(), strlen($root) + 1, -4));

        try {
            if (! class_exists($class)) {
                continue;
            }
        } catch (Error $e) {
            // A command adapting a generator this Laravel version doesn't ship (make:config before 12.x).
            if (preg_match('/^Class "Illuminate\\\\[^"]+" not found$/', $e->getMessage()) === 1) {
                continue;
            }

            throw $e;
        }

        if (is_subclass_of($class, Command::class)) {
            $classes[class_basename($class)] = [$class];
        }
    }

    ksort($classes);

    return $classes;
}

function frameworkSignature(ReflectionMethod $method): string
{
    $parameters = array_map(
        static fn (ReflectionParameter $parameter): string => trim(
            ($parameter->getType() ?? 'untyped')
            .($parameter->isPassedByReference() ? ' &' : '')
            .($parameter->isVariadic() ? ' ...' : '')
            .($parameter->isOptional() ? ' = default' : '')
        ),
        $method->getParameters(),
    );

    // Visibility and static only: an abstract parent method is implemented, not narrowed.
    $visibility = $method->isPublic() ? 'public' : ($method->isProtected() ? 'protected' : 'private');

    return $visibility.($method->isStatic() ? ' static' : '')
        .' '.$method->getName().'('.implode(', ', $parameters).'): '
        .($method->getReturnType() ?? $method->getTentativeReturnType() ?? 'untyped');
}

it('finds the command classes', function () {
    expect(modCommandClasses())->toHaveKeys(['ClassCommand', 'GenericClassCommand', 'MigrationCommand', 'ModelCommand', 'DiscoveryCacheCommand']);
});

it('keeps the framework signature of every method a command overrides', function (string $class) {
    $command = new ReflectionClass($class);
    $framework = $command->getParentClass();

    while ($framework !== false && str_starts_with($framework->getName(), 'Tey\\Mod\\')) {
        $framework = $framework->getParentClass();
    }

    $narrowed = [];

    foreach ($command->getMethods() as $method) {
        // Constructors are exempt from signature compatibility.
        if ($method->isConstructor() || ! str_starts_with($method->getDeclaringClass()->getName(), 'Tey\\Mod\\') || $framework === false || ! $framework->hasMethod($method->getName())) {
            continue;
        }

        $parent = $framework->getMethod($method->getName());

        if (frameworkSignature($method) !== frameworkSignature($parent)) {
            $narrowed[] = frameworkSignature($method).', but '.$parent->getDeclaringClass()->getName().' declares '.frameworkSignature($parent);
        }
    }

    expect($narrowed)->toBe([]);
})->with(modCommandClasses());
