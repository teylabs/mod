<?php

use Tey\Mod\Artifact\ArtifactKind;
use Tey\Mod\Artifact\ClassIdentity;
use Tey\Mod\Artifact\ResolvedArtifact;
use Tey\Mod\Exceptions\GenerationRefused;
use Tey\Mod\Placement\PlacementContext;
use Tey\Mod\Scaffolds\AnchorWriter;
use Tey\Mod\Scaffolds\Placeholders;
use Tey\Mod\Scaffolds\Scaffold;
use Tey\Mod\Scaffolds\ScaffoldExecution;

it('keeps CRLF, stub indentation, anchor indentation and trailing whitespace', function (string $comment) {
    $source = "<?php\r\n    $comment  \r\n}\r\n";
    $result = (new AnchorWriter)->insert($source, 'tabs', "        entry\n", 'base.php');
    expect($result)->toBe("<?php\r\n        entry\r\n    $comment  \r\n}\r\n");
})->with(['// mod:tabs', '# mod:tabs', '{{-- mod:tabs --}}', '<!-- mod:tabs -->']);

it('refuses absent or ambiguous literal anchors and names both lines', function () {
    $writer = new AnchorWriter;
    expect(fn () => $writer->insert("// mod:tabsOther\n", 'tabs', 'entry', 'base.php'))->toThrow(GenerationRefused::class)
        ->and(fn () => $writer->insert("// mod:tabs\n# mod:tabs\n", 'tabs', 'entry', 'base.php'))->toThrow(GenerationRefused::class, 'lines 1 and 2');
});

it('renders chained forms, literal braces, typed names and PHP list literals', function () {
    $p = new Placeholders(['name' => 'Widget', 'model' => 'App\\Models\\Widget', 'tabs' => ['Overview', "Owner's notes"], 'enabled' => false]);
    expect($p->render('{{ name }} {{ name.plural.kebab }} {{{ model.camel }}} {{ model.fqcn }} {{ enabled }}'))->toBe('Widget widgets {widget} App\\Models\\Widget false');
    $array = $p->render('{{ tabs.array }}');
    expect(eval('return '.$array.';'))->toBe(['Overview', "Owner's notes"]);
});

it('copies questions, parts and repetitions and replaces an inherited part', function () {
    $root = (new Scaffold)->asks('tabs', type: 'list', default: ['A'])->part('tab', uses: 'leaf')->each('tabs', part: 'tab');
    $copy = (new Scaffold(fn () => $root))->include('root')->part('tab', uses: 'other');
    expect(array_keys($copy->questions()))->toBe(['tabs'])->and($copy->parts()['tab']->scaffold())->toBe('other')
        ->and($copy->repetitions())->toBe(['tabs' => 'tab'])->and($root->parts()['tab']->scaffold())->toBe('leaf');
});

it('compares kept paths with forward slashes on both sides', function (string $kept, string $target) {
    $scope = new ScaffoldExecution;
    $scope->keep = [$kept];
    $artifact = new ResolvedArtifact(
        ArtifactKind::phpClass('model'),
        PlacementContext::none(),
        'Widget',
        new ClassIdentity('App\\Models', 'Widget', $target),
    );
    expect($scope->keeps($artifact))->toBeTrue();
})->with([
    ['app/Models/Widget.php', 'app\\Models\\Widget.php'],
    ['app\\Models\\Widget.php', 'app/Models/Widget.php'],
]);
