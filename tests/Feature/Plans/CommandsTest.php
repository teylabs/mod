<?php

use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Tester\CommandTester;
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
            ->and(array_column($data['inserts'], 'into'))->toBe([TreeExamples::base(), 'app/Modules/Inventory/Http/Controllers/WidgetController.php'])
            ->and(array_column($data['inserts'], 'at'))->toBe(['tabs', 'actions'])
            ->and($data['inserts'][0]['stub'])->toContain('History')
            ->and($data['warnings'])->toBe([])->and($data['would_write'])->toBeTrue()
            ->and(array_map($w->read(...), array_combine($w->files(), $w->files())))->toBe($before);
    });
});

it('reports a missing answer in a successful non-interactive plan', function () {
    Workspace::run(null, function (Workspace $w) {
        config()->set('mod.layout', 'modules');
        Mod::scaffold('needs-answer', fn (Scaffold $s) => $s->asks('label', type: 'text')->makes('model'));
        $data = json_decode($w->artisan('mod:needs-answer', ['name' => 'Inventory:Widget', '--dry-run' => true, '--json' => true])->assertSuccessful()->output, true, flags: JSON_THROW_ON_ERROR);
        expect($data['warnings'])->toBe([['file' => null, 'line' => null, 'message' => 'mod:needs-answer needs a label. Pass --label=<value>.']])
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
