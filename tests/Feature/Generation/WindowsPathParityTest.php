<?php

use Illuminate\Foundation\Console\RuleMakeCommand;
use Tey\Mod\Commands\Concerns\PlacesGeneratedClass;
use Tey\Mod\Generation\GeneratorAdapter;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;

class BackslashNativeRuleCommand extends RuleMakeCommand
{
    protected function getPath($name)
    {
        return str_replace('/', '\\', parent::getPath($name));
    }
}

class BackslashPlacedRuleCommand extends BackslashNativeRuleCommand implements GeneratorAdapter
{
    use PlacesGeneratedClass;
}

it('delegates duplicates to the native generator with backslash native paths', function () {
    Workspace::run(null, function (Workspace $workspace) {
        config()->set('mod.generators.rule', BackslashPlacedRuleCommand::class);
        $workspace->artisan('mod:rule', ['name' => 'SeparatorRule'])->assertSuccessful();
        $before = $workspace->read('app/Rules/SeparatorRule.php');
        $workspace->artisan('mod:rule', ['name' => 'SeparatorRule'])
            ->expectsOutputToContain('Rule already exists.')
            ->doesntExpectOutputToContain('SeparatorRule.php already exists.')
            ->assertSuccessful();
        expect($workspace->read('app/Rules/SeparatorRule.php'))->toBe($before);
        $workspace->artisan('mod:rule', ['name' => 'SeparatorRule', '--force' => true])->assertSuccessful();
    });
});
