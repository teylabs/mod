<?php

use Tey\Mod\Rename\Frontend\Blade;
use Tey\Mod\Rename\InputFile;

it('parses nested Blade arguments and paired component tags while preserving PHP and escaped regions', function (string $eol) {
    $source = implode($eol, ["@includeWhen(check(['a', 'b']), 'inventory::widgets.show')", "@extends('inventory::widgets.show')", "@component('inventory::widgets.show', ['x' => 1])", '<x-inventory::widget-card /><x-inventory::widget-card>Body</x-inventory::widget-card>', "@include('inventory::widgets.show' . suffix())", "@@include('inventory::widgets.show')", "@verbatim @include('inventory::widgets.show') @endverbatim", "@php echo 'inventory::widgets.show'; @endphp", "<?php echo 'inventory::widgets.show'; ?>", "{{ 'inventory::widgets.show' }}", "{{-- @include('inventory::widgets.show') --}}"]);
    $file = new InputFile('resources/views/a.blade.php', $source, 0644);
    $result = Blade::contribute($file, ['inventory::widgets.show' => 'inventory::gadgets.show'], ['x-inventory::widget-card' => 'x-inventory::gadget-card']);
    expect($result->edits)->toHaveCount(6);
    foreach ($result->edits as $edit) {
        expect(substr($source, $edit->offset, strlen($edit->before)))->toBe($edit->before)->and($edit->line)->toBeLessThan(5);
    }
})->with(["\n", "\r\n"]);

it('returns no speculative Blade edits for unclosed directives', function () {
    $file = new InputFile('resources/views/a.blade.php', "@include('old')\n@include('old'", 0644);
    expect(Blade::contribute($file, ['old' => 'new'], [])->edits)->toBe([]);
});

it('keeps component-looking user copy inside attributes and raw script bodies unchanged', function () {
    $source = '<div title="<x-old-card>"><x-old-card /></div><script>const text = "<x-old-card>";</script><style>.a:after{content:"<x-old-card>"}</style>';
    $file = new InputFile('resources/views/a.blade.php', $source, 0644);
    $result = Blade::contribute($file, [], ['x-old-card' => 'x-new-card']);
    expect($result->edits)->toHaveCount(1)->and($result->edits[0]->offset)->toBe(strpos($source, '<x-old-card />') + 1);
});

it('uses PHP lexical boundaries for comments inside Blade arguments', function () {
    $source = "@include('old' /* ) */ . suffix())\n@include(/* ) */ 'old')";
    $file = new InputFile('resources/views/a.blade.php', $source, 0644);
    $result = Blade::contribute($file, ['old' => 'new'], []);
    expect($result->edits)->toHaveCount(1)->and($result->edits[0]->line)->toBe(2);
});
