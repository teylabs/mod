<?php

use Illuminate\Support\Facades\Artisan;
use Laravel\Mcp\Server\McpServiceProvider;
use Tey\Mod\Commands\GenericClassCommand;
use Tey\Mod\Facades\Mod;
use Tey\Mod\Generation\GeneratorRegistry;
use Tey\Mod\Layout\LayoutRegistry;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;

it('matches native MCP bytes and options at a custom module or nested DDD placement', function (string $layout, string $kind, string $folder) {
    Workspace::run(null, function (Workspace $workspace) use ($layout, $kind, $folder) {
        config()->set('mod.layout', $layout);
        app()->register(McpServiceProvider::class);
        $commands = Artisan::all();
        $native = $commands['make:mcp-'.$kind];
        $adapter = $commands['mod:mcp-'.$kind];
        expect(is_subclass_of($adapter, $native::class))->toBeTrue();
        foreach ($native->getDefinition()->getOptions() as $option => $definition) {
            $placed = $adapter->getDefinition()->getOption($option);
            expect([$placed->getShortcut(), $placed->getDefault(), $placed->isValueRequired(), $placed->isValueOptional(), $placed->isArray(), $placed->getDescription()])
                ->toBe([$definition->getShortcut(), $definition->getDefault(), $definition->isValueRequired(), $definition->isValueOptional(), $definition->isArray(), $definition->getDescription()]);
        }
        $workspace->artisan('make:mcp-'.$kind, ['name' => 'ExampleProbe'])->assertSuccessful();
        $nativePath = 'app/Mcp/'.$folder.'/ExampleProbe.php';
        $bytes = $workspace->read($nativePath);
        $workspace->remove([$nativePath]);
        $group = $layout === 'ddd' ? 'Knowledge/Search' : 'Inventory';
        $path = 'app/Modules/'.$group.'/Mcp/'.$folder.'/ExampleProbe.php';
        $namespace = 'App\\Modules\\'.str_replace('/', '\\', $group).'\\Mcp\\'.$folder;
        $expected = str_replace('namespace App\\Mcp\\'.$folder.';', 'namespace '.$namespace.';', $bytes);
        $preview = $workspace->artisan('mod:mcp-'.$kind, ['name' => $group.':ExampleProbe', '--dry-run' => true, '--json' => true])->assertSuccessful();
        expect($preview->output)->toContain($path)->and($workspace->files())->toBe([]);
        $workspace->artisan('mod:mcp-'.$kind, ['name' => $group.':ExampleProbe'])->assertSuccessful();
        expect($workspace->files())->toBe([$path])->and($workspace->read($path))->toBe($expected)
            ->and($expected)->not->toContain('{{ title }}', '{{ serverDisplayName }}');
        $workspace->write($path, $expected."\n// keep existing edit\n");
        $before = $workspace->read($path);
        $workspace->artisan('mod:mcp-'.$kind, ['name' => $group.':ExampleProbe'])->expectsOutputToContain('already exists');
        expect($workspace->read($path))->toBe($before);
        $collision = $workspace->artisan('mod:mcp-'.$kind, ['name' => $group.':ExampleProbe', '--dry-run' => true, '--json' => true]);
        expect(json_decode($collision->output, true, flags: JSON_THROW_ON_ERROR))->toMatchArray(['would_write' => false])
            ->and($workspace->read($path))->toBe($before);
        $workspace->artisan('mod:mcp-'.$kind, ['name' => $group.':ExampleProbe', '--force' => true])->assertSuccessful();
        expect($workspace->read($path))->toBe($expected);
    });
})->with(['modules', 'ddd'])->with([
    ['tool', 'Tools'], ['server', 'Servers'], ['resource', 'Resources'], ['prompt', 'Prompts'],
]);

it('uses native published MCP stubs for app-declared ids without built-in placements', function (string $kind, string $folder) {
    Workspace::run(null, function (Workspace $workspace) use ($kind, $folder) {
        config()->set('mod.layout', 'custom-mcp');
        Mod::layout('custom-mcp')->extends('modules')->path('src/Areas/{area}')
            ->generates('mcp-'.$kind, in: '@area/Protocol/'.$folder);
        app()->register(McpServiceProvider::class);
        $stub = "<?php\nnamespace {{ namespace }};\nclass {{ class }} { public const TITLE = '{{ title }}'; public const DISPLAY = '{{ serverDisplayName }}'; }\n";
        $workspace->write('stubs/mcp-'.$kind.'.stub', $stub);
        $workspace->artisan('make:mcp-'.$kind, ['name' => 'ExampleProbe'])->assertSuccessful();
        $nativePath = 'app/Mcp/'.$folder.'/ExampleProbe.php';
        $bytes = $workspace->read($nativePath);
        $workspace->remove([$nativePath]);
        $workspace->artisan('mod:mcp-'.$kind, ['name' => 'Billing:ExampleProbe'])->assertSuccessful();
        expect($workspace->read('src/Areas/Billing/Protocol/'.$folder.'/ExampleProbe.php'))
            ->toBe(str_replace('namespace App\\Mcp\\'.$folder.';', 'namespace Areas\\Billing\\Protocol\\'.$folder.';', $bytes));
    });
})->with([['tool', 'Tools'], ['server', 'Servers']]);

it('matches native MCP output and duplicate handling at the native path', function (string $kind, string $folder) {
    Workspace::run(null, function (Workspace $workspace) use ($kind, $folder) {
        Mod::layout('laravel')->generates('mcp-'.$kind, in: 'Mcp/'.$folder);
        app()->register(McpServiceProvider::class);
        $path = 'app/Mcp/'.$folder.'/ExampleProbe.php';
        $native = $workspace->artisan('make:mcp-'.$kind, ['name' => 'ExampleProbe'])->assertSuccessful();
        $bytes = $workspace->read($path);
        $nativeDuplicate = $workspace->artisan('make:mcp-'.$kind, ['name' => 'ExampleProbe']);
        $workspace->remove([$path]);
        $placed = $workspace->artisan('mod:mcp-'.$kind, ['name' => 'ExampleProbe'])->assertSuccessful();
        expect($placed->normalisedOutput())->toBe($native->normalisedOutput())
            ->and($placed->exitCode)->toBe($native->exitCode)
            ->and($workspace->read($path))->toBe($bytes);
        $duplicate = $workspace->artisan('mod:mcp-'.$kind, ['name' => 'ExampleProbe']);
        expect($duplicate->normalisedOutput())->toBe($nativeDuplicate->normalisedOutput())
            ->and($duplicate->exitCode)->toBe($nativeDuplicate->exitCode);
        $workspace->write($path, $bytes."\n// edited\n");
        $forcedNative = $workspace->artisan('make:mcp-'.$kind, ['name' => 'ExampleProbe', '--force' => true]);
        $workspace->write($path, $bytes."\n// edited\n");
        $forcedPlaced = $workspace->artisan('mod:mcp-'.$kind, ['name' => 'ExampleProbe', '--force' => true]);
        expect($forcedPlaced->normalisedOutput())->toBe($forcedNative->normalisedOutput())
            ->and($forcedPlaced->exitCode)->toBe($forcedNative->exitCode)
            ->and($workspace->read($path))->toBe($bytes);
    });
})->with([['tool', 'Tools'], ['server', 'Servers'], ['resource', 'Resources'], ['prompt', 'Prompts']]);

it('preserves explicit adapter overrides and does not wrap companion-writing generators', function () {
    Workspace::run(null, function (Workspace $workspace) {
        Mod::layout('laravel')->generates('mcp-app-resource', in: 'Protocol/Resources')
            ->generates('mcp-tool', in: 'Protocol/Tools');
        $registry = app(GeneratorRegistry::class);
        $registry->useFileType('mcp-tool', GenericClassCommand::class);
        $layout = app(LayoutRegistry::class)->compile('laravel');
        expect($registry->adapterFor($layout->kind('mcp-tool')))->toBe(GenericClassCommand::class)
            ->and($registry->adapterFor($layout->kind('mcp-app-resource')))->toBe(GenericClassCommand::class);
    });
});
