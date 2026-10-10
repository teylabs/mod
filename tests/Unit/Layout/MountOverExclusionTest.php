<?php

use Tey\Mod\Discovery\PresetFingerprint;
use Tey\Mod\Layout\LayoutRegistry;
use Tey\Mod\Layout\Root;
use Tey\Mod\Reverse\ReverseMapper;
use Tey\Mod\Support\Path;

it('treats Windows absolute exclusions as paths with native or mixed separators', function (string $appPath, string $exclusion, bool $equal) {
    $registry = new LayoutRegistry;
    $registry->layout('parent')->path('app')->mounts('app', 'App\\', $appPath)->excludes($exclusion);
    $kitPath = Path::join($appPath, 'Support/Kit');
    $compiled = $registry->layout('child')->extends('parent')
        ->mounts('kit', 'App\\Support\\Kit\\', $kitPath, fn (Root $root) => $root->generates('kit', in: ''))->compile();
    $mapper = new ReverseMapper($compiled);
    expect($compiled->excludedRoots())->toHaveCount($equal ? 0 : 1)
        ->and($mapper->fromClass('App\\Support\\Kit\\Thing')->isMatched())->toBeTrue()
        ->and($mapper->fromPath(Path::join($kitPath, 'Thing.php'))->isMatched())->toBeTrue();
    if (! $equal) {
        foreach (['Other', 'KitExtra'] as $sibling) {
            expect($mapper->fromClass('App\\Support\\'.$sibling.'\\Thing')->reason)->toContain('excluded')
                ->and($mapper->fromPath(Path::join($appPath, 'Support', $sibling, 'Thing.php'))->reason)->toContain('excluded');
        }
    }
})->with([
    'drive native broader' => ['C:\\work\\app', 'C:\\work\\app\\Support', false],
    'drive mixed broader' => ['C:\\work\\app', 'C:\\work\\app/Support', false],
    'drive native equal' => ['C:\\work\\app', 'C:\\work\\app\\Support\\Kit', true],
    'UNC native broader' => ['\\\\server\\share\\app', '\\\\server\\share\\app\\Support', false],
    'UNC mixed broader' => ['\\\\server\\share\\app', '\\\\server\\share\\app/Support', false],
    'UNC native equal' => ['\\\\server\\share\\app', '\\\\server\\share\\app\\Support\\Kit', true],
]);

it('carves only mounted subtrees out of inherited namespace and path exclusions', function (string $exclusion) {
    $registry = new LayoutRegistry;
    $registry->layout('parent')->path('app')->mounts('app', 'App\\', 'app')->excludes($exclusion);
    $layout = $registry->layout('child')->extends('parent')
        ->mounts('kit', 'App\\Support\\Kit\\', 'app/Support/Kit', fn (Root $r) => $r->generates('kit', in: ''));
    $compiled = $layout->compile();
    $mapper = new ReverseMapper($compiled);
    expect($compiled->excludedRoots())->toHaveCount(1)
        ->and($mapper->fromClass('App\\Support\\Kit\\Thing')->isMatched())->toBeTrue()
        ->and($mapper->fromPath('app/Support/Kit/Thing.php')->isMatched())->toBeTrue();
    foreach (['Other', 'KitExtra'] as $sibling) {
        expect($mapper->fromClass('App\\Support\\'.$sibling.'\\Thing')->reason)->toContain('excluded')
            ->and($mapper->fromPath('app/Support/'.$sibling.'/Thing.php')->reason)->toContain('excluded');
    }
    $grandchild = $registry->layout('grandchild')->extends('child')->compile();
    expect((new ReverseMapper($grandchild))->fromPath('app/Support/Kit/Thing.php')->isMatched())->toBeTrue()
        ->and((new ReverseMapper($grandchild))->fromPath('app/Support/Other/Thing.php')->reason)->toContain('excluded');
})->with(['App\\Support\\', 'app/Support']);

it('removes equal inherited exclusions and keeps unrelated exclusions', function (string $exclusion) {
    $registry = new LayoutRegistry;
    $registry->layout('parent')->path('app')->mounts('app', 'App\\', 'app')->excludes($exclusion, 'App\\Other\\');
    $compiled = $registry->layout('child')->extends('parent')->mounts('kit', 'App\\Support\\', 'app/Support')->compile();
    expect(array_map(fn ($root) => $root->namespace, $compiled->excludedRoots()))->toBe(['App\\Other\\']);
})->with(['App\\Support\\', 'app/Support']);

it('keeps child exclusions regardless of declaration order', function (bool $first) {
    $registry = new LayoutRegistry;
    $layout = $registry->layout('child')->extends('modules')->path('app/Modules/{module}');
    if ($first) {
        $layout->excludes('App\\Support\\');
    }
    $layout->mounts('kit', 'App\\Support\\Kit\\', 'app/Support/Kit', fn (Root $r) => $r->generates('kit', in: ''));
    if (! $first) {
        $layout->excludes('App\\Support\\');
    }
    expect((new ReverseMapper($layout->compile()))->fromPath('app/Support/Kit/Thing.php')->reason)->toContain('excluded');
})->with([true, false]);

it('keeps narrower inherited exclusions inside a mounted root', function () {
    $registry = new LayoutRegistry;
    $registry->layout('parent')->path('app')->mounts('app', 'App\\', 'app')->excludes('App\\Support\\Kit\\Private\\');
    $compiled = $registry->layout('child')->extends('parent')
        ->mounts('kit', 'App\\Support\\Kit\\', 'app/Support/Kit', fn (Root $r) => $r->generates('kit', in: '', nested: true))->compile();
    expect((new ReverseMapper($compiled))->fromClass('App\\Support\\Kit\\Private\\Thing')->reason)->toContain('excluded');
});

it('fingerprints carve outs even when roots and types are otherwise identical', function () {
    $registry = new LayoutRegistry;
    $registry->layout('parent')->path('app')->mounts('app', 'App\\', 'app')->excludes('App\\Support\\');
    $layout = $registry->layout('child')->extends('parent')->mounts('kit', 'App\\Support\\Kit\\', 'app/Support/Kit');
    $claimed = $layout->compile();
    $layout->excludes('App\\Support\\');
    expect(PresetFingerprint::of($layout->compile()))->not->toBe(PresetFingerprint::of($claimed));
});

it('keeps several carve outs and lets a child exclude a private subtree', function () {
    $registry = new LayoutRegistry;
    $registry->layout('parent')->path('app')->mounts('app', 'App\\', 'app')->excludes('App\\Support\\');
    $layout = $registry->layout('child')->extends('parent')
        ->mounts('kit', 'App\\Support\\Kit\\', 'app/Support/Kit', fn (Root $r) => $r->generates('kit', in: '', nested: true))
        ->mounts('tools', 'App\\Support\\Tools\\', 'app/Support/Tools', fn (Root $r) => $r->generates('tool', in: ''))
        ->excludes('App\\Support\\Kit\\Private\\');
    $mapper = new ReverseMapper($layout->compile());
    expect($mapper->fromPath('app/Support/Kit/Thing.php')->isMatched())->toBeTrue()
        ->and($mapper->fromPath('app/Support/Tools/Thing.php')->isMatched())->toBeTrue()
        ->and($mapper->fromPath('app/Support/Kit/Private/Thing.php')->reason)->toContain('excluded')
        ->and($mapper->fromPath('app/Support/Other/Thing.php')->reason)->toContain('excluded');
});
