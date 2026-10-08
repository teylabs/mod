<?php

use Pest\TestSuite;
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
        // Two folders that differ only by case exist only on a case-sensitive disk.
        app()->bind(GroupFolders::class, fn ($app, array $parameters) => new GroupFolders(
            $parameters['basePath'],
            fn (string $directory): array => str_ends_with($directory, 'app/Modules') ? ['KNOWLEDGE', 'Knowledge'] : [],
        ));

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
