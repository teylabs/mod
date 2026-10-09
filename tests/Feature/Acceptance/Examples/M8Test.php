<?php

use Tey\Mod\Tests\Feature\Acceptance\Examples\Support\CreationScenario;
use Tey\Mod\Tests\Feature\Acceptance\Examples\Support\TemplateScenario;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;

it('M8 refuses @module/Reactions/reaction without a terminal and writes nothing', function () {
    Workspace::run(null, function (Workspace $w) {
        CreationScenario::setup($w);
        $result = $w->artisan('mod:template', ['type' => 'listener', 'path' => 'reaction'])->assertFailed();
        expect($result->normalisedOutput())->toBe(CreationScenario::errors(['The listener stub needs an event ({{ event }}), which a template can\'t supply.', 'Start from a class instead (mod:template class reaction), or use ->generates() with mod\'s listener command.']))->and($w->files())->toBe([]);
    });
});

it('M8 accepts its correction in a terminal for @module/Reactions/reaction', function () {
    Workspace::run(null, function (Workspace $w) {
        CreationScenario::setup($w);
        TemplateScenario::testCase()->artisan('mod:template', ['type' => 'listener', 'path' => 'reaction'])
            ->expectsConfirmation('The listener stub needs an event, which a template can\'t supply. Start from a class instead?', 'yes')
            ->assertSuccessful();
        expect($w->files())->toBe(['stubs/mod/@module/Reactions/reaction.stub']);
        expect(str_replace("\r\n", "\n", $w->read('stubs/mod/@module/Reactions/reaction.stub')))->toBe(CreationScenario::fixture('class_template'));
    });
});

it('M8 keeps markdown a plain-file error in both modes', function (bool $interactive) {
    Workspace::run(null, function (Workspace $w) use ($interactive) {
        CreationScenario::setup($w);
        $args = ['type' => 'markdown', 'path' => '@module/Docs/readme'];
        if ($interactive) {
            TemplateScenario::testCase()->artisan('mod:template', $args)->expectsOutputToContain("Plain-file templates aren't supported yet; mod:template makes class templates.")->assertFailed();
        } else {
            expect($w->artisan('mod:template', $args)->assertFailed()->normalisedOutput())->toBe(CreationScenario::error("Plain-file templates aren't supported yet; mod:template makes class templates."));
        }
        expect($w->files())->toBe([]);
    });
})->with([true, false]);
