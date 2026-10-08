<?php

use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Symfony\Component\Process\Process;
use Tey\Mod\Tests\Fixtures\Commands\TypedStubCommand;
use Tey\Mod\Tests\Fixtures\Commands\UntypedConfigureCommand;
use Tey\Mod\Tests\Fixtures\Commands\UntypedStubCommand;

/*
 * An application or package subclass of a mod:* command overrides its
 * methods with Laravel's signatures. An incompatible declaration is a fatal
 * error when the class loads, so each fixture is loaded in its own process.
 */

/**
 * @param  class-string  $class
 */
function loadInIsolation(string $class): Process
{
    $process = new Process([
        PHP_BINARY,
        '-r',
        'require $argv[1]; exit(class_exists($argv[2]) ? 0 : 2);',
        '--',
        dirname(__DIR__, 3).'/vendor/autoload.php',
        $class,
    ]);
    $process->run();

    return $process;
}

it('loads a subclass that overrides getStub() untyped, as Laravel declares it', function () {
    $process = loadInIsolation(UntypedStubCommand::class);

    expect($process->getExitCode())->toBe(0, $process->getOutput().$process->getErrorOutput());
});

it('loads a subclass that overrides getStub() with a string return type', function () {
    $process = loadInIsolation(TypedStubCommand::class);

    expect($process->getExitCode())->toBe(0, $process->getOutput().$process->getErrorOutput());
});

it('loads a subclass that overrides configure() untyped, as Symfony Console 7 declares it', function () {
    $process = loadInIsolation(UntypedConfigureCommand::class);

    expect($process->getExitCode())->toBe(0, $process->getOutput().$process->getErrorOutput());
})->skip(
    (new ReflectionMethod(SymfonyCommand::class, 'configure'))->hasReturnType(),
    'Symfony Console 8 declares configure(): void, so an untyped override is invalid there whatever mod declares.',
);
