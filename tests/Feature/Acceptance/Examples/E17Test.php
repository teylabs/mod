<?php

use Illuminate\Support\Composer;
use Pest\TestSuite;
use Tey\Mod\Facades\Mod;
use Tey\Mod\Exceptions\InvalidLayout;
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
            Mod::layout('areas')->extends('modules')->path('src/Areas/{area}'); // @phpstan-ignore method.notFound (needs lane 3)
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

            expect($workspace->read('composer.json'))->toBe("{\n    \"name\": \"tey-mod/owned-app\",\n    \"autoload\": {\n        \"psr-4\": {\n            \"App\\\\\": \"app/\",\n            \"Areas\\\\\": \"src/Areas/\"\n        }\n    },\n    \"autoload-dev\": {\n        \"psr-4\": {\n            \"Tests\\\\\": \"tests/\"\n        }\n    }\n}\n");

            $workspace->artisan('mod:model', ['name' => 'Knowledge:Document'])->assertSuccessful()
                ->expectsOutputToContain('Model [src/Areas/Knowledge/Models/Document.php] created successfully.');
            expect($workspace->files())->toBe(['src/Areas/Knowledge/Models/Document.php'])
                ->and($workspace->read('src/Areas/Knowledge/Models/Document.php'))->toBe("<?php\n\nnamespace Areas\\Knowledge\\Models;\n\nuse Illuminate\\Database\\Eloquent\\Model;\n\nclass Document extends Model\n{\n    //\n}\n");
        });
    } finally {
        putenv($columns === false ? 'COLUMNS' : 'COLUMNS='.$columns);
    }
})->with([[true, null], [false, null], [false, 'Areas\\']])
    ->skip('needs lane 3 (->path/->extends)');

it('E17 extends modules with the area token in options and notices', function () {
    putenv('COLUMNS=72');
    Workspace::run(null, function (Workspace $workspace) {
        config()->set('mod.layout', 'areas');
        Mod::layout('areas')->extends('modules');
        mkdir($workspace->root->path('app/Modules/Knowledge'), 0700, true);
        $workspace->artisan('mod:model', ['name' => 'Document', '--area' => 'Knowledge'])->assertSuccessful();
        $result = $workspace->artisan('mod:model', ['name' => 'Billing:Invoice']);
        $result->assertSuccessful();
        expect($result->output)->toBe("\n   INFO  Created new area Billing (existing: Knowledge).  \n\n   INFO  Model [app/Modules/Billing/Models/Invoice.php] created successfully.  \n\n")
            ->and($workspace->files())->toBe(['app/Modules/Billing/Models/Invoice.php', 'app/Modules/Knowledge/Models/Document.php'])
            ->and($workspace->read('app/Modules/Knowledge/Models/Document.php'))->toContain('namespace App\\Modules\\Knowledge\\Models;');
    });
});

it('E17 moves every modules file type to a project-relative or absolute path', function (bool $absolute) {
    Workspace::run(null, function (Workspace $workspace) use ($absolute) {
        $workspace->write('composer.json', json_encode(['autoload' => ['psr-4' => ['App\\' => 'app/', 'Areas\\' => 'src/Areas/']]], JSON_THROW_ON_ERROR));
        $registry = new LayoutRegistry;
        $original = $registry->layout('modules')->compile();
        $moved = $registry->layout('areas')->extends('modules')->path(($absolute ? $workspace->root->path.'/' : '').'src/Areas/{area}')->compile();
        foreach ($original->kinds() as $id => $kind) {
            $before = place($original, $id, 'Example', 'Knowledge', ['timestamp' => MIGRATION_TIMESTAMP]);
            $after = place($moved, $id, 'Example', 'Knowledge', ['timestamp' => MIGRATION_TIMESTAMP]);
            // Tests keep their separate root; all application file types move together.
            $expected = str_replace('app/Modules/', 'src/Areas/', $before->path());
            expect($after->path())->toBe($expected, $id);
            if ($id !== 'test' && $kind->isClass()) {
                expect($after->fqcn())->toStartWith('Areas\\Knowledge\\');
            }
        }
    });
})->with([false, true]);

it('E17 names the Composer entry needed for a moved group path', function () {
    Workspace::run(null, function () {
        expect(fn () => (new LayoutRegistry)->layout('areas')->extends('modules')->path('src/Areas/{area}')->compile())
            ->toThrow(InvalidLayout::class, '"Areas\\\\": "src/Areas/"');
    });
});
