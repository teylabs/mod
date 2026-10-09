<?php

use Tey\Mod\Tests\Feature\Acceptance\Examples\Support\FrontendScenario as Frontend;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;

it('F12 writes Markdown below an arbitrary resources folder with headline forms', function () {
    Workspace::run(null, function (Workspace $w) {
        Frontend::setup($w);
        $w->write('stubs/mod/@module/resources/prompts/prompt.md.stub', Frontend::source('F12', 'stubs/mod/@module/resources/prompts/prompt.md.stub'));
        $path = 'app/Modules/Agents/resources/prompts/answer-question.md';
        $result = $w->artisan('mod:prompt', ['name' => 'Agents:AnswerQuestion'])->assertSuccessful();
        expect($w->read($path))->toBe(Frontend::source('F12', $path))
            ->and($result->normalisedOutput())->toContain("Prompt [{$path}] created successfully.");
    });
});
