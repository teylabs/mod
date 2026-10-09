<?php

use Tey\Mod\Tests\Feature\Generation\Support\CommandResult;

it('normalises command line endings and bracketed workspace paths without changing spacing', function () {
    $result = new CommandResult(0, "\r\n   INFO  Model [C:\\Temp\\workspace\\app/Models\\Invoice.php] created successfully.  \r\n\r\nUsing namespace [Areas\\].\r\n", 'C:/Temp/workspace');

    expect($result->normalisedOutput())->toBe("\n   INFO  Model [app/Models/Invoice.php] created successfully.  \n\nUsing namespace [Areas\\].\n");
});

it('recognises the real workspace path as well as the given spelling', function () {
    $root = __DIR__.'/../../Feature/Generation/Support/..';
    $real = realpath($root);
    expect($real)->not->toBeFalse();

    $result = new CommandResult(0, 'Model ['.str_replace('/', '\\', $real).'/app/Invoice.php]', $root);

    expect($result->normalisedOutput())->toBe('Model [app/Invoice.php]');
});

it('requires a directory boundary when stripping a workspace root', function () {
    $result = new CommandResult(0, 'Model [C:\\Temp\\workspace-other\\Invoice.php]', 'C:/Temp/workspace');

    expect($result->normalisedOutput())->toBe('Model [C:/Temp/workspace-other/Invoice.php]');
});

it('matches workspace path case according to the platform', function () {
    $result = new CommandResult(0, 'Model [C:\\TEMP\\Workspace\\Invoice.php]', 'c:/temp/workspace');

    expect($result->normalisedOutput())->toBe(PHP_OS_FAMILY === 'Windows'
        ? 'Model [Invoice.php]'
        : 'Model [C:/TEMP/Workspace/Invoice.php]');
});

it('normalises relative bracketed paths without a workspace root', function () {
    expect((new CommandResult(0, 'Tool [app\\Tools\\Search.php]'))->normalisedOutput())
        ->toBe('Tool [app/Tools/Search.php]');
});

it('keeps bracketed class references unchanged', function () {
    expect((new CommandResult(0, 'Related factory [Database\\Factories\\InvoiceFactory] is a reference.'))->normalisedOutput())
        ->toBe('Related factory [Database\\Factories\\InvoiceFactory] is a reference.');
});
