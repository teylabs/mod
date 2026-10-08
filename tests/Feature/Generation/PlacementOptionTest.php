<?php

use Tey\Mod\Tests\Feature\Generation\Support\Workspace;

/*
 * --in errors come from the core placement rules, verbatim, and nothing is written.
 */

it('requires a placement value the rule needs', function () {
    Workspace::run('modules', function (Workspace $workspace) {
        $workspace->artisan('mod:model', ['name' => 'Invoice'])
            ->expectsOutputToContain('mod:model needs a module. Pass --module=<module>, --in=<module>, or prefix the name: <module>:Invoice.')
            ->assertFailed();

        expect($workspace->files())->toBe([]);
    });
});

it('rejects a placement value the rule does not read', function () {
    Workspace::run('vertical-slices', function (Workspace $workspace) {
        $workspace->artisan('mod:model', ['name' => 'Invoice', '--in' => 'Billing/CreateInvoice'])
            ->expectsOutputToContain('mod:model does not use a slice in this layout. Leave the slice out.')
            ->assertFailed();

        expect($workspace->files())->toBe([]);
    });
});

it('rejects --in in a layout without dimensions', function () {
    Workspace::run('ordinary', function (Workspace $workspace) {
        $workspace->artisan('mod:model', ['name' => 'Invoice', '--in' => 'Billing'])->assertFailed();

        expect($workspace->files())->toBe([]);
    });
});

it('rejects too many or malformed placement values', function () {
    Workspace::run('modules', function (Workspace $workspace) {
        $workspace->artisan('mod:model', ['name' => 'Invoice', '--in' => 'Billing/Extra'])->assertFailed();
        $workspace->artisan('mod:model', ['name' => 'Invoice', '--in' => 'billing-module'])->assertFailed();

        expect($workspace->files())->toBe([]);
    });
});

it('rejects a nested name with the --in hint', function () {
    Workspace::run('modules', function (Workspace $workspace) {
        $workspace->artisan('mod:model', ['name' => 'Billing/Invoice'])
            ->expectsOutputToContain('--in=<module>')
            ->assertFailed();

        $workspace->artisan('mod:policy', ['name' => 'InvoicePolicy', '--in' => 'Billing', '--model' => 'Billing/Invoice'])
            ->assertFailed();

        expect($workspace->files())->toBe([]);
    });
});

it('refuses --path on migrations', function () {
    Workspace::run('ordinary', function (Workspace $workspace) {
        $workspace->artisan('mod:migration', ['name' => 'create_invoices_table', '--path' => 'elsewhere'])
            ->expectsOutputToContain('use --in instead of --path/--realpath')
            ->assertFailed();

        expect($workspace->files())->toBe([]);
    });
});
