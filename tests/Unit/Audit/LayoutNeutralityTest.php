<?php

use Illuminate\Foundation\Console\ConfigMakeCommand;
use Tey\Mod\Commands\ConfigCommand;
use Tey\Mod\Support\Path;

/*
 * Layout neutrality: the engine has no layout-specific branches. Layout words may appear in prose
 * (docblocks, comments) as examples, never in code: no string literal,
 * identifier or variable of the engine names a fixture's segments or
 * dimensions. The engine has no dependency on any layout package.
 */

/**
 * Whether a file below src is shipped content rather than engine code.
 */
function isBuiltInContent(string $relative): bool
{
    return str_starts_with($relative, 'Layout/BuiltIn/')
        || $relative === 'Generation/Starters.php'
        || str_starts_with($relative, 'Generation/stubs/starters/');
}

/**
 * Every code token of src, comments and docblocks stripped.
 *
 * The built-in content is exempt: src/Layout/BuiltIn (the layouts mod ships,
 * written as data with the public builder) and the starters
 * (src/Generation/Starters.php and src/Generation/stubs/starters), which
 * necessarily name folders, placeholders and DDD terms. Everything else in
 * src stays neutral.
 *
 * @return list<array{file: string, line: int, text: string}>
 */
function engineCodeTokens(bool $withBuiltInLayouts = false): array
{
    $root = dirname(__DIR__, 3).'/src';
    $tokens = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
        if (! in_array($file->getExtension(), ['php', 'stub'], true)) {
            continue;
        }

        if (! $withBuiltInLayouts && isBuiltInContent((string) Path::relative($root, $file->getPathname()))) {
            continue;
        }

        foreach (PhpToken::tokenize((string) file_get_contents($file->getPathname())) as $token) {
            if ($token->is([T_COMMENT, T_DOC_COMMENT, T_WHITESPACE, T_INLINE_HTML])) {
                continue;
            }

            $tokens[] = ['file' => (string) Path::relative($root, $file->getPathname()), 'line' => $token->line, 'text' => $token->text];
        }
    }

    return $tokens;
}

/**
 * @return list<string>
 */
function codeTokensMatching(string $pattern, bool $withBuiltInLayouts = false): array
{
    $hits = [];

    foreach (engineCodeTokens($withBuiltInLayouts) as $token) {
        if (preg_match($pattern, $token['text']) === 1) {
            $hits[] = "{$token['file']}:{$token['line']} {$token['text']}";
        }
    }

    return $hits;
}

it('names no fixture layout, dimension or segment in engine code', function () {
    expect(codeTokensMatching('/(module|feature|slice|billing|invoice|vertical|cookbook|type-?first)/i'))->toBe([]);
});

it('exempts only the built-in layouts from the layout-word check', function () {
    $exempt = array_diff(
        array_unique(array_column(engineCodeTokens(withBuiltInLayouts: true), 'file')),
        array_unique(array_column(engineCodeTokens(), 'file')),
    );

    expect($exempt)->not->toBeEmpty();

    foreach ($exempt as $file) {
        expect(isBuiltInContent($file))->toBeTrue($file);
    }
});

it('names no DDD concept in engine code', function () {
    expect(codeTokensMatching('/(domain|layer|bounded|aggregate|value-?object|ddd)/i'))->toBe([]);
});

it('keeps the ddd vocabulary to the built-in layouts', function () {
    $hits = codeTokensMatching('/(domain|value-?object|ddd)/i', withBuiltInLayouts: true);

    expect($hits)->not->toBeEmpty();

    foreach ($hits as $hit) {
        expect(isBuiltInContent(explode(':', $hit)[0]))->toBeTrue($hit);
    }
});

it('has no dependency on any layout package', function () {
    $root = dirname(__DIR__, 3);
    /** @var array<string, mixed> $composer */
    $composer = json_decode((string) file_get_contents($root.'/composer.json'), true, flags: JSON_THROW_ON_ERROR);
    $packages = [...array_keys((array) ($composer['require'] ?? [])), ...array_keys((array) ($composer['require-dev'] ?? []))];

    expect(array_filter($packages, fn (string $package) => preg_match('/lunarstorm|laravel-ddd/i', $package) === 1))->toBe([])
        ->and($composer['repositories'] ?? [])->toBe([])
        ->and(codeTokensMatching('/lunarstorm|LaravelDdd/i', withBuiltInLayouts: true))->toBe([]);
});

it('declares no static properties on any engine class', function () {
    $root = dirname(__DIR__, 3).'/src';
    $checked = 0;

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $name = 'Tey\\Mod\\'.str_replace(['/', '.php'], ['\\', ''], substr($file->getPathname(), strlen($root) + 1));

        // The optional adapter cannot be loaded on frameworks without its parent.
        if ($name === ConfigCommand::class && ! class_exists(ConfigMakeCommand::class)) {
            continue;
        }

        if (! class_exists($name) && ! interface_exists($name) && ! trait_exists($name) && ! enum_exists($name)) {
            throw new RuntimeException("[{$name}] does not autoload from its file.");
        }

        $reflection = new ReflectionClass($name);
        $declared = array_filter(
            $reflection->getProperties(ReflectionProperty::IS_STATIC),
            fn (ReflectionProperty $property): bool => $property->getDeclaringClass()->name === $name,
        );

        expect(array_map(fn (ReflectionProperty $property) => $property->name, $declared))->toBe([], $name);
        $checked++;
    }

    expect($checked)->toBeGreaterThan(80);
});
