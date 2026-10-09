<?php

use Symfony\Component\Process\Process;
use Tey\Mod\Facades\Mod;
use Tey\Mod\Tests\Feature\Acceptance\Examples\Support\CreationScenario;
use Tey\Mod\Tests\Feature\Acceptance\Examples\Support\TemplateScenario;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;

it('prompts for both missing arguments with class preselected', function () {
    Workspace::run(null, function (Workspace $w) {
        CreationScenario::setup($w);
        TemplateScenario::testCase()->artisan('mod:template')
            ->expectsQuestion('Which type should the template start from?', 'class')
            ->expectsChoice('Which type should the template start from?', 'class', ['class'])
            ->expectsQuestion('What should the template be called, or where should it live?', '@module/Tools/tool')->assertSuccessful();
        expect(str_replace("\r\n", "\n", $w->read('stubs/mod/@module/Tools/tool.stub')))->toBe(CreationScenario::fixture('class_template'));
    });
});

it('creates a template with a literal slot folder on Windows too', function () {
    Workspace::run(null, function (Workspace $w) {
        CreationScenario::setup($w);
        $r = $w->artisan('mod:template', ['type' => 'job', 'path' => '@module/Webhooks/[source]/webhook'])->assertSuccessful();
        expect($r->normalisedOutput())->toBe(CreationScenario::output('@module/Webhooks/[source]/webhook', [
            'Starts as' => 'the job stub', 'Command' => 'mod:webhook', 'Options' => '--source',
            'Writes' => 'app/Modules/<module>/Webhooks/<source>/<Name>.php', 'Try' => 'php artisan mod:webhook Agents:<Name> --source=<source>',
        ]))->and(str_replace("\r\n", "\n", $w->read('stubs/mod/@module/Webhooks/[source]/webhook.stub')))->toBe(CreationScenario::fixture('job_template'));
    });
});

it('refuses contextual native stubs but accepts a published stub with no extra requirements', function (string $type) {
    Workspace::run(null, function (Workspace $w) use ($type) {
        CreationScenario::setup($w);
        $w->artisan('mod:template', ['type' => $type, 'path' => 'custom'])->assertFailed();
        expect($w->files())->toBe([]);
        $w->write('stubs/mod.'.$type.'.stub', TemplateScenario::CLASS_STUB);
        $w->artisan('mod:template', ['type' => $type, 'path' => 'custom'])->assertSuccessful();
        expect(str_replace("\r\n", "\n", $w->read('stubs/mod/@module/Customs/custom.stub')))->toBe(TemplateScenario::CLASS_STUB);
    });
})->with(['listener', 'controller', 'model', 'factory', 'policy', 'test', 'observer']);

it('uses published language stubs and copies accepted templates', function () {
    Workspace::run(null, function (Workspace $w) {
        CreationScenario::setup($w);
        $w->write('stubs/interface.stub', "<?php\nnamespace {{ namespace }};\ninterface {{ class }} { /* house */ }\n");
        $w->write('stubs/mod/@module/Tools/tool.stub', TemplateScenario::CLASS_STUB);
        $w->artisan('mod:template', ['type' => 'interface', 'path' => 'contract'])->assertSuccessful();
        expect(str_replace("\r\n", "\n", $w->read('stubs/mod/@module/Contracts/contract.stub')))->toBe(str_replace("\r\n", "\n", $w->read('stubs/interface.stub')));
        $w->artisan('mod:template', ['type' => 'tool', 'path' => 'helper'])->assertSuccessful();
        expect(str_replace("\r\n", "\n", $w->read('stubs/mod/@module/Helpers/helper.stub')))->toBe(TemplateScenario::CLASS_STUB);
    });
});

it('extracts a mismatched filename, generates into Agents, and lints the round trip', function () {
    Workspace::run(null, function (Workspace $w) {
        CreationScenario::setup($w);
        $w->write('app/Modules/Knowledge/Tools/Unexpected.php', "<?php\ndeclare(strict_types=1);\nnamespace App\\Modules\\Knowledge\\Tools;\nclass Original { public function copy(): Original { return new Original; } }\n");
        $w->artisan('mod:template', ['--from' => 'Original', '--into' => '@module/Tools/copied'])->assertSuccessful();
        CreationScenario::rebootConsole();
        $w->artisan('mod:copied', ['name' => 'Agents:Replica'])->assertSuccessful();
        $file = 'app/Modules/Agents/Tools/Replica.php';
        expect(str_replace("\r\n", "\n", $w->read($file)))->toBe("<?php\ndeclare(strict_types=1);\nnamespace App\\Modules\\Agents\\Tools;\nclass Replica { public function copy(): Replica { return new Replica; } }\n")
            ->and(class_exists('App\\Modules\\Knowledge\\Tools\\Original', false))->toBeFalse();
        $lint = new Process([PHP_BINARY, '-l', $w->root->path($file)]);
        $lint->run();
        expect($lint->isSuccessful())->toBeTrue($lint->getErrorOutput());
    });
});

it('copies a template that uses its groups and slots', function () {
    Workspace::run(null, function (Workspace $w) {
        CreationScenario::setup($w);
        $contents = "<?php\nnamespace {{ namespace }};\nclass {{ class }} { const GROUP = '{{ module }}'; const SOURCE = '{{ source }}'; }\n";
        $w->write('stubs/mod/@module/Webhooks/[source]/webhook.stub', $contents);
        $w->artisan('mod:template', ['type' => 'webhook', 'path' => '@module/Webhooks/[source]/reaction'])->assertSuccessful();
        expect(str_replace("\r\n", "\n", $w->read('stubs/mod/@module/Webhooks/[source]/reaction.stub')))->toBe($contents);
    });
});

it('rejects aliases and normalized command collisions before writing', function (string $path) {
    Workspace::run(null, function (Workspace $w) use ($path) {
        CreationScenario::setup($w);
        $layout = Mod::layout('modules');
        $layout->generates('record', in: 'Modules/{module}/Records', aliases: ['mod:house-record']);
        $w->artisan('mod:template', ['type' => $path])->assertFailed();
        expect($w->files())->toBe([]);
    });
})->with(['house-record', 'houseRecord']);

it('uses the group token of an extended layout for bare names', function () {
    Workspace::run(null, function (Workspace $w) {
        CreationScenario::setup($w);
        Mod::layout('areas')->extends('modules')->path('src/Areas/{area}');
        config()->set('mod.layout', 'areas');
        $w->artisan('mod:template', ['type' => 'tool'])->assertSuccessful();
        expect($w->files())->toBe(['stubs/mod/@area/Tools/tool.stub'])
            ->and(str_replace("\r\n", "\n", $w->read('stubs/mod/@area/Tools/tool.stub')))->toBe(CreationScenario::fixture('class_template'));
    });
});

it('does not write broken paths or a second anchor even with force', function (string $path) {
    Workspace::run(null, function (Workspace $w) use ($path) {
        CreationScenario::setup($w);
        $w->artisan('mod:template', ['type' => 'class', 'path' => $path, '--force' => true])->assertFailed();
        expect($w->files())->toBe([]);
    });
})->with(['../escape', '/tmp/escape', '@module/@domain/tool', '@module/[force]/tool', '@module/[module]/tool', '@module/[source]/[source]/tool']);

it('maps extraction suggestions through the layout including nested and wildcard groups', function (string $layout, string $sourcePath, string $destination) {
    Workspace::run(null, function (Workspace $w) use ($layout, $sourcePath, $destination) {
        CreationScenario::setup($w, $layout);
        $w->write($sourcePath, "<?php\nnamespace App\\Source;\nclass Process {}\n");
        $w->artisan('mod:template', ['--from' => $sourcePath])->assertSuccessful();
        expect($w->exists('stubs/mod/'.$destination.'.stub'))->toBeTrue(implode(', ', $w->files()));
    });
})->with([
    ['ddd', 'src/Domain/Agents/Chat/Actions/Process.php', '@domain/Actions/process'],
    ['ddd', 'app/Modules/Agents/Presenters/Process.php', 'Modules/@domain/Presenters/process'],
    ['type-first', 'app/Tools/Agents/Process.php', '@feature/Tools/process'],
    ['slices', 'app/Knowledge/IndexDocument/Pages/Process.php', '@slice/Pages/process'],
    ['modules', 'app/Modules/Knowledge/Webhooks/Drive/Process.php', '@module/Webhooks/Drive/process'],
    ['laravel', 'app/Support/Tools/Process.php', 'Support/Tools/process'],
]);

it('refuses a copied template when the destination cannot supply its slot', function () {
    Workspace::run(null, function (Workspace $w) {
        CreationScenario::setup($w);
        $w->write('stubs/mod/@module/Webhooks/[source]/webhook.stub', "<?php\nnamespace {{ namespace }};\nclass {{ class }} { const SOURCE = '{{ source }}'; }\n");
        $w->artisan('mod:template', ['type' => 'webhook', 'path' => '@module/Webhooks/reaction'])->assertFailed();
        expect($w->files())->toBe(['stubs/mod/@module/Webhooks/[source]/webhook.stub']);
    });
});

it('keeps non-class layout file types out of template creation', function (string $type) {
    Workspace::run(null, function (Workspace $w) use ($type) {
        CreationScenario::setup($w, 'laravel');
        $w->artisan('mod:template', ['type' => $type, 'path' => 'custom'])->assertFailed()
            ->expectsOutputToContain("Plain-file templates aren't supported yet; mod:template makes class templates.");
        expect($w->files())->toBe([]);
    });
})->with(['config', 'migration']);
