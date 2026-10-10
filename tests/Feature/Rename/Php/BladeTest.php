<?php

use Tey\Mod\Facades\Mod;
use Tey\Mod\Rename\Contribution;
use Tey\Mod\Rename\Contributors;
use Tey\Mod\Rename\Edit;
use Tey\Mod\Rename\EditComposer;
use Tey\Mod\Rename\Planner;
use Tey\Mod\Rename\Request;
use Tey\Mod\Scaffolds\Scaffold;
use Tey\Mod\Tests\Feature\Acceptance\Examples\Rename\Support\RenameScenario as S;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;

it('owns PHP regions in Blade and composes with separate directive bytes using original UTF8 CRLF offsets', function () {
    Workspace::run(null, function (Workspace $w) {
        S::setup($w);
        $path = 'resources/views/consumer.blade.php';
        $before = "é 😀\r\n@php\r\nuse App\\Modules\\Inventory\\Models\\Widget as Stock;\r\n\$x = new Stock;\r\n@endphp\r\n{{ \\App\\Modules\\Inventory\\Models\\Widget::class }}\r\n@include('inventory::widgets.show')\r\n{{-- {{ \\App\\Modules\\Inventory\\Models\\Widget::class }} --}}\r\n";
        $w->write($path, $before);
        S::commit($w);
        $result = app(Planner::class)->build(new Request('Inventory:Widget', 'Inventory:Gadget', 'model-only'));
        expect($result->plan->warnings)->toBe([]);
        $expected = str_replace(['Models\\Widget as', "{{ \\App\\Modules\\Inventory\\Models\\Widget::class }}\r\n@include"], ['Models\\Gadget as', "{{ \\App\\Modules\\Inventory\\Models\\Gadget::class }}\r\n@include"], $before);
        expect($result->bodies[$path])->toBe($expected);
        $php = app(Contributors::class)->collect($result->inputs);
        $identity = 'inventory::widgets.show';
        $directive = new Contribution([new Edit($path, strpos($before, $identity), $identity, 'inventory::gadgets.show', 'blade-identity', 7)]);
        $result->plan->rename['rewrites'] = [];
        $result->plan->rename['checklist'] = [];
        $composed = (new EditComposer)->compose($result->plan, $result->inputs, [...$php, $directive]);
        expect($composed->plan->wouldWrite)->toBeTrue();
        expect($composed->bodies[$path])->toBe(str_replace($identity, 'inventory::gadgets.show', $expected));
        $overlap = new Contribution([new Edit($path, strpos($before, 'Widget as'), 'Widget', 'Gadget', 'duplicate', 3)]);
        expect((new EditComposer)->compose($result->plan, $result->inputs, [...$php, $overlap])->plan->wouldWrite)->toBeFalse();
    });
});

it('rewrites PHP symbols inside Blade conditions and loops but leaves directive identities for frontend', function () {
    Workspace::run(null, function (Workspace $w) {
        S::setup($w);
        $path = 'resources/views/conditions.blade.php';
        $source = "@if(\\App\\Modules\\Inventory\\Models\\Widget::available())\n@foreach(\\App\\Modules\\Inventory\\Models\\Widget::all() as \$item)\n{{ \$item }}\n@endforeach\n@endif\n@include('inventory::widgets.show')";
        $w->write($path, $source);
        S::commit($w);
        $result = app(Planner::class)->build(new Request('Inventory:Widget', 'Inventory:Gadget', 'model-only'));
        expect($result->plan->warnings)->toBe([]);
        expect($result->bodies[$path])->toBe(str_replace('Models\\Widget::', 'Models\\Gadget::', $source));
    });
});

it('leaves escaped Blade echoes and directives byte exact', function () {
    Workspace::run(null, function (Workspace $w) {
        S::setup($w);
        $path = 'resources/views/escaped.blade.php';
        $source = '@{{ \App\Modules\Inventory\Models\Widget::class }} @@php $x = new \App\Modules\Inventory\Models\Widget; @@endphp';
        $w->write($path, $source);
        S::commit($w);
        $result = app(Planner::class)->build(new Request('Inventory:Widget', 'Inventory:Gadget', 'model-only'));
        expect($result->plan->warnings)->toBe([]);
        expect($result->bodies[$path] ?? $source)->toBe($source);
    });
});

it('owns PHP calls and symbols nested in directive arguments while leaving direct identity literals to frontend', function () {
    Workspace::run(null, function (Workspace $w) {
        S::setup($w);
        $w->write('stubs/mod/@module/resources/views/show-view.blade.php.stub', 'Manual');
        Mod::scaffold('view-cluster', fn (Scaffold $s) => $s->makes('model')->makes('show-view', '{name.kebab}s/show'));
        $w->write('app/Modules/Inventory/resources/views/widgets/show.blade.php', 'Manual');
        $path = 'resources/views/directives.blade.php';
        $source = <<<'SOURCE'
@php use App\Modules\Inventory\Models\Widget; @endphp
@include('inventory::widgets.show')
@include(view('inventory::widgets.show'))
@includeIf('inventory::widgets.show', ['kind' => Widget::class])
SOURCE;
        $w->write($path, $source);
        S::commit($w);
        $result = app(Planner::class)->build(new Request('Inventory:Widget', 'Inventory:Gadget', 'view-cluster'));
        expect($result->plan->warnings)->toBe([]);
        expect($result->bodies[$path])->toBe(str_replace(['Models\\Widget;', "'inventory::widgets.show'", 'Widget::class'], ['Models\\Gadget;', "'inventory::gadgets.show'", 'Gadget::class'], $source));
        $rows = array_values(array_filter($result->plan->rename['rewrites'], fn (array $row) => $row['file'] === $path));
        $frontend = array_values(array_filter($rows, fn (array $row) => $row['category'] === 'blade-identity'));
        $phpCalls = array_values(array_filter($rows, fn (array $row) => $row['category'] === 'php-identity'));
        expect(array_column($frontend, 'line'))->toBe([2, 4])->and(array_column($phpCalls, 'line'))->toBe([3]);
        expect(array_filter($result->plan->rename['checklist'], fn (array $row) => $row['file'] === $path && in_array($row['line'], [2, 4], true)))->toBe([]);
    });
});

it('preserves original CRLF lines when whitespace separates a Blade directive and its arguments', function () {
    Workspace::run(null, function (Workspace $w) {
        S::setup($w);
        $path = 'resources/views/multiline.blade.php';
        $source = "é\r\n@if\r\n(\\App\\Modules\\Inventory\\Models\\Widget::available())\r\n@endif\r\n";
        $w->write($path, $source);
        S::commit($w);
        $result = app(Planner::class)->build(new Request('Inventory:Widget', 'Inventory:Gadget', 'model-only'));
        expect($result->plan->warnings)->toBe([]);
        expect($result->bodies[$path])->toBe(str_replace('Models\\Widget::', 'Models\\Gadget::', $source));
        $rows = array_values(array_filter($result->plan->rename['rewrites'], fn (array $row) => $row['file'] === $path));
        expect($rows[0]['line'])->toBe(3);
    });
});
