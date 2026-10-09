<?php

use Tey\Mod\Facades\Mod;
use Tey\Mod\Scaffolds\Scaffold;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;

it('uses the same exact and parent coverage as mod:autoload for generation warnings', function (string $namespace, string|array $paths, string $root, string $section, bool $covered) {
    Workspace::run(null, function (Workspace $w) use ($namespace, $paths, $root, $section, $covered) {
        Mod::layout('areas')->mounts('areas', 'App\\Modules\\', $root)->generates('model', in: '{area}/Models');
        config()->set('mod.layout', 'areas');
        app()->getNamespace(); // Laravel detects App from the original host mapping.
        $w->write('composer.json', json_encode([$section => ['psr-4' => [$namespace => $paths]]], JSON_THROW_ON_ERROR));
        $preview = $w->artisan('mod:autoload', ['--dry-run' => true])->assertSuccessful()->normalisedOutput();
        $output = $w->artisan('mod:model', ['name' => 'Inventory:Widget'])->assertSuccessful()->normalisedOutput();

        expect(str_contains($preview, 'Every root'))->toBe($covered)
            ->and(str_contains($output, "isn't autoloaded yet"))->toBe(! $covered);
    });
})->with([
    'exact' => ['App\\Modules\\', 'app/Modules/', 'app/Modules', 'autoload', true],
    'parent' => ['App\\', 'app/', 'app/Modules', 'autoload', true],
    'dev parent' => ['App\\', 'app/', 'app/Modules', 'autoload-dev', true],
    'array and Windows paths' => ['App\\', ['legacy/', 'app\\'], 'app\\Modules', 'autoload', true],
    'wrong folder' => ['App\\', 'elsewhere/', 'app/Modules', 'autoload', false],
    'wrong namespace' => ['Other\\', 'app/Modules/', 'app/Modules', 'autoload', false],
]);

it('refreshes ddd coverage after mod:autoload and includes application and test roots', function () {
    Workspace::run(null, function (Workspace $w) {
        config()->set('mod.layout', 'ddd');
        $w->write('composer.json', json_encode([
            'autoload' => ['psr-4' => ['App\\' => 'app/']],
            'autoload-dev' => ['psr-4' => ['Tests\\' => 'tests/']],
        ], JSON_THROW_ON_ERROR));
        expect($w->artisan('mod:model', ['name' => 'Inventory:Before'])->assertSuccessful()->normalisedOutput())
            ->toContain("src/Domain isn't autoloaded yet");
        $w->artisan('mod:autoload', ['--no-dump' => true])->assertSuccessful();
        foreach (['mod:model', 'mod:request', 'mod:test'] as $command) {
            expect($w->artisan($command, ['name' => 'Inventory:Widget'])->assertSuccessful()->normalisedOutput())
                ->not->toContain("isn't autoloaded yet");
        }
    });
});

it('does not warn for any scaffold member in a fresh modules application', function () {
    Workspace::run(null, function (Workspace $w) {
        config()->set('mod.layout', 'modules');
        Mod::scaffold('pair', fn (Scaffold $s) => $s->makes('model')->makes('request'));
        expect($w->artisan('mod:model', ['name' => 'Inventory:Widget'])->assertSuccessful()->normalisedOutput())
            ->not->toContain("isn't autoloaded yet");
        expect($w->artisan('mod:pair', ['name' => 'Support:Ticket'])->assertSuccessful()->normalisedOutput())
            ->not->toContain("isn't autoloaded yet");
    });
});

it('does not warn for template-generated classes covered by app domain or dev mappings', function (string $layout, string $template, string $name, string $path) {
    Workspace::run(null, function (Workspace $w) use ($layout, $template, $name, $path) {
        config()->set('mod.layout', $layout);
        $w->write($template, "<?php\n\nnamespace {{ namespace }};\n\nclass {{ class }}\n{\n}\n");
        if ($layout === 'ddd') {
            $w->artisan('mod:autoload', ['--no-dump' => true])->assertSuccessful();
        }
        $output = $w->artisan('mod:probe', ['name' => $name])->assertSuccessful()->normalisedOutput();
        expect($output)->not->toContain("isn't autoloaded yet")
            ->and($w->exists($path))->toBeTrue();
    });
})->with([
    'parent app mapping' => ['modules', 'stubs/mod/@module/Tools/probe.stub', 'Inventory:Widget', 'app/Modules/Inventory/Tools/Widget.php'],
    'just registered domain' => ['ddd', 'stubs/mod/@domain/Tools/probe.stub', 'Inventory:Widget', 'src/Domain/Inventory/Tools/Widget.php'],
    'dev mapping' => ['modules', 'stubs/mod/tests/Feature/probe.stub', 'Widget', 'tests/Feature/Widget.php'],
]);
