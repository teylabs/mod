<?php

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;
use Tey\Mod\Tests\Feature\Scaffolds\Support\Examples;
use Tey\Mod\Tests\Feature\Scaffolds\Support\TreeExamples;
use Tey\Mod\Tests\Support\BoostCoverage;

it('covers every registered command and option with Boost guidance', function (string $layout) {
    Workspace::run(null, function (Workspace $w) use ($layout) {
        config()->set('mod.layout', $layout);
        config()->set('mod.discovery.enabled', true);
        if ($layout === 'modules') {
            Examples::setup($w);
            TreeExamples::setup($w);
            $w->write('stubs/mod/@module/Tools/[source]/probe.stub', "<?php\nnamespace {{ namespace }};\nclass {{ class }} {}\n");
        }
        $skill = file_get_contents(dirname(__DIR__, 2).'/resources/boost/skills/mod-development/SKILL.md');
        $guideline = file_get_contents(dirname(__DIR__, 2).'/resources/boost/guidelines/core.blade.php');
        $commands = Artisan::all();
        if ($layout === 'modules') {
            expect($commands)->toHaveKeys(['mod:crud', 'mod:resource-tabs', 'mod:resource-tabs.tab', 'mod:tab-page', 'mod:probe']);
        }
        $missing = BoostCoverage::missing($commands, $skill, $guideline);
        expect($missing)->toBe([], implode("\n", $missing));
    });
})->with(['laravel', 'modules', 'features', 'slices', 'type-first', 'ddd']);

it('does not let another command or a longer option cover an undocumented flag', function () {
    $command = new class extends Command
    {
        protected $signature = 'mod:index-filter {--fields=}';
    };
    expect(BoostCoverage::missing([$command], 'mod:index-filter --fields-extra, mod:other --fields', ''))
        ->toBe(["mod:index-filter --fields isn't mentioned in resources/boost/skills/mod-development/SKILL.md."]);
});
