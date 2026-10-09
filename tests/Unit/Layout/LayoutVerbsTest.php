<?php

use Tey\Mod\Discovery\DiscoveryOptions;
use Tey\Mod\Exceptions\InvalidDiscoveryConfig;
use Tey\Mod\Exceptions\InvalidLayout;
use Tey\Mod\Exceptions\UnknownFileType;
use Tey\Mod\Layout\FileType;
use Tey\Mod\Layout\Layout;
use Tey\Mod\Layout\LayoutRegistry;
use Tey\Mod\Placement\TemplateRule;

it('removes the 0.1 layout names without aliases', function (string $method, array $arguments) {
    expect(fn () => (new LayoutRegistry)->layout('modules')->{$method}(...$arguments))
        ->toThrow(BadMethodCallException::class);
})->with([
    ['root', ['app', 'App\\', 'app']], ['kind', ['model']],
    ['relation', ['model-factory']], ['exclude', ['app/Support']],
    ['placementOption', ['area']], ['typeFolders', ['app/{area}']],
]);

it('rejects the old discovery key and names its replacement', function () {
    expect(fn () => DiscoveryOptions::fromConfig(['kinds' => []]))
        ->toThrow(InvalidDiscoveryConfig::class, 'Use mod.discovery.file_types instead.');
});

it('keeps every built-in placement exactly as 0.1.1', function () {
    $snapshot = json_decode(file_get_contents(__DIR__.'/../../Fixtures/Layout/v0.1.1.json'), true, flags: JSON_THROW_ON_ERROR);
    $actual = [];
    foreach (array_keys($snapshot) as $name) {
        foreach ((new LayoutRegistry)->compile($name)->rules() as $id => $rule) {
            if (! $rule instanceof TemplateRule) {
                throw new RuntimeException("Built-in {$name}/{$id} is no longer a declarative placement.");
            }
            $actual[$name][$id] = array_map(fn ($variant) => $variant->pattern(), $rule->variants());
        }
    }
    expect($actual)->toBe($snapshot);
});

it('names tokens from explicit paths, placeholders, and plural layout names', function (string $name, ?string $path, string $in, string $token) {
    $layout = (new Layout($name))->mounts('app', 'App\\', 'app');
    if ($path !== null) {
        $layout->path($path);
    }
    $compiled = $layout->generates('tool', in: $in)->compile();
    expect($compiled->dimensionNames())->toBe([$token]);
})->with([
    ['areas', 'app/Areas/{context}', 'Tools', 'context'],
    ['platform', null, '{team}/Tools', 'team'],
    ['areas', null, 'Tools', 'area'], ['contexts', null, 'Tools', 'context'],
]);

it('asks for a path when a name cannot supply a token', function () {
    expect(fn () => (new Layout('platform'))->mounts('app', 'App\\', 'app')->generates('tool', in: 'Tools')->compile())
        ->toThrow(InvalidLayout::class, "path('app/{group}')");
});

it('inherits derived tokens like Eloquent tables and declared tokens as facts', function (string $name, string $parent, ?string $path, array $tokens, string $expected) {
    $layout = (new LayoutRegistry)->layout($name)->extends($parent);
    if ($path !== null) {
        $layout->path($path);
    }
    $compiled = $layout->compile();
    expect($compiled->dimensionNames())->toBe($tokens)
        ->and(place($compiled, 'model', 'Document', 'Knowledge')->path())->toBe($expected);
})->with([
    ['areas', 'modules', null, ['area'], 'app/Modules/Knowledge/Models/Document.php'],
    ['contexts', 'ddd', null, ['domain'], 'src/Domain/Knowledge/Models/Document.php'],
    ['contexts', 'ddd', 'src/Domain/{context}', ['context'], 'src/Domain/Knowledge/Models/Document.php'],
    ['areas', 'slices', null, ['feature', 'slice'], 'app/Knowledge/Models/Document.php'],
]);

it('copies a custom parent at the moment extends is called', function () {
    $registry = new LayoutRegistry;
    $parent = $registry->layout('teams')->mounts('app', 'App\\', 'app')->generates('tool', in: '{team}/Tools');
    $child = $registry->layout('areas')->extends('teams');
    $parent->generates('tool', suffix: 'Changed')->generates('report', in: '{team}/Reports');
    expect($child->compile()->hasKind('report'))->toBeFalse()
        ->and(place($child->compile(), 'tool', 'Search', 'Knowledge')->path())->toBe('app/Knowledge/Tools/Search.php');
});

it('checks extends order and forbids a second parent at compile time', function (Closure $define) {
    $layout = (new LayoutRegistry)->layout('areas');
    $define($layout);
    expect(fn () => $layout->compile())->toThrow(InvalidLayout::class, "extends('modules') must come first: Mod::layout('areas')->extends('modules')->generates(…).");
})->with([
    fn (Layout $layout) => $layout->generates('tool', in: 'Tools')->extends('modules'),
    fn (Layout $layout) => $layout->extends('ddd')->extends('modules'),
    fn (Layout $layout) => $layout->path('app/{area}')->extends('modules'),
]);

it('uses absolute group paths as given, including Windows drive paths', function (string $path, string $expected) {
    $layout = (new LayoutRegistry)->layout('areas')->extends('modules')->path($path)->compile();
    expect(place($layout, 'model', 'Document', 'Knowledge')->path())->toBe($expected);
})->with([
    ['/srv/mod/Areas/{area}', '/srv/mod/Areas/Knowledge/Models/Document.php'],
    ['C:\\work\\Areas\\{area}', 'C:/work/Areas/Knowledge/Models/Document.php'],
]);

it('validates nesting dimensions and allows one dimension of a multi-level layout', function () {
    $registry = new LayoutRegistry;
    expect(fn () => $registry->layout('slices')->allowsNesting()->compile())->toThrow(InvalidLayout::class, 'needs a dimension');
    expect(fn () => (new LayoutRegistry)->layout('modules')->allowsNesting('missing')->compile())->toThrow(InvalidLayout::class, 'names no group');
    $layout = (new LayoutRegistry)->layout('slices')->allowsNesting('feature')->compile();
    expect(place($layout, 'handler', 'Handler', 'Agents.Chat/Search')->path())->toBe('app/Agents/Chat/Search/Handler.php');
});

it('exposes FileType and removes the former public class and exception', function () {
    expect(class_exists(FileType::class))->toBeTrue()
        ->and(class_exists('Tey\\Mod\\Layout\\Kind'))->toBeFalse()
        ->and(class_exists('Tey\\Mod\\Exceptions\\UnknownKind'))->toBeFalse();
    expect(fn () => (new LayoutRegistry)->compile('modules')->kind('absent'))
        ->toThrow(UnknownFileType::class);
});

it('moves every application file type when the type-first path changes', function () {
    $registry = new LayoutRegistry;
    $before = $registry->layout('type-first')->compile();
    $after = $registry->layout('tools')->extends('type-first')->path('src/Tools/*/{area}')->compile();
    foreach ($before->rules() as $id => $rule) {
        if ($rule->root()->path !== 'app') {
            continue;
        }
        $original = place($before, $id, 'Example', 'Knowledge');
        $moved = place($after, $id, 'Example', 'Knowledge');
        expect($moved->path())->toBe('src/Tools/'.substr($original->path(), strlen('app/')), $id)
            ->and($moved->fqcn())->toStartWith('Tools\\');
    }
    expect(place($after, 'job', 'Search')->path())->toBe('src/Tools/Jobs/Search.php');
});

it('resolves anchors from custom paths and name-derived group folders without path', function () {
    $explicit = (new Layout('platform'))->mounts('app', 'App\\', 'app')
        ->generates('model', in: 'Teams/{team}/Models')
        ->generates('tool', in: '@team/Tools')->compile();
    $derived = (new Layout('areas'))->mounts('app', 'App\\', 'app')
        ->generates('tool', in: '@area/Tools')->compile();
    expect(place($explicit, 'tool', 'Search', 'Knowledge')->path())->toBe('app/Teams/Knowledge/Tools/Search.php')
        ->and(place($derived, 'tool', 'Search', 'Knowledge')->path())->toBe('app/Knowledge/Tools/Search.php');
});
