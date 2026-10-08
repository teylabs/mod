<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Blade;
use Pest\TestSuite;
use Symfony\Component\Yaml\Yaml;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;
use Tey\Mod\Tests\TestCase;

/*
 * The Laravel Boost guideline and skill shipped in resources/boost: they
 * exist, parse, and name only mod:* commands that a built-in layout registers.
 */

function boostPath(string $path = ''): string
{
    return dirname(__DIR__, 2).'/resources/boost'.($path === '' ? '' : '/'.$path);
}

/**
 * @return array{0: array<string, mixed>, 1: string}
 */
function boostSkill(string $name): array
{
    return boostFrontmatter((string) file_get_contents(boostPath("skills/{$name}/SKILL.md")), "skills/{$name}/SKILL.md");
}

/**
 * Split a skill into its YAML frontmatter and body, whatever its line endings.
 *
 * @return array{0: array<string, mixed>, 1: string}
 */
function boostFrontmatter(string $contents, string $file): array
{
    if (preg_match('/\A---\r?\n(.*?)\r?\n---\r?\n(.*)\z/s', $contents, $matches) !== 1) {
        throw new RuntimeException("{$file} has no frontmatter.");
    }

    return [Yaml::parse($matches[1]), $matches[2]];
}

/**
 * @return list<string> every mod:* command the guideline and skills name
 */
function boostCommands(): array
{
    $text = (string) file_get_contents(boostPath('guidelines/core.blade.php'));

    foreach (glob(boostPath('skills/*/SKILL.md')) ?: [] as $skill) {
        $text .= file_get_contents($skill);
    }

    preg_match_all('/\bmod:[a-z][a-z-]*[a-z]\b/', $text, $matches);

    $commands = array_values(array_unique($matches[0]));
    sort($commands);

    return $commands;
}

/**
 * @return list<string> the mod:* commands and aliases registered for a built-in layout
 */
function commandsInLayout(string $layout): array
{
    return Workspace::run(null, function () use ($layout): array {
        config()->set('mod.layout', $layout);

        return array_values(array_filter(array_keys(Artisan::all()), static fn (string $name): bool => str_starts_with($name, 'mod:')));
    });
}

it('ships a guideline that renders as plain text through Blade', function () {
    $guideline = (string) file_get_contents(boostPath('guidelines/core.blade.php'));

    expect($guideline)->toStartWith('## Mod')
        ->and(trim(Blade::render($guideline)))->toBe(trim($guideline))
        ->and($guideline)->toContain('mod-development');
});

it('ships skills whose frontmatter names their folder', function () {
    $skills = glob(boostPath('skills/*/SKILL.md')) ?: [];

    expect($skills)->not->toBeEmpty();

    foreach ($skills as $path) {
        $folder = basename(dirname($path));
        [$frontmatter, $body] = boostSkill($folder);

        expect($frontmatter['name'] ?? null)->toBe($folder)
            ->and($frontmatter['description'] ?? '')->toBeString()
            ->and(trim((string) ($frontmatter['description'] ?? '')))->not->toBe('')
            ->and($frontmatter['license'] ?? null)->toBe('MIT')
            ->and($frontmatter['metadata']['author'] ?? null)->toBe('Jasper Tey / Tey Labs')
            ->and(trim($body))->toStartWith('# ');
    }
});

it('names only mod:* commands that a built-in layout registers', function () {
    $case = TestSuite::getInstance()->test;

    if (! $case instanceof TestCase) {
        throw new RuntimeException('This test needs the Testbench test case.');
    }

    $registered = [];

    // The command list is fixed when Artisan starts, so each layout gets a fresh application,
    // booted with discovery on so the discovery cache commands are registered too.
    foreach (['laravel', 'modules', 'features', 'slices', 'type-first', 'ddd'] as $layout) {
        $case->bootApplicationUsing(fn ($app) => $app->make('config')->set('mod.discovery.enabled', true));
        Artisan::clearResolvedInstances();
        $registered = [...$registered, ...commandsInLayout($layout)];
    }

    $named = boostCommands();

    expect($named)->toContain('mod:model', 'mod:bases', 'mod:discovery-cache')
        ->and(array_values(array_diff($named, $registered)))->toBe([]);
});

it('registers the commands the skill names for a layout in that layout', function (string $layout, array $commands) {
    expect(commandsInLayout($layout))->toContain(...$commands);
})->with([
    'modules' => ['modules', ['mod:model', 'mod:dto', 'mod:data', 'mod:view-model', 'mod:value', 'mod:action', 'mod:bases']],
    'ddd' => ['ddd', ['mod:model', 'mod:dto', 'mod:data', 'mod:view-model', 'mod:value', 'mod:action', 'mod:bases']],
    'slices' => ['slices', ['mod:handler', 'mod:command']],
    'features' => ['features', ['mod:command']],
]);

it('reads skill frontmatter checked out with CRLF line endings', function () {
    [$frontmatter, $body] = boostFrontmatter("---\r\nname: mod-development\r\nlicense: MIT\r\n---\r\n# Body\r\n", 'SKILL.md');

    expect($frontmatter)->toBe(['name' => 'mod-development', 'license' => 'MIT'])
        ->and($body)->toBe("# Body\r\n");
});
