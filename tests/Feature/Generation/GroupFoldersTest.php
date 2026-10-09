<?php

use Illuminate\Support\Facades\Artisan;
use Pest\TestSuite;
use Symfony\Component\Console\Output\BufferedOutput;
use Tey\Mod\Facades\Mod;
use Tey\Mod\Generation\GroupFolders;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;
use Tey\Mod\Tests\TestCase;

/*
 * A placement value that differs from an existing group folder only by case
 * stops with a hint (on a case-insensitive disk it would land in the other
 * folder, on Linux it would not autoload). A value that creates a new group
 * folder says so, naming the groups that exist.
 */

/**
 * The running test case, for interactive commands (PendingCommand answers prompts).
 */
function groupFoldersCase(): TestCase
{
    $case = TestSuite::getInstance()->test;

    return $case instanceof TestCase ? $case : throw new RuntimeException('Needs the Testbench test case.');
}

/**
 * Whether the workspace's disk tells folder names apart by case (Linux, a
 * case-sensitive APFS volume), as a server can.
 */
function caseSensitive(Workspace $workspace): bool
{
    $workspace->write('case-probe/a.txt', '');
    $sensitive = ! file_exists($workspace->root->path('case-probe/A.txt'));
    $workspace->remove(['case-probe/a.txt']);

    return $sensitive;
}

it('uses the one existing group that differs only by case, and says so', function () {
    Workspace::run(null, function (Workspace $workspace) {
        config()->set('mod.layout', 'modules');
        $workspace->write('app/Modules/Knowledge/Models/Document.php', '<?php // mine');

        $result = $workspace->artisan('mod:model', ['name' => 'knowledge:Note']);

        expect($result->exitCode)->toBe(0)
            ->and($result->output)->toContain('Using existing module Knowledge (you typed knowledge).')
            ->and($workspace->read('app/Modules/Knowledge/Models/Note.php'))->toContain('namespace App\\Modules\\Knowledge\\Models;')
            ->and($workspace->files())->toBe(['app/Modules/Knowledge/Models/Document.php', 'app/Modules/Knowledge/Models/Note.php']);

        // Interactively too: an unambiguous answer needs no question.
        groupFoldersCase()->artisan('mod:model', ['name' => 'KNOWLEDGE:Page'])
            ->expectsOutputToContain('Using existing module Knowledge (you typed KNOWLEDGE).')
            ->assertSuccessful();
    });
});

it('asks which group is meant when several differ only by case, and stops when not interactive', function () {
    Workspace::run(null, function (Workspace $workspace) {
        config()->set('mod.layout', 'modules');
        $workspace->write('app/Modules/Knowledge/Models/Document.php', '<?php // mine');

        if (caseSensitive($workspace)) {
            // Linux: both groups really exist, each holding the layout.
            $workspace->write('app/Modules/KNOWLEDGE/Models/Page.php', '<?php // mine');
        } else {
            // A case-insensitive disk cannot hold both: list them; KNOWLEDGE resolves to Knowledge on disk.
            app()->bind(GroupFolders::class, fn ($app, array $parameters) => new GroupFolders(
                $parameters['basePath'],
                fn (string $directory): array => str_ends_with($directory, 'app/Modules') ? ['KNOWLEDGE', 'Knowledge'] : [],
            ));
        }

        $stopped = $workspace->artisan('mod:model', ['name' => 'knowledge:Note']);

        expect($stopped->exitCode)->toBe(1)
            ->and($stopped->output)->toContain("Module [knowledge] doesn't exist; did you mean [KNOWLEDGE] or [Knowledge]?")
            ->and($workspace->exists('app/Modules/Knowledge/Models/Note.php'))->toBeFalse();

        groupFoldersCase()->artisan('mod:model', ['name' => 'knowledge:Note'])
            ->expectsChoice("Module [knowledge] doesn't exist. Which one did you mean?", 'Knowledge', ['KNOWLEDGE', 'Knowledge', 'Cancel'])
            ->assertSuccessful();

        groupFoldersCase()->artisan('mod:model', ['name' => 'knowledge:Page'])
            ->expectsChoice("Module [knowledge] doesn't exist. Which one did you mean?", 'Cancel', ['KNOWLEDGE', 'Knowledge', 'Cancel'])
            ->assertFailed();

        expect($workspace->exists('app/Modules/Knowledge/Models/Note.php'))->toBeTrue()
            ->and($workspace->exists('app/Modules/Knowledge/Models/Page.php'))->toBeFalse();
    });
});

it('says when a placement creates a new group, naming the existing ones', function () {
    Workspace::run(null, function (Workspace $workspace) {
        config()->set('mod.layout', 'modules');

        expect($workspace->artisan('mod:model', ['name' => 'Knowledge:Document'])->output)->toContain('Created new module Knowledge.');

        $workspace->write('app/Modules/Agents/Models/Conversation.php', '<?php // mine');
        $typo = $workspace->artisan('mod:model', ['name' => 'Knowledg:Document']);

        expect($typo->exitCode)->toBe(0)
            ->and($typo->output)->toContain('Created new module Knowledg (existing: Agents, Knowledge).')
            ->and($workspace->artisan('mod:model', ['name' => 'Knowledge:Note'])->output)->not->toContain('Created new module');
    });
});

it('checks every folder of a nested group', function () {
    Workspace::run(null, function (Workspace $workspace) {
        config()->set('mod.layout', 'ddd');
        $workspace->write('src/Domain/Reporting/Internal/Models/Report.php', '<?php // mine');

        $wrongCase = $workspace->artisan('mod:model', ['name' => 'Chart', '--domain' => 'Reporting.internal']);

        expect($wrongCase->exitCode)->toBe(0)
            ->and($wrongCase->output)->toContain('Using existing domain Reporting/Internal (you typed Reporting/internal).')
            ->and($workspace->exists('src/Domain/Reporting/Internal/Models/Chart.php'))->toBeTrue()
            ->and($workspace->artisan('mod:model', ['name' => 'Chart', '--domain' => 'Reporting.External'])->output)
            ->toContain('Created new domain Reporting/External (existing: Internal).');
    });
});

it('offers the close groups for a near miss, or creating the new one', function () {
    Workspace::run(null, function (Workspace $workspace) {
        config()->set('mod.layout', 'modules');
        $workspace->write('app/Modules/Knowledge/Models/Document.php', '<?php // mine');
        $workspace->write('app/Modules/Agents/Models/Conversation.php', '<?php // mine');

        groupFoldersCase()->artisan('mod:model', ['name' => 'Knowledg:Note'])
            ->expectsChoice("Module [Knowledg] doesn't exist. Did you mean an existing one?", 'Knowledge', ['Knowledge', 'Create new module Knowledg'])
            ->assertSuccessful();

        groupFoldersCase()->artisan('mod:model', ['name' => 'Agent:Prompt'])
            ->expectsChoice("Module [Agent] doesn't exist. Did you mean an existing one?", 'Create new module Agent', ['Agents', 'Create new module Agent'])
            ->expectsOutputToContain('Created new module Agent (existing: Agents, Knowledge).')
            ->assertSuccessful();

        expect($workspace->exists('app/Modules/Knowledge/Models/Note.php'))->toBeTrue()
            ->and($workspace->exists('app/Modules/Agent/Models/Prompt.php'))->toBeTrue()
            ->and($workspace->exists('app/Modules/Knowledg'))->toBeFalse();
    });
});

it('asks nothing about a clearly new group', function () {
    Workspace::run(null, function (Workspace $workspace) {
        config()->set('mod.layout', 'modules');
        $workspace->write('app/Modules/Knowledge/Models/Document.php', '<?php // mine');

        groupFoldersCase()->artisan('mod:model', ['name' => 'Billing:Invoice'])
            ->expectsOutputToContain('Created new module Billing (existing: Knowledge).')
            ->assertSuccessful();
    });
});

it('asks at every level of a nested group', function () {
    Workspace::run(null, function (Workspace $workspace) {
        config()->set('mod.layout', 'ddd');
        $workspace->write('src/Domain/Reporting/Internal/Models/Report.php', '<?php // mine');

        groupFoldersCase()->artisan('mod:model', ['name' => 'Chart', '--domain' => 'reporting.Internl'])
            ->expectsOutputToContain('Using existing domain Reporting/Internl (you typed reporting/Internl).')
            ->expectsChoice("Domain [Reporting/Internl] doesn't exist. Did you mean an existing one?", 'Reporting/Internal', ['Reporting/Internal', 'Create new domain Reporting/Internl'])
            ->assertSuccessful();

        expect($workspace->exists('src/Domain/Reporting/Internal/Models/Chart.php'))->toBeTrue();
    });
});

it('creates a near miss without asking when not interactive', function () {
    Workspace::run(null, function (Workspace $workspace) {
        config()->set('mod.layout', 'modules');
        $workspace->write('app/Modules/Knowledge/Models/Document.php', '<?php // mine');

        $result = $workspace->artisan('mod:model', ['name' => 'Knowledg:Note']);

        expect($result->exitCode)->toBe(0)
            ->and($result->output)->toContain('Created new module Knowledg (existing: Knowledge).');
    });
});

/*
 * Only folders that hold the layout's files count as groups: Laravel's own
 * app/Http or app/Models are not features, a feature's shared Models folder
 * is not a slice.
 */

it('counts only folders that hold the layout as groups', function () {
    Workspace::run(null, function (Workspace $workspace) {
        config()->set('mod.layout', 'slices');
        $workspace->write('app/Http/Controllers/Controller.php', '<?php // Laravel');
        $workspace->write('app/Models/User.php', '<?php // Laravel');
        $workspace->write('app/Providers/AppServiceProvider.php', '<?php // Laravel');

        expect($workspace->artisan('mod:model', ['name' => 'Knowledge:Document'])->output)->toContain('Created new feature Knowledge.');

        $workspace->write('app/Knowledge/Database/Factories/DocumentFactory.php', '<?php // mine');
        $slice = $workspace->artisan('mod:handler', ['--in' => 'Knowledge/IndexDocument']);

        expect($slice->output)->toContain('Created new slice IndexDocument.')
            ->and($workspace->artisan('mod:handler', ['--in' => 'Knowledge/CreateDocument'])->output)
            ->toContain('Created new slice CreateDocument (existing: IndexDocument).')
            ->and($workspace->artisan('mod:model', ['name' => 'Billing:Invoice'])->output)
            ->toContain('Created new feature Billing (existing: Knowledge).');
    });
});

it('does not count the kind folders inside a group as nested groups', function () {
    Workspace::run(null, function (Workspace $workspace) {
        config()->set('mod.layout', 'ddd');
        // A root with classes placed right in the group as well, as laravel-ddd's application root has.
        Mod::layout('ddd')->generates('application-root', in: 'application:{domain+}', nested: true, command: false, priority: 0);
        $workspace->write('app/Modules/Billing/Http/Controllers/InvoiceController.php', '<?php // mine');
        $workspace->write('app/Modules/Billing/Http/Requests/StoreInvoiceRequest.php', '<?php // mine');
        $workspace->write('app/Modules/Billing/Internal/Http/Controllers/AuditController.php', '<?php // mine');

        $result = $workspace->artisan('mod:controller', ['name' => 'ReportController', '--domain' => 'Billing.Reports']);

        expect($result->exitCode)->toBe(0)
            ->and($result->output)->toContain('Created new domain Billing/Reports (existing: Internal).')
            ->and($workspace->exists('app/Modules/Billing/Reports/Http/Controllers/ReportController.php'))->toBeTrue();
    });
});
it('matches near misses against the same groups only', function () {
    Workspace::run(null, function (Workspace $workspace) {
        config()->set('mod.layout', 'slices');
        $workspace->write('app/Http/Controllers/Controller.php', '<?php // Laravel');

        // Htp is no near miss of Laravel's Http folder: no question.
        groupFoldersCase()->artisan('mod:model', ['name' => 'Htp:Thing'])
            ->expectsOutputToContain('Created new feature Htp.')
            ->assertSuccessful();
    });
});

it('prints the new-group notice once per command, not once per related file', function () {
    Workspace::run(null, function (Workspace $workspace) {
        config()->set('mod.layout', 'type-first');

        $result = $workspace->artisan('mod:model', ['name' => 'Knowledge:Document', '--migration' => true, '--factory' => true, '--policy' => true]);

        expect($result->exitCode)->toBe(0)
            ->and(substr_count($result->output, 'Created new feature Knowledge'))->toBe(1);
    });
});

it('says nothing about a new group when the command then refuses', function (string $command, array $parameters) {
    Workspace::run(null, function (Workspace $workspace) use ($command, $parameters) {
        config()->set('mod.layout', 'ddd');
        $workspace->write('src/Domain/Knowledge/Models/Document.php', '<?php // mine');

        // Some native refusals throw: keep what was printed before.
        $buffer = new BufferedOutput;

        try {
            $exitCode = Artisan::call($command, [...$parameters, '--no-interaction' => true], $buffer);
        } catch (Throwable) {
            $exitCode = 1;
        }

        $output = $buffer->fetch();

        expect($exitCode)->not->toBe(0)
            ->and($output)->not->toContain('Created new domain')
            ->and($workspace->files())->toBe(['src/Domain/Knowledge/Models/Document.php']);
    });
})->with([
    'policy with an unknown guard' => ['mod:policy', ['name' => 'Billing:InvoicePolicy', '--guard' => 'nope']],
    'observer of an invalid model' => ['mod:observer', ['name' => 'Billing:InvoiceObserver', '--model' => 'Not A Model']],
    'controller of an unknown type' => ['mod:controller', ['name' => 'Billing:InvoiceController', '--type' => 'nope']],
]);
