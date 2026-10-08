<?php

use Symfony\Component\Console\Exception\RuntimeException;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;

/*
 * A file type with a fixed name (slices' Handler, Command, Request...) takes
 * no name: mod:handler --in=Billing/CreateInvoice writes Handler.php. A name
 * that is given anyway is not used, and the command says so.
 */

it('needs no name for a file type with a fixed name', function (string $command, string $file) {
    Workspace::run(null, function (Workspace $workspace) use ($command, $file) {
        config()->set('mod.layout', 'slices');

        expect($workspace->artisan($command, ['--in' => 'Billing/CreateInvoice']))
            ->toHaveGenerated("app/Billing/CreateInvoice/{$file}.php", 'App\\Billing\\CreateInvoice');
    });
})->with([
    'generic' => ['mod:handler', 'Handler'],
    'native' => ['mod:request', 'Request'],
]);

it('says when a given name is not used', function () {
    Workspace::run(null, function (Workspace $workspace) {
        config()->set('mod.layout', 'slices');

        $result = $workspace->artisan('mod:handler', ['name' => 'Billing/CreateInvoice:IssueInvoice']);

        expect($result)->toHaveGenerated('app/Billing/CreateInvoice/Handler.php')
            ->and($result->output)->toContain('mod:handler always writes Handler.php; the name [IssueInvoice] is not used.')
            ->and($workspace->artisan('mod:handler', ['name' => 'Handler', '--in' => 'Billing/PayInvoice'])->output)
            ->not->toContain('is not used');
    });
});

it('still needs a name for every other file type', function () {
    Workspace::run(null, function (Workspace $workspace) {
        config()->set('mod.layout', 'slices');

        $workspace->artisan('mod:model', ['--in' => 'Billing']);
    });
})->throws(RuntimeException::class, 'Not enough arguments (missing: "name").');
