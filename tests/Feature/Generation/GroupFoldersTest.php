<?php

use Tey\Mod\Tests\Feature\Generation\Support\Workspace;

/*
 * A placement value that differs from an existing group folder only by case
 * stops with a hint (on a case-insensitive disk it would land in the other
 * folder, on Linux it would not autoload). A value that creates a new group
 * folder says so, naming the groups that exist.
 */

it('stops on a group that differs from an existing one only by case', function () {
    Workspace::run(null, function (Workspace $workspace) {
        config()->set('mod.layout', 'modules');
        $workspace->write('app/Modules/Knowledge/Models/Document.php', '<?php // mine');

        $result = $workspace->artisan('mod:model', ['name' => 'knowledge:Note']);

        expect($result->exitCode)->toBe(1)
            ->and($result->output)->toContain("Module [knowledge] doesn't exist; did you mean [Knowledge]?")
            ->and($workspace->files())->toBe(['app/Modules/Knowledge/Models/Document.php']);
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

        expect($wrongCase->exitCode)->toBe(1)
            ->and($wrongCase->output)->toContain("Domain [Reporting/internal] doesn't exist; did you mean [Reporting/Internal]?")
            ->and($workspace->artisan('mod:model', ['name' => 'Chart', '--domain' => 'Reporting.External'])->output)
            ->toContain('Created new domain Reporting/External (existing: Internal).');
    });
});
