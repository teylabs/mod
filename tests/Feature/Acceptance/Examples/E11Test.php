<?php

use Composer\Autoload\ClassLoader;
use Tey\Mod\Facades\Mod;
use Tey\Mod\Tests\Feature\Acceptance\Examples\Support\TemplateScenario;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;

it('E11 resolves a package group anchor in modules and ddd', function (string $layout, string $folder, string $namespace) {
    putenv('COLUMNS=72');
    Workspace::run(null, function (Workspace $workspace) use ($layout, $folder, $namespace) {
        config()->set('mod.layout', $layout);
        $workspace->write('vendor/acme/agent-kit/stubs/mod/@group/Prompts/prompt.stub', TemplateScenario::CLASS_STUB);
        Mod::stubs()->folder($workspace->root->path('vendor/acme/agent-kit/stubs/mod'));
        mkdir($workspace->root->path($folder.'/Agents/Prompts'), 0700, true);
        $loader = new ClassLoader;
        $loader->addPsr4($namespace.'\\', $workspace->root->path($folder));
        $loader->register();
        try {
            $result = $workspace->artisan('mod:prompt', ['name' => 'Agents:AnswerQuestion'])->assertSuccessful();
            expect($result->normalisedOutput())->toBe("\n   INFO  Prompt [{$folder}/Agents/Prompts/AnswerQuestion.php] created successfully.  \n\n")
                ->and(str_replace("\r\n", "\n", $workspace->read($folder.'/Agents/Prompts/AnswerQuestion.php')))
                ->toBe(TemplateScenario::content($namespace.'\\Agents\\Prompts', 'AnswerQuestion'));
        } finally {
            $loader->unregister();
        }
    });
})->with([['modules', 'app/Modules', 'App\\Modules'], ['ddd', 'src/Domain', 'Domain']]);
