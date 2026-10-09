<?php

use Illuminate\Console\Command;
use Tey\Mod\Facades\Mod;
use Tey\Mod\Generation\GeneratorRegistry;
use Tey\Mod\Generation\PackageDetector;
use Tey\Mod\Generation\Stub;
use Tey\Mod\Layout\CompiledLayout;
use Tey\Mod\Scaffolds\Scaffold;
use Tey\Mod\Scaffolds\ScaffoldRegistry;
use Tey\Mod\Support\Path;
use Tey\Mod\Tests\Feature\Acceptance\Examples\Support\TemplateScenario;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;

it('uses generation selections for every provenance branch without writing bases', function (string $branch) {
    Workspace::run(null, function (Workspace $w) use ($branch) {
        config()->set('mod.layout', 'modules');
        $type = match ($branch) {
            'Laravel', 'published stub' => 'model', 'starter' => 'dto', default => 'tool'
        };
        $w->write('example.stub', TemplateScenario::CLASS_STUB);
        if ($type === 'tool' && $branch !== 'template (app)') {
            Mod::layout('modules')->generates('tool', in: '@module/Tools', stub: $branch === 'layout' ? Stub::file($w->root->path('example.stub')) : null);
        }
        if ($branch === 'registered') {
            Mod::stubs()->for('tool', Stub::file($w->root->path('example.stub')));
        }
        if ($branch === 'published stub') {
            $w->write('stubs/mod.model.stub', TemplateScenario::CLASS_STUB);
        }
        if ($branch === 'template (app)') {
            $w->write('stubs/mod/@module/Tools/tool.stub', TemplateScenario::CLASS_STUB);
        }
        $before = $w->files();
        $data = json_decode($w->artisan('mod:list', ['--json' => true, '--type' => $type])->assertSuccessful()->output, true, flags: JSON_THROW_ON_ERROR);
        $row = $data['types'][0];
        expect($row['source'])->toBe($branch)->and($w->files())->toBe($before);
        foreach (app(GeneratorRegistry::class)->commands(app(CompiledLayout::class), app()) as $command) {
            if ($command->getName() === $row['command'] && $command instanceof Command && method_exists($command, 'stubSelection')) {
                $command->setLaravel(app());
                $selection = $command->stubSelection();
                expect($row['source'])->toBe($selection->source)
                    ->and($row['stub'])->toBe($selection->file === null ? null : (Path::relative($w->root->path, $selection->file) ?? Path::normalize($selection->file)));
            }
        }
    });
})->with(['Laravel', 'published stub', 'layout', 'registered', 'starter', 'empty class', 'template (app)']);

it('reports installed starter variants without generating a base', function () {
    Workspace::run(null, function (Workspace $w) {
        config()->set('mod.layout', 'modules');
        app()->instance(PackageDetector::class, new class implements PackageDetector
        {
            public function isInstalled(string $package): bool
            {
                return $package === 'spatie/laravel-data';
            }

            public function classExists(string $class): bool
            {
                return false;
            }
        });
        $data = json_decode($w->artisan('mod:list', ['--json' => true, '--type' => 'dto'])->assertSuccessful()->output, true, flags: JSON_THROW_ON_ERROR);
        expect($data['types'][0]['source'])->toBe('starter (spatie/laravel-data)')->and($w->files())->toBe([]);
    });
});

it('lists scaffold problems separately and keeps usable recipes', function () {
    Workspace::run(null, function (Workspace $w) {
        config()->set('mod.layout', 'modules');
        Mod::scaffold('good', fn (Scaffold $s) => $s->makes('model'));
        app(ScaffoldRegistry::class)->register('crud', fn (Scaffold $s) => $s->makes('model'), 'acme/kit');
        app(ScaffoldRegistry::class)->register('crud', fn (Scaffold $s) => $s->makes('model'), 'beta/kit');
        $data = json_decode($w->artisan('mod:list', ['--json' => true])->assertSuccessful()->output, true, flags: JSON_THROW_ON_ERROR);
        expect(array_column($data['scaffolds']['items'], 'name'))->toBe(['good'])
            ->and($data['scaffolds']['problems'][0]['reason'])->toContain('acme/kit', 'beta/kit');
        $w->artisan('mod:list')->assertSuccessful()->expectsOutputToContain('Scaffolds with problems');
    });
});

it('reports the native migration stub rather than class-generator overrides', function () {
    Workspace::run(null, function (Workspace $w) {
        config()->set('mod.layout', 'modules');
        $w->write('stubs/migration.stub', '<?php // native migration override');
        $w->write('stubs/mod.migration.stub', '<?php // not used by the native creator');
        $data = json_decode($w->artisan('mod:list', ['--json' => true, '--type' => 'migration'])->assertSuccessful()->output, true, flags: JSON_THROW_ON_ERROR);
        expect($data['types'][0]['source'])->toBe('published stub')
            ->and($data['types'][0]['stub'])->toBe('stubs/migration.stub');
    });
});
