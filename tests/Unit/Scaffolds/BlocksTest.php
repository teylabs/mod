<?php

use Tey\Mod\Exceptions\GenerationRefused;
use Tey\Mod\Scaffolds\Placeholders;

/** @return list<array<string, mixed>> */
function columns(): array
{
    return [
        ['key' => 'name', 'label' => 'Name', 'sortable' => true],
        ['key' => 'company_name', 'label' => 'Company', 'sortable' => false],
    ];
}

it('repeats a block per record with item fields, transforms and outer placeholders in scope', function () {
    $stub = "<table name=\"{{ name.kebab }}\">\n    <tr>\n        {{ #columns }}\n        <th data-key=\"{{ key.studly }}\" data-page=\"{{ name }}\">{{ label }}</th>\n        {{ /columns }}\n    </tr>\n</table>\n";

    expect((new Placeholders(['name' => 'UserList', 'columns' => columns()]))->render($stub))->toBe(
        "<table name=\"user-list\">\n    <tr>\n        <th data-key=\"Name\" data-page=\"UserList\">Name</th>\n        <th data-key=\"CompanyName\" data-page=\"UserList\">Company</th>\n    </tr>\n</table>\n"
    );
});

it('leaves nothing behind for tag lines, and nothing at all for an empty list', function () {
    $stub = "before\n{{ #columns }}\n- {{ key }}\n{{ /columns }}\nafter\n";

    expect((new Placeholders(['columns' => []]))->render($stub))->toBe("before\nafter\n")
        ->and((new Placeholders(['columns' => [['key' => 'a'], ['key' => 'b']]]))->render($stub))->toBe("before\n- a\n- b\nafter\n");
});

it('gives block lines the indentation of the opening tag whatever the template used', function (string $stub) {
    expect((new Placeholders(['columns' => columns()]))->render($stub))->toBe("<tr>\n    <th>Name</th>\n      <td>x</td>\n    <th>Company</th>\n      <td>x</td>\n</tr>\n");
})->with([
    'body flush with the tag' => ["<tr>\n    {{ #columns }}\n    <th>{{ label }}</th>\n      <td>x</td>\n    {{ /columns }}\n</tr>\n"],
    'body indented inside the tag' => ["<tr>\n    {{ #columns }}\n            <th>{{ label }}</th>\n              <td>x</td>\n    {{ /columns }}\n</tr>\n"],
    'body flush left' => ["<tr>\n    {{ #columns }}\n<th>{{ label }}</th>\n  <td>x</td>\n    {{ /columns }}\n</tr>\n"],
]);

it('keeps relative indentation and drops whitespace on blank lines', function () {
    $stub = "class A\n{\n    {{ #columns }}\n    public function {{ key.camel }}()\n    {\n        return '{{ label }}';\n    }\n  \n    {{ /columns }}\n}\n";

    expect((new Placeholders(['columns' => [['key' => 'a_b', 'label' => 'A'], ['key' => 'c', 'label' => 'C']]]))->render($stub))->toBe(
        "class A\n{\n    public function aB()\n    {\n        return 'A';\n    }\n\n    public function c()\n    {\n        return 'C';\n    }\n\n}\n"
    );
});

it('switches a block on a boolean and an inverted block on its opposite', function (bool $on) {
    $stub = "return [\n    'q' => 1,\n    {{ #filter }}\n    'status' => 2,\n    {{ /filter }}\n    {{ ^filter }}\n    'plain' => 3,\n    {{ /filter }}\n];\n";

    expect((new Placeholders(['filter' => $on]))->render($stub))->toBe($on ? "return [\n    'q' => 1,\n    'status' => 2,\n];\n" : "return [\n    'q' => 1,\n    'plain' => 3,\n];\n");
})->with([true, false]);

it('renders an inverted block for an empty list only', function () {
    $stub = "{{ ^columns }}\nnone\n{{ /columns }}\n";

    expect((new Placeholders(['columns' => []]))->render($stub))->toBe("none\n")
        ->and((new Placeholders(['columns' => columns()]))->render($stub))->toBe('');
});

it('nests blocks inside blocks, with record booleans gating sections', function () {
    $stub = "<tr>\n    {{ #columns }}\n    <th>\n        {{ label }}\n        {{ #sortable }}\n        <i>{{ key }}</i>\n        {{ /sortable }}\n        {{ ^sortable }}\n        <b />\n        {{ /sortable }}\n    </th>\n    {{ /columns }}\n</tr>\n";

    expect((new Placeholders(['columns' => columns()]))->render($stub))->toBe(
        "<tr>\n    <th>\n        Name\n        <i>name</i>\n    </th>\n    <th>\n        Company\n        <b />\n    </th>\n</tr>\n"
    );
});

it('reaches outer values and nested lists from inside a block', function () {
    $stub = "{{ #groups }}\n[{{ title }}]\n    {{ #items }}\n    {{ title }}/{{ id }}/{{ suffix }}\n    {{ /items }}\n{{ /groups }}\n";
    $groups = [
        ['title' => 'A', 'items' => [['id' => '1'], ['id' => '2']]],
        ['title' => 'B', 'items' => []],
    ];

    expect((new Placeholders(['suffix' => 'x', 'groups' => $groups]))->render($stub))->toBe("[A]\n    A/1/x\n    A/2/x\n[B]\n");
});

it('renders inline blocks verbatim between other text', function () {
    $stub = "<ul>{{ #columns }}<li>{{ key }}</li>{{ /columns }}</ul>{{ #on }}!{{ /on }}\n";

    expect((new Placeholders(['columns' => columns(), 'on' => true]))->render($stub))->toBe("<ul><li>name</li><li>company_name</li></ul>!\n")
        ->and((new Placeholders(['columns' => [], 'on' => false]))->render($stub))->toBe("<ul></ul>\n");
});

it('is byte-identical every time, and keeps Windows line endings', function () {
    $stub = "a\r\n    {{ #columns }}\r\n    {{ key }}\r\n    {{ /columns }}\r\nb\r\n";
    $placeholders = new Placeholders(['columns' => columns()]);

    expect($placeholders->render($stub))->toBe("a\r\n    name\r\n    company_name\r\nb\r\n")
        ->and($placeholders->render($stub))->toBe($placeholders->render($stub));
});

it('handles a closing tag on the last line without a trailing newline', function () {
    expect((new Placeholders(['on' => true]))->render("{{ #on }}\nyes\n{{ /on }}"))->toBe("yes\n");
});

it('leaves text without blocks exactly as it was', function () {
    $stub = "{{ name }} {{ missing }} @{{ name }}\n  {{ name.snake }}\n";

    expect((new Placeholders(['name' => 'Ab']))->render($stub))->toBe("Ab {{ missing }} @Ab\n  ab\n");
});

it('refuses malformed or misused blocks with a message', function (string $stub, array $values, string $message) {
    expect(fn () => (new Placeholders($values))->render($stub))->toThrow(GenerationRefused::class, $message);
})->with([
    'unclosed' => ["{{ #a }}\nx\n", ['a' => true], 'never closed'],
    'unopened' => ["x\n{{ /a }}\n", ['a' => true], 'never opened'],
    'crossed' => ["{{ #a }}\n{{ #b }}\n{{ /a }}\n{{ /b }}\n", ['a' => true, 'b' => true], 'closed by'],
    'half on its line' => ["{{ #a }}\nx {{ /a }}\n", ['a' => true], 'one tag alone on its line'],
    'unknown name' => ["{{ #nope }}\nx\n{{ /nope }}\n", [], 'names no question, member or record field'],
    'text' => ["{{ #a }}\nx\n{{ /a }}\n", ['a' => 'yes'], "is text, so it can't be repeated"],
    'a plain list' => ["{{ #a }}\nx\n{{ /a }}\n", ['a' => ['x', 'y']], 'repeats records'],
]);
