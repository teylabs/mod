<?php

use Composer\Autoload\ClassLoader;
use Tey\Mod\Facades\Mod;
use Tey\Mod\Support\ComposerJson;
use Tey\Mod\Tests\Feature\Acceptance\Examples\Support\TemplateScenario;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;

it('E11 resolves a package group anchor in modules and ddd', function (string $layout, string $folder, string $namespace) {
    putenv('COLUMNS=72');
    Workspace::run(null, function (Workspace $workspace) use ($layout, $folder, $namespace) {
        config()->set('mod.layout', $layout);
        $workspace->write('vendor/acme/agent-kit/stubs/mod/@group/Prompts/prompt.stub', TemplateScenario::CLASS_STUB);
        Mod::stubs()->folder($workspace->root->path('vendor/acme/agent-kit/stubs/mod'));
        mkdir($workspace->root->path($folder.'/Agents/Prompts'), 0700, true);
        $composer = new ComposerJson($workspace->root->path('composer.json'));
        $composer->register($namespace.'\\', $folder);
        $composer->save();
        $loader = new ClassLoader;
        $loader->addPsr4($namespace.'\\', $workspace->root->path($folder));
        $loader->register();
        try {
            $result = $workspace->artisan('mod:prompt', ['name' => 'Agents:AnswerQuestion'])->assertSuccessful();
            expect($result->normalisedOutput())->toBe(TemplateScenario::normalise($workspace, "\n   INFO  Prompt [{$folder}/Agents/Prompts/AnswerQuestion.php] created successfully.  \n\n"))
                ->and(TemplateScenario::normalise($workspace, $workspace->read($folder.'/Agents/Prompts/AnswerQuestion.php')))
                ->toBe(TemplateScenario::normalise($workspace, TemplateScenario::content($namespace.'\\Agents\\Prompts', 'AnswerQuestion')));
        } finally {
            $loader->unregister();
        }
    });
})->with([['modules', 'app/Modules', 'App\\Modules'], ['ddd', 'src/Domain', 'Domain']]);

it('E11 lists a neutral package template with its source and resolved folder', function (string $layout, string $folder) {
    Workspace::run(null, function (Workspace $workspace) use ($layout, $folder) {
        config()->set('mod.layout', $layout);
        $workspace->write('vendor/acme/agent-kit/stubs/mod/@group/Prompts/prompt.stub', TemplateScenario::CLASS_STUB);
        Mod::stubs()->folder($workspace->root->path('vendor/acme/agent-kit/stubs/mod'));
        $data = json_decode($workspace->artisan('mod:list', ['--json' => true, '--type' => 'prompt'])->assertSuccessful()->output, true, flags: JSON_THROW_ON_ERROR);
        expect($data['types'][0]['source'])->toBe('template (acme/agent-kit)')
            ->and($data['types'][0]['folder'])->toBe($folder)
            ->and($data['types'][0]['stub'])->toBe('vendor/acme/agent-kit/stubs/mod/@group/Prompts/prompt.stub');
    });
})->with([['modules', 'app/Modules/{module}/Prompts'], ['ddd', 'src/Domain/{domain+}/Prompts']]);
