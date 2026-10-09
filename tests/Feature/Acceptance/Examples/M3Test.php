<?php

use Tey\Mod\Tests\Feature\Acceptance\Examples\Support\CreationScenario;
use Tey\Mod\Tests\Feature\Acceptance\Examples\Support\TemplateScenario;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;

it('M3 refuses overwriting without force', function () {
    Workspace::run(null, function (Workspace $w) {
        CreationScenario::setup($w);
        $w->write('stubs/mod/@module/Tools/tool.stub', 'original');
        $r = $w->artisan('mod:template', ['type' => 'tool'])->assertFailed();
        expect($r->normalisedOutput())->toBe(CreationScenario::error('Template [stubs/mod/@module/Tools/tool.stub] already exists. Use --force to overwrite it.'))
            ->and(str_replace("\r\n", "\n", $w->read('stubs/mod/@module/Tools/tool.stub')))->toBe('original');
        $w->artisan('mod:template', ['type' => 'tool', '--force' => true])->assertSuccessful();
        expect(str_replace("\r\n", "\n", $w->read('stubs/mod/@module/Tools/tool.stub')))->toBe(CreationScenario::fixture('class_template'));
    });
});

it('M3 asks before overwriting and honours both answers', function (string $answer) {
    Workspace::run(null, function (Workspace $w) use ($answer) {
        CreationScenario::setup($w);
        $w->write('stubs/mod/@module/Tools/tool.stub', 'original');
        $cmd = TemplateScenario::testCase()->artisan('mod:template', ['type' => 'tool'])
            ->expectsConfirmation('Template [stubs/mod/@module/Tools/tool.stub] already exists. Overwrite it?', $answer);
        $answer === 'yes' ? $cmd->assertSuccessful() : $cmd->assertFailed();
        $cmd->run();
        expect(str_replace("\r\n", "\n", $w->read('stubs/mod/@module/Tools/tool.stub')))->toBe($answer === 'yes' ? CreationScenario::fixture('class_template') : 'original');
    });
})->with(['yes', 'no']);
