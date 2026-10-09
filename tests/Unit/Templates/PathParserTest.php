<?php

use Tey\Mod\Layout\LayoutRegistry;
use Tey\Mod\Templates\InvalidTemplate;
use Tey\Mod\Templates\PathParser;

it('parses template paths literally through the active layout', function (string $layout, string $path, string $root, string $in, array $slots) {
    $parsed = (new PathParser)->parse($path, (new LayoutRegistry)->layout($layout)->mounts('infrastructure', 'Infrastructure\\', 'src/Infrastructure'));
    expect($parsed->root)->toBe($root)->and($parsed->in)->toBe($in)->and($parsed->slots)->toBe($slots);
})->with([
    ['modules', '@module/Tools/tool.stub', 'app', 'Modules/{module}/Tools', []],
    ['modules', '@group/Tools/tool.stub', 'app', 'Modules/{module}/Tools', []],
    ['modules', '@domain/Tools/tool.stub', 'app', 'Modules/{module}/Tools', []],
    ['modules', '@feature/Tools/tool.stub', 'app', 'Modules/{module}/Tools', []],
    ['modules', '@slice/Tools/tool.stub', 'app', 'Modules/{module}/Tools', []],
    ['features', '@feature/Tools/tool.stub', 'app', 'Features/{feature}/Tools', []],
    ['ddd', '@domain/Tools/tool.stub', 'domain', '{domain+}/Tools', []],
    ['ddd', 'Modules/@domain/Presenters/presenter.stub', 'application', '{domain+}/Presenters', []],
    ['slices', '@feature/Tools/tool.stub', 'app', '{feature}/Tools', []],
    ['slices', '@slice/presenter.stub', 'app', '{feature}/{slice}', []],
    ['type-first', '@feature/Tools/tool.stub', 'app', 'Tools/{feature?}', []],
    ['laravel', 'Support/Tools/tool.stub', 'app', 'Support/Tools', []],
    ['modules', 'Support/Tools/tool.stub', 'app', 'Support/Tools', []],
    ['ddd', 'src/Infrastructure/Tools/tool.stub', 'infrastructure', 'Tools', []],
    ['modules', 'tests/Tools/tool.stub', 'tests', 'Tools', []],
    ['modules', '@module/Webhooks/[source]/webhook.stub', 'app', 'Modules/{module}/Webhooks/{source}', ['source']],
    ['modules', '[source]/@module/Webhooks/webhook.stub', 'app', '{source}/Modules/{module}/Webhooks', ['source']],
    ['modules', '@module/[ab]/a/tool.stub', 'app', 'Modules/{module}/{ab}/a', ['ab']],
    ['modules', '@module/a/[source]/tool.stub', 'app', 'Modules/{module}/a/{source}', ['source']],
    ['modules', '@module/Tools/ShowDocumentPage.stub', 'app', 'Modules/{module}/Tools', []],
    ['modules', '@module/Tools/show_document_page.stub', 'app', 'Modules/{module}/Tools', []],
    ['modules', '@module/Tools/show-document-page.stub', 'app', 'Modules/{module}/Tools', []],
    ['modules', '@module\\Tools\\tool.stub', 'app', 'Modules/{module}/Tools', []],
]);

it('rejects broken paths with a fix', function (string $path, string $fix) {
    expect(fn () => (new PathParser)->parse($path, (new LayoutRegistry)->layout('modules')))
        ->toThrow(InvalidTemplate::class, $fix);
})->with([
    ['app/Tools/tool.stub', 'Drop app/'],
    ['{module}/Tools/tool.stub', 'template folders use @module'],
    ['[module]/Tools/tool.stub', 'template folders use @module'],
    ['@modul/Tools/tool.stub', '@module'],
    ['@module/@domain/tool.stub', 'one anchor'],
    ['Other/@module/tool.stub', 'does not keep'],
    ['@module/[...source]/tool.stub', 'single folder'],
    ['@module/[force]/tool.stub', '--force'],
    ['@module/[in]/tool.stub', '--in'],
    ['@module/[help]/tool.stub', '--help'],
    ['@module/[quiet]/tool.stub', '--quiet'],
    ['@module/[verbose]/tool.stub', '--verbose'],
    ['@module/[version]/tool.stub', '--version'],
    ['@module/[ansi]/tool.stub', '--ansi'],
    ['@module/[no-ansi]/tool.stub', '--no-ansi'],
    ['@module/[no-interaction]/tool.stub', '--no-interaction'],
    ['@module/[env]/tool.stub', '--env'],
    ['../Tools/tool.stub', 'outside'],
    ['@group/../Tools/tool.stub', 'outside'],
    ['@group\\..\\Tools\\tool.stub', 'outside'],
    ['/Tools/tool.stub', 'relative'],
    ['@module/[source]/[source]/tool.stub', 'once'],
]);
