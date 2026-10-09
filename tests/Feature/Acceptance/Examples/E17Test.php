<?php

use Illuminate\Support\Composer;
use Pest\TestSuite;
use Tey\Mod\Facades\Mod;
use Tey\Mod\Layout\LayoutRegistry;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;
use Tey\Mod\Tests\TestCase;

it('autoloads E17 moved areas and then generates the model', function (bool $interactive, ?string $namespace) {
    $columns = getenv('COLUMNS');
    putenv('COLUMNS=72');

    try {
        Workspace::run(null, function (Workspace $workspace) use ($interactive, $namespace) {
            $case = TestSuite::getInstance()->test;
            if (! $case instanceof TestCase) {
                throw new LogicException('E17 needs Testbench.');
            }
            $workspace->write('composer.json', "{\n    \"name\": \"tey-mod/owned-app\",\n    \"autoload\": {\n        \"psr-4\": {\n            \"App\\\\\": \"app/\"\n        }\n    },\n    \"autoload-dev\": {\n        \"psr-4\": {\n            \"Tests\\\\\": \"tests/\"\n        }\n    }\n}\n");
            // This is the actual E17 declaration, enabled by the lead after merging lane 3.
            Mod::layout('areas')->extends('modules')->path('src/Areas/{area}');
            config()->set('mod.layout', 'areas');
            $composer = Mockery::mock(Composer::class);
            $composer->shouldReceive('setWorkingPath')->once()->with($workspace->root->path)->andReturnSelf();
            $composer->shouldReceive('dumpAutoloads')->once()->andReturn(0);
            app()->instance(Composer::class, $composer);

            if ($interactive) {
                $case->artisan('mod:autoload')
                    ->expectsQuestion("src/Areas isn't autoloaded. Which namespace should it use?", 'Areas\\')
                    ->expectsOutputToContain('composer.json is missing 1 autoload entry for the areas layout.')
                    ->assertSuccessful();
            } else {
                $options = $namespace === null ? [] : ['--namespace' => $namespace];
                $result = $workspace->artisan('mod:autoload', $options)->assertSuccessful();
                $notice = $namespace === null ? "\n   INFO  Using namespace [Areas\\] for [src/Areas]; pass --namespace to choose another.  \n" : '';
                expect(str_replace("\r\n", "\n", $result->output))->toBe($notice."\n   INFO  composer.json is missing 1 autoload entry for the areas layout.  \n\n  \"Areas\\\\\": \"src/Areas/\" ...................................... added  \n  composer dump-autoload ........................................ DONE  \n\n");
            }

            expect(str_replace("\r\n", "\n", $workspace->read('composer.json')))->toBe("{\n    \"name\": \"tey-mod/owned-app\",\n    \"autoload\": {\n        \"psr-4\": {\n            \"App\\\\\": \"app/\",\n            \"Areas\\\\\": \"src/Areas/\"\n        }\n    },\n    \"autoload-dev\": {\n        \"psr-4\": {\n            \"Tests\\\\\": \"tests/\"\n        }\n    }\n}\n");

            $workspace->artisan('mod:model', ['name' => 'Knowledge:Document'])->assertSuccessful()
                ->expectsOutputToContain('Model [src/Areas/Knowledge/Models/Document.php] created successfully.');
            expect($workspace->files())->toBe(['src/Areas/Knowledge/Models/Document.php'])
                ->and(str_replace("\r\n", "\n", $workspace->read('src/Areas/Knowledge/Models/Document.php')))->toBe("<?php\n\nnamespace Areas\\Knowledge\\Models;\n\nuse Illuminate\\Database\\Eloquent\\Model;\n\nclass Document extends Model\n{\n    //\n}\n");
        });
    } finally {
        putenv($columns === false ? 'COLUMNS' : 'COLUMNS='.$columns);
    }
})->with([[true, null], [false, null], [false, 'Areas\\']])
    ->skip('needs the lead’s namespaceFor() autoload integration');

it('E17 extends modules with the area token in options and notices', function () {
    putenv('COLUMNS=72');
    Workspace::run(null, function (Workspace $workspace) {
        config()->set('mod.layout', 'areas');
        Mod::layout('areas')->extends('modules');
        mkdir($workspace->root->path('app/Modules/Knowledge'), 0700, true);
        $workspace->artisan('mod:model', ['name' => 'Document', '--area' => 'Knowledge'])->assertSuccessful();
        $result = $workspace->artisan('mod:model', ['name' => 'Billing:Invoice']);
        $result->assertSuccessful();
        expect(str_replace("\r\n", "\n", $result->output))->toBe("\n   INFO  Created new area Billing (existing: Knowledge).  \n\n   INFO  Model [app/Modules/Billing/Models/Invoice.php] created successfully.  \n\n")
            ->and($workspace->files())->toBe(['app/Modules/Billing/Models/Invoice.php', 'app/Modules/Knowledge/Models/Document.php'])
            ->and(str_replace("\r\n", "\n", $workspace->read('app/Modules/Knowledge/Models/Document.php')))->toContain('namespace App\\Modules\\Knowledge\\Models;');
    });
});

it('E17 moves every modules file type to a project-relative or absolute path', function (bool $absolute) {
    Workspace::run(null, function (Workspace $workspace) use ($absolute) {
        $workspace->write('composer.json', json_encode(['autoload' => ['psr-4' => ['App\\' => 'app/', 'Areas\\' => 'src/Areas/']]], JSON_THROW_ON_ERROR));
        $registry = new LayoutRegistry;
        $original = $registry->layout('modules')->compile();
        $moved = $registry->layout('areas')->extends('modules')->path(($absolute ? $workspace->root->path.'/' : '').'src/Areas/{area}')->compile();
        foreach ($original->kinds() as $id => $kind) {
            $before = place($original, $id, ($id === 'migration' ? 'create_examples_table' : 'Example'), 'Knowledge', ['timestamp' => MIGRATION_TIMESTAMP]);
            $after = place($moved, $id, ($id === 'migration' ? 'create_examples_table' : 'Example'), 'Knowledge', ['timestamp' => MIGRATION_TIMESTAMP]);
            // Tests keep their separate root; all application file types move together.
            $expected = str_replace('app/Modules/', 'src/Areas/', $before->path());
            expect($after->path())->toBe($expected, $id);
            if ($id !== 'test' && $kind->isClass()) {
                expect($after->fqcn())->toStartWith('Areas\\Knowledge\\');
            }
        }
    });
})->with([false, true]);

it('E17 compiles an unautoloaded path and exposes its default namespace', function () {
    Workspace::run(null, function () {
        $layout = (new LayoutRegistry)->layout('areas')->extends('modules')->path('src/Areas/{area}')->compile();
        expect($layout->namespaceFor('src/Areas'))->toBe('Areas\\')
            ->and($layout->namespaceFor('src/Areas/Knowledge'))->toBe('Areas\\Knowledge\\')
            ->and($layout->namespaceFor('app/Modules'))->toBe('App\\Modules\\');
    });
});

it('E17 warns about an unautoloaded path or mount and still generates', function (bool $mount) {
    putenv('COLUMNS=72');
    Workspace::run(null, function (Workspace $workspace) use ($mount) {
        config()->set('mod.layout', 'areas');
        $layout = Mod::layout('areas')->extends('modules');
        if ($mount) {
            $layout->mounts('areas', 'Areas\\', 'src/Areas')->generates('tool', in: 'areas:{area}/Tools');
        } else {
            $layout->path('src/Areas/{area}')->generates('tool', in: '@area/Tools');
        }
        $workspace->write('stubs/mod.tool.stub', "<?php\n\nnamespace {{ namespace }};\n\nclass {{ class }}\n{\n}\n");
        mkdir($workspace->root->path('src/Areas/Knowledge'), 0700, true);
        $result = $workspace->artisan('mod:tool', ['name' => 'Knowledge:Search']);
        $result->assertSuccessful();
        expect(str_replace("\r\n", "\n", $result->output))->toContain("src/Areas isn't autoloaded yet. Run php artisan mod:autoload.")
            ->and(str_replace("\r\n", "\n", $workspace->read('src/Areas/Knowledge/Tools/Search.php')))->toBe("<?php\n\nnamespace Areas\\Knowledge\\Tools;\n\nclass Search\n{\n}\n");
    });
})->with([false, true]);
