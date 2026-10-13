<?php

use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Process\Process;
use Tey\Mod\Facades\Mod;
use Tey\Mod\Scaffolds\Part;
use Tey\Mod\Scaffolds\Scaffold;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;
use Tey\Mod\Tests\Feature\Scaffolds\Support\Examples;
use Tey\Mod\Tests\Feature\Scaffolds\Support\TreeExamples;
use Tey\Mod\Tests\Support\JsonSchema;

it('plans the S1 CRUD files as JSON without writing or printing notices', function () {
    Workspace::run(null, function (Workspace $w) {
        Examples::setup($w);
        $before = $w->files();
        $output = $w->artisan('mod:crud', ['name' => 'Knowledge:Document', '--dry-run' => true, '--json' => true])->assertSuccessful()->normalisedOutput();
        $data = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        $schema = json_decode(file_get_contents(__DIR__.'/../../Fixtures/schema/plan.json'), true, flags: JSON_THROW_ON_ERROR);
        expect(JsonSchema::errors($data, $schema))->toBe([]);
        expect(array_keys($data))->toBe(['command', 'group', 'name', 'files', 'inserts', 'warnings', 'would_write'])
            ->and($data['command'])->toBe('mod:crud')->and($data['group'])->toBe('Knowledge')->and($data['name'])->toBe('Document')
            ->and(array_column($data['files'], 'path'))->toBe(Examples::paths())
            ->and($data['files'][0])->toBe(['alias' => 'model', 'type' => 'model', 'path' => Examples::paths()[0], 'class' => 'App\\Modules\\Knowledge\\Models\\Document', 'group' => 'Knowledge', 'existing' => false, 'exists' => false])
            ->and($data['inserts'])->toBe([])->and($data['warnings'])->toBe([])->and($data['would_write'])->toBeTrue()
            ->and(trim($output))->toBe(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR))
            ->and($w->files())->toBe($before);
    });
});

it('uses the same plan for human dry runs', function () {
    Workspace::run(null, function (Workspace $w) {
        Examples::setup($w);
        $before = $w->files();
        $result = $w->artisan('mod:crud', ['name' => 'Knowledge:Document', '--dry-run' => true])->assertSuccessful();
        expect($result->normalisedOutput())->toBe(Examples::plan(Examples::paths()))->and($w->files())->toBe($before);
    });
});

it('plans tree files and inserts without changing the parent', function () {
    Workspace::run(null, function (Workspace $w) {
        TreeExamples::setup($w);
        TreeExamples::create($w);
        $before = array_map($w->read(...), array_combine($w->files(), $w->files()));
        $data = json_decode($w->artisan('mod:resource-tabs.tab', ['name' => 'Inventory:Widget', 'value' => 'History', '--dry-run' => true, '--json' => true])->assertSuccessful()->output, true, flags: JSON_THROW_ON_ERROR);
        expect(array_column($data['files'], 'path'))->toBe([TreeExamples::page('History')])
            ->and($data['files'][0])->not->toHaveKey('state')
            ->and(array_column($data['inserts'], 'into'))->toBe([TreeExamples::base(), 'app/Modules/Inventory/Http/Controllers/WidgetController.php'])
            ->and(array_column($data['inserts'], 'at'))->toBe(['tabs', 'actions'])
            ->and($data['inserts'][0]['stub'])->toContain('History')
            ->and($data['warnings'])->toBe([])->and($data['would_write'])->toBeTrue()
            ->and(array_map($w->read(...), array_combine($w->files(), $w->files())))->toBe($before);
    });
});

it('includes recipe inserts when comparing whole-file tree members', function () {
    Workspace::run(null, function (Workspace $w) {
        TreeExamples::setup($w);
        TreeExamples::create($w);
        $before = array_map($w->read(...), array_combine($w->files(), $w->files()));
        $plan = json_decode($w->artisan('mod:resource-tabs', ['name' => 'Inventory:Widget', '--dry-run' => true, '--json' => true])->assertSuccessful()->output, true, flags: JSON_THROW_ON_ERROR);
        expect(array_column($plan['files'], 'state'))->toBe(array_fill(0, 5, 'unchanged'))
            ->and($plan['would_write'])->toBeFalse()
            ->and(array_map($w->read(...), array_combine($w->files(), $w->files())))->toBe($before);
    });
});

it('reports a missing answer in a successful non-interactive plan', function () {
    Workspace::run(null, function (Workspace $w) {
        config()->set('mod.layout', 'modules');
        Mod::scaffold('needs-answer', fn (Scaffold $s) => $s->asks('label', type: 'text')->makes('model'));
        $data = json_decode($w->artisan('mod:needs-answer', ['name' => 'Inventory:Widget', '--dry-run' => true, '--json' => true])->assertSuccessful()->output, true, flags: JSON_THROW_ON_ERROR);
        expect($data['warnings'])->toBe([['code' => 'generation_refused', 'data' => [], 'file' => null, 'line' => null, 'message' => 'mod:needs-answer needs a label. Pass --label=<value>.']])
            ->and($data['would_write'])->toBeFalse()->and($w->files())->toBe([]);
    });
});

it('reports existing files and honours the collision flags in a plan', function (array $flags, bool $allowed) {
    Workspace::run(null, function (Workspace $w) use ($flags, $allowed) {
        Examples::setup($w);
        $w->write(Examples::paths()[0], '<?php // keep');
        $data = json_decode($w->artisan('mod:crud', ['name' => 'Knowledge:Document', '--dry-run' => true, '--json' => true, ...$flags])->assertSuccessful()->output, true, flags: JSON_THROW_ON_ERROR);
        expect($data['files'][0]['exists'])->toBeTrue()->and($data['files'][0]['existing'])->toBeTrue()
            ->and($data['would_write'])->toBe($allowed)->and($w->read(Examples::paths()[0]))->toBe('<?php // keep');
    });
})->with([[[], false], [['--force' => true], true], [['--skip-existing' => true], true]]);

/** @param array<string, mixed> $arguments */
function scaffoldProcess(Workspace $w, string $command, array $arguments): string
{
    $code = <<<'PHP'
require $argv[1];
$case = new class('scaffold') extends \Tey\Mod\Tests\TestCase {
    public function boot(): void { $this->setUp(); }
};
$case->boot();
app()->setBasePath($argv[2]);
config()->set('mod.layout', 'modules');
require $argv[2].'/recipe.php';
$exit = \Illuminate\Support\Facades\Artisan::call($argv[3], [...json_decode($argv[4], true), '--no-interaction' => true]);
echo \Illuminate\Support\Facades\Artisan::output();
exit($exit);
PHP;
    $process = new Process([PHP_BINARY, '-r', $code, '--', dirname(__DIR__, 3).'/vendor/autoload.php', $w->root->path, $command, json_encode($arguments, JSON_THROW_ON_ERROR)], $w->root->path);
    $process->run();
    expect($process->getExitCode())->toBe(0, $process->getOutput().$process->getErrorOutput());

    return $process->getOutput();
}

it('compares current scaffold bytes in a fresh process without changing any file', function () {
    Workspace::run(null, function (Workspace $w) {
        Examples::setup($w);
        $w->write('recipe.php', "<?php\n\\Tey\\Mod\\Facades\\Mod::scaffold('crud', \\Tey\\Mod\\Tests\\Feature\\Scaffolds\\Support\\Examples::recipe(...));\n");
        $w->artisan('mod:crud', ['name' => 'Knowledge:Document'])->assertSuccessful();
        $arguments = ['name' => 'Knowledge:Document', '--dry-run' => true, '--json' => true];
        $snapshot = fn (): array => ['composer.json' => $w->read('composer.json'), ...array_map($w->read(...), array_combine($w->files(), $w->files()))];
        $before = $snapshot();
        $plan = json_decode(scaffoldProcess($w, 'mod:crud', $arguments), true, flags: JSON_THROW_ON_ERROR);
        expect(array_column($plan['files'], 'state'))->toBe(array_fill(0, 8, 'unchanged'))
            ->and($snapshot())->toBe($before);

        $edited = Examples::paths()[7];
        $w->write($edited, $w->read($edited)."\n// developer edit\n");
        $before = $snapshot();
        $plan = json_decode(scaffoldProcess($w, 'mod:crud', $arguments), true, flags: JSON_THROW_ON_ERROR);
        expect(array_column($plan['files'], 'state'))->toBe([...array_fill(0, 7, 'unchanged'), 'edited'])
            ->and($snapshot())->toBe($before);
        $human = scaffoldProcess($w, 'mod:crud', ['name' => 'Knowledge:Document', '--dry-run' => true]);
        expect($human)->toContain('model (exists, unchanged)', 'controller (exists, edited)');

        $stub = 'stubs/mod.request.crud.stub';
        $w->write($stub, $w->read($stub)."\n// changed stub\n");
        $before = $snapshot();
        $plan = json_decode(scaffoldProcess($w, 'mod:crud', $arguments), true, flags: JSON_THROW_ON_ERROR);
        expect(array_column($plan['files'], 'state'))->toBe(['unchanged', 'unchanged', 'unchanged', 'edited', 'edited', 'unchanged', 'unchanged', 'edited'])
            ->and($snapshot())->toBe($before);
        scaffoldProcess($w, 'mod:crud', ['name' => 'Knowledge:Document', '--skip-existing' => true]);
        expect($snapshot())->toBe($before);
        scaffoldProcess($w, 'mod:crud', ['name' => 'Knowledge:Document', '--force' => true]);
        $before = $snapshot();
        $plan = json_decode(scaffoldProcess($w, 'mod:crud', $arguments), true, flags: JSON_THROW_ON_ERROR);
        expect(array_column($plan['files'], 'state'))->toBe(array_fill(0, 8, 'unchanged'))
            ->and($snapshot())->toBe($before);
    });
});

it('compares kept members and question blocks in flat and tree recipes', function (bool $tree) {
    Workspace::run(null, function (Workspace $w) use ($tree) {
        $w->write('recipe.php', <<<'PHP'
<?php
use Tey\Mod\Facades\Mod;
use Tey\Mod\Scaffolds\Scaffold;
Mod::scaffold('answers', function (Scaffold $s) {
    $s->named('Inventory:Widget')
        ->asks('columns', type: 'records', shape: ['key' => 'string', 'enabled' => 'bool'], default: [['key' => 'default', 'enabled' => false]])
        ->makes('job', as: 'job', stub: 'answers')
        ->makes('page', name: '{name}/Page', stub: 'answers', existing: 'keep')
        ->makes('page', name: '{name}/Default', as: 'defaultPage');
    if (is_file(base_path('tree'))) {
        $s->asks('tabs', type: 'list', default: ['Details', 'Notes'])
            ->part('tab', configure: fn ($p) => $p->makes('job', name: '{name}{tab}', stub: 'answers'))
            ->each('tabs', part: 'tab');
    }
});
PHP);
        if ($tree) {
            $w->write('tree', '');
        }
        $w->write('package.json', '{"dependencies":{"@inertiajs/vue3":"*"}}');
        $w->write('stubs/mod.job.answers.stub', "<?php\nnamespace {{ namespace }};\nuse Zed\\Last;\nuse Alpha\\First;\nclass {{ class }} {\n{{ #columns }}\n{{ #enabled }}\n    // {{ key }} {{ group }}\n{{ /enabled }}\n{{ /columns }}\n}\n");
        $w->write('stubs/mod.page.answers.vue.stub', "<template>\n{{ #columns }}\n{{ #enabled }}\n<p>{{ key }} {{ job.fqcn }} {{ group }} @{{ client }}</p>\n{{ /enabled }}\n{{ /columns }}\n</template>\n");
        $columns = '[{"key":"title","enabled":true},{"key":"hidden","enabled":false}]';
        scaffoldProcess($w, 'mod:answers', ['--columns' => $columns]);
        $snapshot = fn (): array => ['composer.json' => $w->read('composer.json'), ...array_map($w->read(...), array_combine($w->files(), $w->files()))];
        $before = $snapshot();
        $arguments = ['--columns' => $columns, '--dry-run' => true, '--json' => true];
        $plan = json_decode(scaffoldProcess($w, 'mod:answers', $arguments), true, flags: JSON_THROW_ON_ERROR);
        expect(array_column($plan['files'], 'state'))->toBe(array_fill(0, $tree ? 5 : 3, 'unchanged'))
            ->and($plan['files'][1]['existing'])->toBe('keep')
            ->and($snapshot())->toBe($before);
        $human = scaffoldProcess($w, 'mod:answers', ['--columns' => $columns, '--dry-run' => true]);
        expect($human)->toContain('page (kept, exists, unchanged)');
        $page = $plan['files'][1]['path'];
        $w->write($page, $w->read($page)."<!-- edited -->\n");
        $before = $snapshot();
        $plan = json_decode(scaffoldProcess($w, 'mod:answers', $arguments), true, flags: JSON_THROW_ON_ERROR);
        expect($plan['files'][1]['state'])->toBe('edited')
            ->and(scaffoldProcess($w, 'mod:answers', ['--columns' => $columns, '--dry-run' => true]))->toContain('page (kept, exists, edited)')
            ->and($snapshot())->toBe($before);
        $changedAnswers = json_decode(scaffoldProcess($w, 'mod:answers', ['--dry-run' => true, '--json' => true]), true, flags: JSON_THROW_ON_ERROR);
        expect(array_column($changedAnswers['files'], 'state'))->toBe(['edited', 'edited', 'unchanged', ...($tree ? ['edited', 'edited'] : [])])
            ->and($snapshot())->toBe($before);
        $w->remove([$plan['files'][0]['path']]);
        $before = $snapshot();
        $plan = json_decode(scaffoldProcess($w, 'mod:answers', $arguments), true, flags: JSON_THROW_ON_ERROR);
        expect($plan['files'][0])->not->toHaveKey('state')
            ->and($snapshot())->toBe($before);
    });
})->with(['flat' => false, 'tree' => true]);

it('plans individual generators and their companions', function () {
    Workspace::run(null, function (Workspace $w) {
        config()->set('mod.layout', 'modules');
        $data = json_decode($w->artisan('mod:model', ['name' => 'Inventory:Widget', '--migration' => true, '--factory' => true, '--dry-run' => true, '--json' => true])->assertSuccessful()->output, true, flags: JSON_THROW_ON_ERROR);
        expect(array_column($data['files'], 'type'))->toContain('model', 'factory', 'migration')->and($w->files())->toBe([]);
    });
});

it('plans template creation and Composer mappings without writing', function (string $command, array $arguments, string $path) {
    Workspace::run(null, function (Workspace $w) use ($command, $arguments, $path) {
        config()->set('mod.layout', 'ddd');
        $before = $w->read('composer.json');
        $data = json_decode($w->artisan($command, [...$arguments, '--dry-run' => true, '--json' => true])->assertSuccessful()->output, true, flags: JSON_THROW_ON_ERROR);
        expect($data['files'][0]['path'])->toBe($path)->and($data['would_write'])->toBeTrue()
            ->and($w->files())->toBe([])->and($w->read('composer.json'))->toBe($before);
    });
})->with([
    ['mod:template', ['type' => 'class', 'path' => 'tool'], 'stubs/mod/@domain/Tools/tool.stub'],
    ['mod:autoload', [], 'composer.json'],
]);

it('returns a schema-valid plan for every registered writing command without creating files', function (string $layout) {
    Workspace::run(null, function (Workspace $w) use ($layout) {
        config()->set('mod.layout', $layout);
        $schema = json_decode(file_get_contents(__DIR__.'/../../Fixtures/schema/plan.json'), true, flags: JSON_THROW_ON_ERROR);
        foreach (Artisan::all() as $command) {
            $definition = $command->getDefinition();
            if (! str_starts_with($command->getName() ?? '', 'mod:') || ! $definition->hasOption('dry-run')) {
                continue;
            }
            $arguments = ['--dry-run' => true, '--json' => true];
            if ($definition->hasArgument('stack')) {
                $arguments['stack'] = 'inertia';
            }
            if ($definition->hasArgument('name')) {
                $arguments['name'] = match ($layout) {
                    'modules', 'ddd', 'features' => 'Inventory:Widget',
                    'slices' => 'Inventory/Index:Widget',
                    default => 'Widget',
                };
            }
            if ($definition->hasArgument('module')) {
                $arguments['module'] = $layout === 'slices' ? 'Inventory/Index' : 'Inventory';
            }
            if ($command->getName() === 'mod:template') {
                $arguments['type'] = 'class';
                $arguments['path'] = 'tool';
            }
            $data = json_decode($w->artisan($command->getName(), $arguments)->assertSuccessful()->output, true, flags: JSON_THROW_ON_ERROR);
            expect(JsonSchema::errors($data, $schema))->toBe([], (string) $command->getName())
                ->and($w->files())->toBe([]);
        }
    });
})->with(['laravel', 'modules', 'ddd', 'features', 'slices', 'type-first']);

it('never prompts for a missing answer even when preview input is interactive', function () {
    Workspace::run(null, function (Workspace $w) {
        config()->set('mod.layout', 'modules');
        Mod::scaffold('needs-answer', fn (Scaffold $s) => $s->asks('label', type: 'text')->makes('model'));
        $command = Artisan::all()['mod:needs-answer'];
        $tester = new CommandTester($command);
        expect($tester->execute(['name' => 'Inventory:Widget', '--dry-run' => true, '--json' => true], ['interactive' => true]))->toBe(0);
        $data = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        expect($data['warnings'][0]['message'])->toBe('mod:needs-answer needs a label. Pass --label=<value>.')
            ->and($data['would_write'])->toBeFalse()->and($w->files())->toBe([]);
    });
});

it('collects every unanswered question in flat and tree plans', function (bool $tree) {
    Workspace::run(null, function (Workspace $w) use ($tree) {
        config()->set('mod.layout', 'modules');
        Mod::scaffold('needs-answers', function (Scaffold $s) use ($tree): void {
            $s->asks('first', type: 'text')->asks('second', type: 'text')->makes('model');
            if ($tree) {
                $s->part('child', configure: fn (Part $p) => $p->makes('controller'));
            }
        });
        $data = json_decode($w->artisan('mod:needs-answers', ['name' => 'Inventory:Widget', '--dry-run' => true, '--json' => true])->assertSuccessful()->output, true, flags: JSON_THROW_ON_ERROR);
        expect(array_column($data['warnings'], 'message'))->toBe([
            'mod:needs-answers needs a first. Pass --first=<value>.',
            'mod:needs-answers needs a second. Pass --second=<value>.',
        ])->and($data['would_write'])->toBeFalse()->and($w->files())->toBe([]);
    });
})->with([false, true]);
