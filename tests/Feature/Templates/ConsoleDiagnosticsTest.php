<?php

use Composer\Autoload\ClassLoader;
use Symfony\Component\Process\Process;
use Tey\Mod\Tests\Feature\Acceptance\Examples\Support\TemplateScenario;
use Tey\Mod\Tests\Feature\Generation\Support\CommandResult;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;

it('prints the skip diagnostic before a missing command is rendered', function () {
    Workspace::run(null, function (Workspace $workspace) {
        $workspace->write('stubs/mod/{module}/Tools/broken.stub', TemplateScenario::CLASS_STUB);
        $loaderFile = (new ReflectionClass(ClassLoader::class))->getFileName();
        if ($loaderFile === false) {
            throw new LogicException('Composer must be loaded from a file.');
        }
        $autoload = var_export(dirname($loaderFile, 2).'/autoload.php', true);
        $basePath = var_export($workspace->root->path, true);
        $workspace->write('diagnostics.php', str_replace(['AUTOLOAD', 'BASE_PATH'], [$autoload, $basePath], <<<'CODE'
<?php
require AUTOLOAD;
$app = new Illuminate\Foundation\Application(BASE_PATH);
$app->instance('config', new Illuminate\Config\Repository([
    'mod' => ['layout' => 'modules', 'discovery' => ['enabled' => false]],
    'logging' => ['default' => 'null', 'channels' => ['null' => ['driver' => 'monolog', 'handler' => Monolog\Handler\NullHandler::class]]],
]));
$app->singleton(Illuminate\Contracts\Debug\ExceptionHandler::class, Illuminate\Foundation\Exceptions\Handler::class);
$app->register(Tey\Mod\ModServiceProvider::class);
$app->boot();
$app->make(Tey\Mod\Layout\CompiledLayout::class);
$handler = $app->make(Illuminate\Contracts\Debug\ExceptionHandler::class);
$exception = new Symfony\Component\Console\Exception\CommandNotFoundException('Command "mod:broken" is not defined.');
$handler->report($exception);
$handler->renderForConsole(new Symfony\Component\Console\Output\ConsoleOutput, $exception);
exit(1);
CODE));
        $process = new Process([PHP_BINARY, $workspace->root->path('diagnostics.php')]);
        $process->run();
        $output = (new CommandResult((int) $process->getExitCode(), $process->getOutput().$process->getErrorOutput(), $workspace->root->path))->normalisedOutput();
        expect($process->getExitCode())->toBe(1)
            ->and($output)->toContain('Skipped template [stubs/mod/{module}/Tools/broken.stub]', 'Command "mod:broken" is not defined')
            ->and(strpos($output, 'Skipped template'))->toBeLessThan(strpos($output, 'Command "mod:broken"'));
    });
});
