<?php

use Tey\Mod\Scaffolds\Literals;

it('writes PHP short array literals on one line', function (mixed $value, string $expected) {
    expect(Literals::php($value))->toBe($expected);
})->with([
    'strings' => [['name', 'company'], "['name', 'company']"],
    'empty' => [[], '[]'],
    'quotes and backslashes' => [["it's", 'a\\b'], "['it\\'s', 'a\\\\b']"],
    'records' => [[['key' => 'name', 'sortable' => true, 'width' => 3]], "[['key' => 'name', 'sortable' => true, 'width' => 3]]"],
    'a dollar sign stays literal' => [['$x', '{$y}'], "['\$x', '{\$y}']"],
]);

it('writes script literals with the preferred quote unless the text holds more of it', function (mixed $value, bool $single, string $expected) {
    expect(Literals::script($value, $single))->toBe($expected);
})->with([
    'single' => [['name', 'company'], true, "['name', 'company']"],
    'double' => [['name', 'company'], false, '["name", "company"]'],
    'empty list' => [[], true, '[]'],
    'an apostrophe, single preferred' => [["it's"], true, '["it\'s"]'],
    'a double quote, double preferred' => [['say "hi"'], false, "['say \"hi\"']"],
    'equal counts keep the preferred quote' => [['\'"'], true, "['\\'\"']"],
    'escapes' => [["a\nb\\c"], true, "['a\\nb\\\\c']"],
    'records' => [[['key' => 'name', 'label' => 'Name', 'sortable' => true]], true, "[{ key: 'name', label: 'Name', sortable: true }]"],
    'record strings follow the quote option' => [[['key' => 'a']], false, '[{ key: "a" }]'],
]);
