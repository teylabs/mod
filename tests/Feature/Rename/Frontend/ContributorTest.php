<?php

use Tey\Mod\Facades\Mod;
use Tey\Mod\Rename\Contributors;
use Tey\Mod\Rename\Frontend\Frontend;
use Tey\Mod\Rename\Frontend\Runner;
use Tey\Mod\Rename\Frontend\RunResult;
use Tey\Mod\Rename\Planner;
use Tey\Mod\Rename\Request;
use Tey\Mod\Rename\Snapshot;
use Tey\Mod\Scaffolds\Scaffold;
use Tey\Mod\Tests\Feature\Acceptance\Examples\Rename\Support\RenameScenario as S;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;

it('validates a controlled parsed response through the planner and snapshots read dependencies', function () {
    Workspace::run(null, function (Workspace $w) {
        S::setup($w);
        Mod::scaffold('pages', fn (Scaffold $s) => $s->makes('page', name: '{name}/Show'));
        $file = 'resources/js/consumer.ts';
        $before = '@modules/Inventory/resources/js/pages/Widget/Show.vue';
        $after = str_replace('Widget', 'Gadget', $before);
        $source = "// 🍁\r\nimport Show from '{$before}';\r\nconst label = 'Inventory::Widget/Show';\r\n";
        $w->write($file, $source);
        $w->write('app/Modules/Inventory/resources/js/pages/Widget/Show.vue', '<template/>');
        $w->write('package.json', '{}');
        $runner = new class($w->root->path('package.json'), $file, $before, $after, $source) implements Runner
        {
            public function __construct(private string $dependency, private string $file, private string $before, private string $after, private string $source) {}

            public function run(string $basePath, string $input): RunResult
            {
                $payload = json_decode($input, true, flags: JSON_THROW_ON_ERROR);
                expect($payload['version'])->toBe(1)->and($payload['aliases']['@modules'])->toBe('app/Modules')->and($payload['paths']['app/Modules/Inventory/resources/js/pages/Widget/Show.vue'])->toBe('app/Modules/Inventory/resources/js/pages/Gadget/Show.vue');

                return new RunResult(json_encode(['version' => 1, 'edits' => [['file' => $this->file, 'offset' => strpos($this->source, $this->before), 'before' => $this->before, 'after' => $this->after, 'line' => 2, 'category' => 'frontend-import']], 'checklist' => [], 'failures' => [], 'dependencies' => [$this->dependency => hash_file('sha256', $this->dependency)]], JSON_THROW_ON_ERROR));
            }
        };
        app(Contributors::class)->set('frontend', new Frontend($runner));
        S::commit($w);
        $result = app(Planner::class)->build(new Request('Inventory:Widget', 'Inventory:Gadget', 'pages'));
        expect($result->plan->warnings)->toBe([])->and($result->bodies[$file])->toBe(str_replace($before, $after, $source))->and($result->inputs)->not->toBeNull();
        $snapshot = new Snapshot;
        expect($snapshot->unchanged($result->inputs, $result->inputs->definitionHash))->toBeTrue();
        $w->write('package.json', '{"changed":true}');
        expect($snapshot->unchanged($result->inputs, $result->inputs->definitionHash))->toBeFalse();
    });
});

it('unsafe or failed subprocess output gives a visible located fallback without any accepted edits', function (string $response) {
    Workspace::run(null, function (Workspace $w) use ($response) {
        S::setup($w);
        Mod::scaffold('pages', fn (Scaffold $s) => $s->makes('page', name: '{name}/Show'));
        $w->write('app/Modules/Inventory/resources/js/pages/Widget/Show.vue', "<script>\nimport Show from '@modules/Inventory/resources/js/pages/Widget/Show.vue';\n</script>");
        app(Contributors::class)->set('frontend', new Frontend(new class($response) implements Runner
        {
            public function __construct(private string $response) {}

            public function run(string $basePath, string $input): RunResult
            {
                return new RunResult($this->response === 'process-failure' ? null : $this->response, 'Subprocess failure');
            }
        }));
        S::commit($w);
        $data = S::preview($w, ['--scaffold' => 'pages']);
        expect($data['warnings'])->toBe([])->and($data['rewrites'])->toBe([])->and(array_column($data['checklist'], 'line'))->toContain(2)->and(array_column($data['checklist'], 'category'))->toContain('frontend-toolchain');
    });
})->with(['process-failure', 'noise', '{"version":2}', str_repeat('x', 8 * 1024 * 1024 + 1)]);

it('keeps invalid UTF8 sources read only and reports a parser fallback', function () {
    Workspace::run(null, function (Workspace $w) {
        S::setup($w);
        $w->write('resources/js/bad.js', "// \xff\nimport X from './Widget.vue';");
        S::commit($w);
        $data = S::preview($w);
        expect($data['warnings'])->toBe([])->and(array_column($data['checklist'], 'category'))->toContain('frontend-toolchain')->and(array_column($data['checklist'], 'line'))->toContain(2);
    });
});
